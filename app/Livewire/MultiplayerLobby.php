<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Events\RaceProgressUpdated;
use App\Events\RoomInvitationSent;
use App\Events\RoomMessageSent;
use App\Events\RoomPresenceChanged;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Livewire\Concerns\FinalizesRace;
use App\Livewire\Concerns\ManagesRoomMembership;
use App\Livewire\Concerns\ReadsRoomState;
use App\Models\Friendship;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\AntiCheatService;
use App\Services\RoomMembershipService;
use App\Services\TextGeneratorService;
use App\Support\SafeBroadcast;
use App\Support\TypingLanguage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The multiplayer race lobby and arena: create/join, ready up, run the synced
 * countdown, then race. Persists a server-recomputed Net WPM (never the client's),
 * broadcasts room/race state, and finalizes placements and XP at the end.
 *
 * The read-model and race finalization live in their own traits. Room lifecycle
 * and race lifecycle deliberately stay here: they are interwoven, so splitting them
 * would only produce traits that use each other without adding clarity.
 */
class MultiplayerLobby extends Component
{
    use FinalizesRace, ManagesRoomMembership, ReadsRoomState;

    public string $step = 'choose';

    public string $roomCode = '';

    public array $joinCodeInput = ['', '', '', '', '', ''];

    public bool $showResultModal = false;

    public bool $hasGivenUp = false;

    public bool $hasFinished = false;

    public array $resultSnapshot = [];

    /**
     * A room change waiting on the player's answer: ['from' => current code, 'to' => target
     * code or null for "create a new room"]. Null when nothing is pending.
     *
     * Deliberately a Livewire PROPERTY rather than a dispatched browser event, because the
     * case that matters most arrives on a FRESH PAGE LOAD: accepting an invite navigates to
     * /multiplayer?invite=CODE, so the question has to exist from the first render. An event
     * fired during mount() would have nothing listening yet.
     */
    public ?array $pendingRoomSwitch = null;

    // Sudden death duration (seconds). Single constant shared by checkSuddenDeath()
    // and getSuddenDeathRemainingProperty() so they never drift apart.
    private const SUDDEN_DEATH_SECONDS = 15;

    // Initial race countdown ("3, 2, 1, GO!"). Server sets race_starts_at = now() + this
    // so all clients stay in sync.
    private const COUNTDOWN_SECONDS = 3;

    // Separate capacities: racers and spectators are each capped at 5. Single constants
    // so join/toggle/guard don't scatter magic numbers.
    public const MAX_PLAYERS = 5;

    public const MAX_SPECTATORS = 5;

    /**
     * Racers required before a race may start. Spectators never count toward it, no matter
     * how many are watching -- a race is between competitors, and one racer with an audience
     * is not a race. Named rather than inlined because the rule is enforced in two places
     * that must not drift: the server guard in startRace() and the button state via
     * ReadsRoomState::getAllReadyProperty().
     */
    public const MIN_PLAYERS_TO_START = 2;

    /**
     * Max race-progress updates accepted per second per player. The honest client emits
     * ~8/sec (120ms throttle); 20 leaves room for bursts + the trailing flush while still
     * capping a scripted flood (each accepted update also broadcasts to the whole room).
     */
    private const MAX_PROGRESS_UPDATES_PER_SECOND = 20;

    /**
     * Seconds after GO before a racer who has typed NOTHING is dropped as DNF.
     *
     * Until this existed, sudden death was the only thing that could ever end a race -- and
     * it only starts once someone finishes with a valid result. If nobody ever finished, the
     * room sat in 'racing' forever. sweepAbandonedRaces() was no help: it requires EVERY
     * member to be offline, and a player idling in the arena keeps sending the presence
     * heartbeat, so they stay "online" indefinitely. Nothing anywhere put a clock on
     * starting, which is exactly why a player had no reason to begin typing.
     *
     * "Has not started" is read as progress_percent === 0, and the race text is what makes
     * that safe: RACE_WORD_COUNT is 45 words (~250-290 characters) and the client floors the
     * percentage, so 0% means FEWER THAN ~3 characters typed. Twenty seconds in, even a
     * 5-WPM beginner has typed ~8 characters (~3%) and is never touched. The margin is in the
     * text length -- shorten the race text or raise this value and the guarantee weakens, so
     * RaceDeadlineTest pins the slow-but-started case.
     *
     * Public so the view can render the same number it is judged by.
     *
     * Like every other threshold in this project, this is a first guess to be re-tuned from
     * real play data -- not intuition. See the history of MAX_CHARS_PER_SECOND and
     * IMPOSSIBLE_CONSISTENCY in docs/features/anti-cheat-wpm.md for why that matters.
     */
    public const START_GRACE_SECONDS = 20;

    /**
     * Hard ceiling on a race: at this point it closes, whoever is still typing.
     *
     * The start deadline above only catches players who never BEGAN. Someone who types a few
     * words and then walks away clears it, and without this second bound their race hangs
     * just as badly. A finisher would trigger sudden death, but there may not be one.
     *
     * 180s is generous by design: the slowest plausible run of a 45-word text (~15 WPM) lands
     * near 100 seconds, so this only ever fires on a race nobody is really running.
     */
    public const MAX_RACE_SECONDS = 180;

    /**
     * Restore the caller straight into their room on load (no re-entering the code).
     *
     * The component has no persisted client state across a full page load (the nav is a
     * plain anchor, not wire:navigate), but the room_members row survives in the DB. So on
     * mount we look up the user's current membership and hydrate $roomCode/$step from it.
     * A not-ready player who truly left elsewhere had their row removed by the leave beacon
     * (see MultiplayerPresenceController), so no stale row is restored; a ready/host member
     * (whom the beacon skips) is brought right back.
     */
    public function mount(?string $invite = null): void
    {
        // Reap members who left without pressing Leave (tab closed / lost connection):
        // their presence heartbeat has gone stale, so they no longer hold a slot and a
        // room they abandoned as host gets a new host (or is deleted if now empty). Lazy,
        // on every lobby load -- no scheduler, mirroring ClanWarResolver. Skip the caller:
        // they are provably here, and their own heartbeat may not have landed yet.
        app(RoomMembershipService::class)->sweepOfflineMembers(exceptUserId: Auth::id());

        // Arrived from a room-invite link (/multiplayer?invite=CODE): auto-join that room.
        // Read from the request query string as the source of truth (with the injected arg
        // as a fallback for the test harness) since Livewire injects route params, not query
        // params, into mount() by name. On success joinRoomByCode already set step/roomCode
        // and subscribed, so we return -- avoiding a redundant second restore + subscribe. A
        // bad/expired code just flashes an error and falls through to the choose screen.
        $inviteCode = request()->query('invite', $invite);
        $inviteCode = is_string($inviteCode) && strlen($inviteCode) === 6 ? strtoupper($inviteCode) : null;

        $member = RoomMember::where('user_id', Auth::id())->first();

        // Only auto-join straight from the link when there is no room to lose. When there IS
        // one, the join runs anyway but stops at the switch guard below, which records the
        // question instead of performing the move -- so the player lands back in their own
        // room with a confirmation rather than finding themselves silently relocated.
        if ($inviteCode !== null && ! $member && $this->joinRoomByCode($inviteCode)) {
            return;
        }

        if (! $member) {
            return; // No membership -> stay on the create/join "choose" screen.
        }

        $room = Room::find($member->room_id);

        if (! $room) {
            return; // Orphan row (room already gone) -> choose screen; nothing to restore.
        }

        $this->roomCode = $room->code;

        // 'finished' shows the result panel; 'waiting'/'racing' show lobby/arena.
        $this->step = $room->status === 'finished' ? 'racing' : $room->status;

        // Lazy backstop for the race deadlines, mirroring the stale-member sweep above: a race
        // whose clock ran out while every tab was closed is settled by whoever opens the lobby
        // next, so nobody comes back to a room still pretending to race. Set roomCode/step
        // first -- the resolver captures the result snapshot, which reads them.
        //
        // Ceiling only, no start-grace: this is a PAGE LOAD, and the player has not had a
        // chance to type yet on it. The grace rule belongs to the live arena that actually
        // watched them sit idle (checkRaceDeadline), not to the moment their page arrives.
        if ($room->status === 'racing'
            && $this->resolveRaceDeadlinesIfElapsed($room, includeStartGrace: false)) {
            $room->refresh();
        }

        if ($room->status === 'finished') {
            $this->showResultModal = true;
            $this->captureResultSnapshot();
        } elseif ($room->status === 'racing') {
            // Derive finish state from the DB so a mid-race refresh doesn't hand a finished
            // player their typing input back. (hasFinished/hasGivenUp default to false.)
            $this->hasGivenUp = $member->isDnf();
            $this->hasFinished = ! is_null($member->finished_time_seconds) && ! $member->isDnf();
        }

        $this->forgetRoomCache();

        // Re-subscribe to the room/race Echo channels after the reload.
        $this->dispatch('subscribe-room', room: $room->code);

        // Restored first, asked second: the player is back in the room they actually belong
        // to, and only then does the invite raise its question on top of it. Cancelling
        // therefore needs no undo -- nothing has moved.
        if ($inviteCode !== null && $inviteCode !== $room->code) {
            $this->joinRoomByCode($inviteCode);
        }
    }

    /**
     * Change the room's content language, HOST-ONLY, from inside the waiting room.
     *
     * Everyone races the same text, so language is a property of the room, not the player.
     * Changing it regenerates the text (a fresh race in the new language) and broadcasts
     * RoomUpdated so every client re-renders the new text and language badge. Only while
     * 'waiting': swapping text mid-race would desync everyone. A non-host call is ignored
     * server-side even though the UI already hides the control from non-hosts.
     */
    public function setRaceLang(string $lang): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room || $room->host_id !== Auth::id() || $room->status !== 'waiting') {
            return;
        }

        $lang = TypingLanguage::resolve($lang);

        if ($lang === $room->language) {
            return; // no change -> no regen, no broadcast
        }

        $room->update([
            'language' => $lang,
            'text_to_type' => $this->generateRaceText($lang),
        ]);

        $this->forgetRoomCache();

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
    }

    /**
     * Decide whether the caller may enter another room, given the one they are in now.
     *
     * The gate lives on the SERVER, not in the lobby's JS, because a player can be a member
     * of a room while looking at a completely different page -- the [data-mp-flags] element
     * the nav interceptor reads only exists on /multiplayer. The same reason the rest of this
     * component re-checks everything the UI already appears to enforce.
     *
     * $targetCode is null for "create a new room".
     *
     * @return bool true if the caller may proceed with the join/create
     */
    private function mayLeaveCurrentRoom(?string $targetCode, bool $confirmed): bool
    {
        $member = RoomMember::where('user_id', Auth::id())->first();

        if (! $member) {
            return true; // nothing to leave
        }

        $current = Room::find($member->room_id);

        if (! $current || $current->code === $targetCode) {
            return true; // nothing to protect, or they are already here
        }

        // Mid-race the answer is no, not "are you sure": leaving takes the player's own race
        // away AND deletes a competitor out of a race the others are still running. An invite
        // arriving at the wrong moment must not be able to do that at all.
        if ($current->status === 'racing') {
            session()->flash('error', __('multiplayer.error_leave_race_first'));

            return false;
        }

        if ($confirmed) {
            return true;
        }

        // 'finished' asks too. It is tempting to wave it through -- no race is lost -- but the
        // result screen is where people press Play Again together, and a rule that says "you
        // are in a room, leaving it is a decision" is only trustworthy if it holds in every
        // status. One consistent rule beats one exception that has to be explained.
        $this->pendingRoomSwitch = ['from' => $current->code, 'to' => $targetCode];

        return false;
    }

    /** Accept the pending room change and carry out the join/create it was holding. */
    public function confirmRoomSwitch(): void
    {
        $pending = $this->pendingRoomSwitch;
        $this->pendingRoomSwitch = null;

        if (! $pending) {
            return;
        }

        if ($pending['to'] === null) {
            $this->createRoom(confirmed: true);

            return;
        }

        $this->joinRoomByCode($pending['to'], confirmed: true);
    }

    /**
     * Decline the pending room change. Nothing to undo: the guard refuses BEFORE any
     * membership is touched, so the player never left in the first place.
     */
    public function cancelRoomSwitch(): void
    {
        $this->pendingRoomSwitch = null;
    }

    /** Create a fresh room, make the caller its host, and subscribe to its channel. */
    public function createRoom(bool $confirmed = false): void
    {
        // Creating a room silently abandons the current one exactly like joining does -- the
        // reported bug arrived through invites, but all three doors lead to the same
        // departCurrentRooms() call.
        if (! $this->mayLeaveCurrentRoom(null, $confirmed)) {
            return;
        }

        $user = Auth::user();
        $code = strtoupper(Str::random(6));

        // Seed the room language from the player's solo preference, so it feels continuous;
        // the host can change it inside the room afterwards.
        $language = TypingLanguage::resolve(session('typing_preferences')['contentLang'] ?? null);

        // One transaction: leave old rooms + join the new one. If split, there is a
        // window where the player belongs to no room (or to two).
        DB::transaction(function () use ($user, $code, $language) {
            $this->departCurrentRooms($user->id);

            $room = Room::create([
                'code' => $code,
                'host_id' => $user->id,
                'status' => 'waiting',
                'language' => $language,
                'text_to_type' => $this->generateRaceText($language),
            ]);

            RoomMember::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'role' => RoomMember::ROLE_PLAYER,
                'is_ready' => true,
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
            ]);
        });

        $this->roomCode = $code;
        $this->step = 'waiting';
        $this->forgetRoomCache();

        $this->dispatch('subscribe-room', room: $code);
    }

    /**
     * Text for one race in the given content language (en|id).
     *
     * This used to assemble its own text with indonesian.json HARDCODED, so multiplayer
     * never supported English. It now shares TextGeneratorService with solo. The caller
     * passes the room's language; playAgain reuses the room's stored language so a rematch
     * stays in the same language.
     */
    private function generateRaceText(string $language): string
    {
        return app(TextGeneratorService::class)->forRace(TypingLanguage::resolve($language));
    }

    /** Join an existing waiting room by code; overflow racers become spectators. */
    public function joinRoom(): void
    {
        $code = strtoupper(implode('', $this->joinCodeInput));

        if (strlen($code) !== 6) {
            session()->flash('error', __('multiplayer.error_code_length'));

            return;
        }

        $this->joinRoomByCode($code);
    }

    /**
     * Join an existing waiting room by an already-validated code. Shared by the manual
     * join-code form (joinRoom) and the invite deep link (mount's ?invite handler).
     * Returns true on success; on failure it flashes an error and returns false.
     */
    private function joinRoomByCode(string $code, bool $confirmed = false): bool
    {
        $room = Room::where('code', $code)->where('status', 'waiting')->first();

        if (! $room) {
            session()->flash('error', __('multiplayer.error_room_not_found'));

            return false;
        }

        // Checked AFTER the room is known to exist, so a dead invite code reports itself as
        // dead rather than asking the player to abandon their room for nothing.
        if (! $this->mayLeaveCurrentRoom($code, $confirmed)) {
            return false;
        }

        // One transaction covers two things at once:
        //
        //  1. Leave-then-join must be atomic. Without it a player can be registered in
        //     two rooms at once -- and that used to happen, because joinRoom() never
        //     dropped the old membership at all.
        //  2. Count-capacity-then-register must be atomic too. `count() >= MAX` read
        //     outside the transaction can be passed by two players simultaneously, so
        //     a room ends up over quota; lockForUpdate serializes it.
        $full = DB::transaction(function () use ($room) {
            $players = $room->players()->lockForUpdate()->count();
            $playersFull = $players >= self::MAX_PLAYERS;

            // "Full" only applies to racers: a 6th+ player slot is not rejected but
            // routed to spectator (automatic overflow). The room counts as full for
            // watching only when the spectator quota is also exhausted.
            if ($playersFull && $room->spectators()->lockForUpdate()->count() >= self::MAX_SPECTATORS) {
                return true;
            }

            // Exclude the target room: joining a room you already occupy must not make
            // the host lose host status (see departCurrentRooms).
            $this->departCurrentRooms(Auth::id(), $room->id);

            RoomMember::updateOrCreate(
                ['room_id' => $room->id, 'user_id' => Auth::id()],
                [
                    'role' => $playersFull ? RoomMember::ROLE_SPECTATOR : RoomMember::ROLE_PLAYER,
                    'is_ready' => false,
                    'progress_percent' => 0,
                    'wpm' => 0,
                    'accuracy' => 100,
                    'finished_time_seconds' => null,
                ]
            );

            return false;
        });

        if ($full) {
            session()->flash('error', __('multiplayer.error_room_full'));

            return false;
        }

        $this->roomCode = $code;
        $this->step = 'waiting';
        $this->forgetRoomCache();

        $this->dispatch('subscribe-room', room: $code);

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($code))->toOthers());

        // Presence notice to other members: "<user> joined". ->toOthers() so the
        // joiner doesn't see a notice about themselves.
        SafeBroadcast::run(fn () => broadcast(new RoomPresenceChanged($code, Auth::user()->username, 'join'))->toOthers());

        return true;
    }

    /** React to a room change broadcast by another player and re-sync local step/state. */
    #[On('room-updated')]
    public function roomUpdated()
    {
        // This event is triggered by a change from ANOTHER player -> anything cached
        // during this request is stale.
        $this->forgetRoomCache();

        $room = Room::where('code', $this->roomCode)->first();

        // Room gone (host left / room dissolved): return the remaining player to the
        // choose page rather than leaving them on step 'racing' with no data
        // (every view block needs roomData -> the page would be blank).
        if (! $room) {
            $this->resetToChoose();
            // Unsubscribe from the channel of a room that no longer exists.
            $this->dispatch('leave-room');

            return;
        }

        // Kicked by the host: my membership row is gone but the room still exists (it
        // usually still has other members, so the "room gone" branch above wouldn't catch
        // this). Scoped to the waiting lobby -- the only phase kick happens -- so a player
        // who left AFTER finishing and is still viewing the result modal isn't dragged
        // away from their results.
        if ($room->status === 'waiting') {
            $stillMember = RoomMember::where('room_id', $room->id)
                ->where('user_id', Auth::id())
                ->exists();

            if (! $stillMember) {
                $this->resetToChoose();
                $this->dispatch('leave-room');
                // Reuse the choose-step error banner to tell the kicked player why they're back.
                session()->flash('error', __('multiplayer.you_were_kicked'));

                return;
            }
        }

        if ($room->status === 'racing' && $this->step !== 'racing') {
            $this->step = 'racing';
            $this->resetRaceOutcome();
        }

        if ($room->status === 'finished') {
            $this->showResultModal = true;
            $this->captureResultSnapshot();
        }

        if ($room->status === 'waiting' && $this->step === 'racing') {
            $this->step = 'waiting';
            $this->resetRaceOutcome();
        }
    }

    /** Toggle the caller's ready flag (non-host racers only) and broadcast the change. */
    public function toggleReady(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room) {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        if ($member && $room->host_id !== Auth::id()) {
            $member->update([
                'is_ready' => ! $member->is_ready,
            ]);

            $this->forgetRoomCache();

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
        }
    }

    /**
     * Switch role racer <-> spectator, only while the room is still 'waiting'.
     *
     * The host may become a spectator (host_id is separate from role): they remain the
     * controller holding "Start Race", just not racing. So no host reassignment is
     * needed here. Per-side capacity guard (5 players / 5 spectators).
     */
    public function toggleSpectator(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room || $room->status !== 'waiting') {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        if (! $member) {
            return;
        }

        if ($member->isSpectator()) {
            if ($room->players()->count() >= self::MAX_PLAYERS) {
                session()->flash('error', __('multiplayer.error_players_full'));

                return;
            }

            // Back to racer: reset race & ready state. A host returning to racer stays
            // auto-ready (consistent with createRoom).
            $member->update([
                'role' => RoomMember::ROLE_PLAYER,
                'is_ready' => $room->host_id === Auth::id(),
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
                'place' => null,
                'xp_earned' => null,
            ]);
        } else {
            if ($room->spectators()->count() >= self::MAX_SPECTATORS) {
                session()->flash('error', __('multiplayer.error_spectators_full'));

                return;
            }

            $member->update([
                'role' => RoomMember::ROLE_SPECTATOR,
                'is_ready' => false,
            ]);
        }

        $this->forgetRoomCache();

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
    }

    /** Leave the current room, settle what's left behind, and reset back to choose. */
    public function leaveRoom(): void
    {
        // Delegates to the shared service (same door as the leave-beacon / leave-confirm
        // endpoints): delete membership, settle the room, and broadcast the leave notice
        // + RoomUpdated so roommates re-render.
        app(RoomMembershipService::class)->depart(Auth::id());

        $this->resetToChoose();

        $this->dispatch('leave-room');
    }

    /**
     * Host-only: remove another member from the room while waiting (e.g. a player who
     * won't ready up so the race can't start). The host cannot kick themselves.
     *
     * Blocked once 'racing' has begun: pulling a competitor mid-race would corrupt the
     * finish/placement accounting. The kicked player's own client returns to choose via
     * the membership check in roomUpdated() (their row is gone, but the room isn't).
     */
    public function kickMember(int $userId): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        // Only the host may kick, only in the waiting lobby, and never themselves.
        if (! $room || $room->status !== 'waiting'
            || $room->host_id !== Auth::id()
            || $userId === Auth::id()) {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)
            ->where('user_id', $userId)
            ->first();

        if (! $member) {
            return;
        }

        $kickedUsername = $member->user?->username ?? '';

        $member->delete();

        $this->forgetRoomCache();

        // "<user> was kicked" system notice in the room chat (action 'kick').
        if ($kickedUsername !== '') {
            SafeBroadcast::run(fn () => broadcast(new RoomPresenceChanged($this->roomCode, $kickedUsername, 'kick')));
        }

        // Everyone re-renders: the kicked player leaves (roomUpdated membership check),
        // remaining players see the freed slot. Not ->toOthers(): the host also needs
        // the re-render to drop the kicked card immediately.
        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
    }

    /**
     * The caller's accepted friends, for the "invite to room" picker shown when an empty
     * slot is clicked. Each row: the friend, whether they're online, and whether they're
     * already in THIS room (so the UI can show "In room" instead of an invite button).
     *
     * Any member (not just the host) may open this -- inviting is collaborative. Offline
     * friends are still listed but not invitable (the toast + join link would go unseen).
     *
     * @return Collection<int, array{user: User, online: bool, in_room: bool}>
     */
    public function getInvitableFriendsProperty()
    {
        $me = Auth::id();

        $friendships = Friendship::query()
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $me)->orWhere('addressee_id', $me))
            ->with(['requester', 'addressee'])
            ->get();

        // The users already in this room, so they're marked "in room" rather than invitable.
        $memberIds = $this->roomData
            ? $this->roomData->members->pluck('user_id')->all()
            : [];

        $friends = $friendships
            ->map(fn (Friendship $f) => $f->requester_id === $me ? $f->addressee : $f->requester)
            ->filter(); // guard against a soft-missing side

        // Friends who are in some OTHER room right now. Without this the picker only knew
        // about this room, so inviting looked equally harmless whether the friend was idle or
        // three words from winning somebody else's race -- and the invite they received asked
        // them to walk out of it. One query for the whole list, keyed by user id.
        $elsewhereIds = $friends->isEmpty() ? [] : RoomMember::query()
            ->whereIn('user_id', $friends->pluck('id'))
            ->when($this->roomData, fn ($q) => $q->where('room_id', '!=', $this->roomData->id))
            ->pluck('user_id')
            ->all();

        return $friends
            ->map(fn ($user) => [
                'user' => $user,
                'online' => $user->isOnline(),
                'in_room' => in_array($user->id, $memberIds, true),
                'busy' => in_array($user->id, $elsewhereIds, true),
            ])
            // Online-and-invitable first, then online in-room, then offline; alphabetical within.
            ->sortBy(fn ($row) => [$row['in_room'] ? 1 : 0, $row['online'] ? 0 : 1, mb_strtolower($row['user']->username)])
            ->values();
    }

    /**
     * Invite an accepted friend to this room. Delivered as a real-time toast on the
     * friend's 'friends.{id}' channel (already subscribed for friend/presence events),
     * carrying a deep link to /multiplayer?invite=CODE where mount() auto-joins.
     *
     * Guards: must be in a waiting room, the target must be a real accepted friend (so the
     * feature can't be used to spam arbitrary users), and they must not already be a member.
     * Rate-limited per inviter to blunt invite-spam.
     */
    public function invitePlayer(int $friendId): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        // Only from inside a waiting room, and never invite yourself.
        if (! $room || $room->status !== 'waiting' || $friendId === Auth::id()) {
            return;
        }

        // Must be an accepted friendship in either direction -- not an arbitrary user id.
        $isFriend = Auth::user()->friendshipWith($friendId)?->status === FriendshipStatus::Accepted;

        if (! $isFriend) {
            return;
        }

        // Already in the room? Nothing to invite.
        if (RoomMember::where('room_id', $room->id)->where('user_id', $friendId)->exists()) {
            return;
        }

        // Cap invites so a member can't flood a friend with toasts (10/min per inviter).
        $key = 'room-invite:'.Auth::id();

        if (RateLimiter::tooManyAttempts($key, 10)) {
            return;
        }

        RateLimiter::hit($key, 60);

        SafeBroadcast::run(fn () => broadcast(new RoomInvitationSent(
            $friendId,
            $room->code,
            Auth::user()->username,
            Auth::user()->avatar,
        )));

        // Confirm to the inviter so the UI can show a one-off "invite sent" acknowledgement.
        $this->dispatch('invite-sent', friendId: $friendId);
    }

    /**
     * Send one chat message to every room member. Broadcast-only: not persisted (lobby
     * chat is ephemeral). Allowed for racers and spectators alike, as long as they are
     * still in the room. Deliberately blocked during 'racing' so it can't distract from
     * the race -- the chat panel isn't even rendered in that phase.
     *
     * ->toOthers(): the sender renders their own message optimistically on the client,
     * so receiving this broadcast too would double it.
     */
    public function sendRoomMessage(string $body): void
    {
        $body = trim($body);

        if ($body === '') {
            return;
        }

        // Cap length to keep the WebSocket payload light (matches the global chat max).
        $body = mb_substr($body, 0, 500);

        $room = Room::where('code', $this->roomCode)->first();

        if (! $room || $room->status === 'racing') {
            return;
        }

        // Must genuinely be a member of this room (prevents sending to a room not theirs).
        $isMember = RoomMember::where('room_id', $room->id)
            ->where('user_id', Auth::id())
            ->exists();

        if (! $isMember) {
            return;
        }

        $user = Auth::user();

        SafeBroadcast::run(fn () => broadcast(new RoomMessageSent(
            $this->roomCode,
            $user->id,
            $user->username,
            $body,
        ))->toOthers());
    }

    /** Clear all room state and return to the create/join page. */
    private function resetToChoose(): void
    {
        $this->roomCode = '';
        $this->joinCodeInput = ['', '', '', '', '', ''];
        $this->step = 'choose';

        $this->resetRaceOutcome();

        $this->forgetRoomCache();
    }

    /**
     * Clear all state from one race outcome.
     *
     * Kept as one method because these four properties must ALWAYS be cleared
     * together, and once weren't: resetToChoose() and startRace() missed $hasFinished.
     * As a result a host who had finished a race, then left and made a new room, found
     * their typing panel hidden -- the host doesn't receive their own room.updated
     * broadcast (->toOthers()), so no other path cleared it.
     */
    private function resetRaceOutcome(): void
    {
        $this->showResultModal = false;
        $this->hasGivenUp = false;
        $this->hasFinished = false;
        $this->resultSnapshot = [];
    }

    /**
     * Integrity note: the client's $liveWpm is DELIBERATELY not used for official
     * numbers. The server recomputes Net WPM itself (correct chars / time) via
     * AntiCheatService -- aligned with solo mode (TypingEngine::saveResult) -- so that
     * fast-garbage typing (high WPM, low accuracy) can't conjure a record. $liveWpm is
     * kept only for compatibility with the existing client payload.
     */
    public function updateRaceProgress(int $progressPercent, int $liveWpm = 0, int $accuracy = 100): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if (! $room || $room->status !== 'racing') {
            return;
        }

        // The room flips to 'racing' the moment the host starts, but the synced 3-2-1
        // countdown is still running until race_starts_at. The honest client only emits
        // progress after beginRace() (post-countdown), so any progress arriving during the
        // countdown is forged -- reject it so a scripted client can't bank a finish time
        // (and thus a place) before the race has really begun.
        if ($room->race_starts_at && now()->lt($room->race_starts_at)) {
            return;
        }

        // Sudden-death deadline, enforced on the race's HOTTEST path: a still-typing player's
        // own progress emits (~8x/second) close the race the instant the 15s window elapses,
        // so resolution is server-authoritative and real time -- it no longer waits on that
        // one client's local timer to fire checkSuddenDeath(). Idempotent & race-safe.
        if ($this->resolveSuddenDeathIfElapsed($room)) {
            return;
        }

        // Same real-time enforcement for the hard ceiling: a still-typing player's own emits
        // close the race the instant it passes. Placed after sudden death because that window
        // is the tighter one whenever it is running.
        //
        // The start-grace rule is deliberately EXCLUDED here, and the reason is not a detail:
        // this method runs BEFORE the caller's own progress is written, so the rule would judge
        // the emitter against the stale zero their in-flight update is about to replace. A
        // player who finally starts typing at second 19.9 would be killed by their own first
        // keystroke. It is also simply unnecessary -- someone emitting progress is, by
        // definition, not the idle player that rule exists to remove.
        if ($this->resolveRaceDeadlinesIfElapsed($room, includeStartGrace: false)) {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        // A player who has already finished may no longer broadcast progress.
        if (! $member || ! is_null($member->finished_time_seconds)) {
            return;
        }

        // Spectators are not racing. Without this they could post progress, take a
        // finish time and a place, and shift every real racer's standing.
        if ($member->role !== RoomMember::ROLE_PLAYER) {
            return;
        }

        // Server-side rate limit on the race's hottest path: each accepted update
        // recomputes WPM and broadcasts to every room member. The honest client
        // self-throttles to ~8 emits/sec (120ms); this caps a scripted client that ignores
        // that. Over-limit ticks are dropped silently -- progress is monotonic, so the next
        // accepted tick still carries the latest position, nothing is lost.
        $rateKey = 'race-progress:'.Auth::id();
        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_PROGRESS_UPDATES_PER_SECOND)) {
            return;
        }
        RateLimiter::hit($rateKey, 1);

        $progressPercent = min(100, max(0, $progressPercent));
        $accuracy = min(100, max(0, $accuracy));

        // Progress only ever moves forward: it is the count of correct characters typed so
        // far, which cannot decrease. Accepting a lower value would let a client rewind to
        // replay the fast part of the text, or drop to 0 to reset its own pace.
        $progressPercent = max($progressPercent, (int) $member->progress_percent);

        // Authoritative Net WPM: derived from progress (progress% x text length = correct
        // chars, same pattern as finalizeRace) and the server-side race duration, NOT the
        // client's WPM. Wrong characters don't advance progress, so this is automatically "net".
        $textLength = mb_strlen($room->text_to_type);
        $correctChars = (int) round(($progressPercent / 100) * $textLength);

        $raceStart = $room->race_starts_at ?? $room->updated_at;
        $durationSeconds = max(0.0, (float) $raceStart->diffInSeconds(now(), true));

        // Net WPM formula is identical to solo mode (one source of truth).
        //
        // totalChars = correctChars because progress only rises from correct characters --
        // an invariant ENFORCED BY THE CLIENT (race-arena.js word-lock: a word never passes
        // until typed exactly). The server can't verify it: it derives correct chars from
        // progress and never sees the typed text. So this is a game rule, not a security
        // boundary. A forged payload is still bounded by the gates below and around this
        // method (MAX_RACE_WPM, the progress/accuracy cross-check, monotonic progress, the
        // countdown gate and the rate limit).
        //
        // When space DID advance a word regardless of what was typed, this comment was
        // simply false: typing part of each word inflated progress, and with it this
        // "recomputed" number -- while finishing sooner in real time, which is what decides
        // the winner. See docs/features/multiplayer-race.md §3.2.
        $antiCheat = app(AntiCheatService::class);
        $wpmCheck = $antiCheat->check($correctChars, $correctChars, $durationSeconds);

        // Reaching this progress this fast is physically impossible, so the claim itself is
        // refused rather than merely scored as 0 WPM. Letting it through would still stamp a
        // finish time and a place -- the part that decides who "won" -- even though the
        // result is later thrown out at finalization.
        if ($antiCheat->exceedsRaceSpeed($correctChars, $durationSeconds)) {
            return;
        }

        // Only impossible signals are REJECTED (WPM beyond human limits / inconsistent
        // chars). Low throughput / short duration are normal early on and for slow players
        // -- their WPM is genuinely small, not cheating -- so the number is used as-is.
        $netWpm = $antiCheat->isCheating($wpmCheck['reasons']) ? 0 : (int) round($wpmCheck['net_wpm']);

        $updateData = [
            'progress_percent' => $progressPercent,
            'wpm' => $netWpm,
            'accuracy' => $accuracy,
        ];

        $justFinished = false;
        $suddenDeathJustStarted = false;

        if ($progressPercent >= 100) {
            $justFinished = true;

            // Elapsed = now - race_starts_at (the point the countdown ends); fall back to
            // updated_at only if race_starts_at is empty. absolute: true -> no negatives.
            $updateData['finished_time_seconds'] = (int) round($durationSeconds);

            // Validate the finish BEFORE it can start sudden death or take a place. A result
            // that finalization would reject anyway (fast-garbage: high progress with an
            // impossibly low accuracy, or an empty session) must not (a) start the sudden
            // death clock -- that would cut the race short for the honest players still
            // typing -- nor (b) claim a podium slot. So an invalid finisher is marked
            // finished (they can't keep racing) but with place = null, and sudden death only
            // ever starts off a VALID finish. isValidRaceResult reads the member's accuracy
            // and progress, so apply this update to the in-memory model first.
            $member->progress_percent = $progressPercent;
            $member->accuracy = $accuracy;
            $member->finished_time_seconds = $updateData['finished_time_seconds'];
            $isValidFinish = $this->isValidRaceResult($member, $correctChars);

            if ($isValidFinish) {
                // TEMPORARY place, not authoritative. This count-then-plus-one is not atomic:
                // two players finishing in the same millisecond can read the same count and
                // get identical numbers. Deliberately NOT locked, and the duplicate is never
                // seen by anyone: this column is NOT rendered while racing (the live "who's
                // ahead" number comes from rankOf() in race-arena.js). By the time placement
                // IS displayed (the result screen) finalizeRace() has re-ranked everyone in
                // one transaction -- the single source of truth for placement. Only VALID
                // finishers are counted, so an invalid one never shifts the honest standings.
                $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
                    ->where('role', RoomMember::ROLE_PLAYER)
                    ->whereNotNull('finished_time_seconds')
                    ->whereNotNull('place')
                    ->count();

                $updateData['place'] = $alreadyFinishedCount + 1;

                // Sudden death starts only off a valid finish: keep waiting for a real
                // finisher otherwise.
                $suddenDeathJustStarted = $this->startSuddenDeathIfNeeded($room);
            } else {
                // Rejected result: finished, but no place and no sudden death. finalizeRace
                // will confirm place = null and mark result_recorded = false.
                $updateData['place'] = null;
            }

            $this->hasFinished = true;
        }

        $member->update($updateData);
        $this->forgetRoomCache();

        // Opponent mascot movement: payload goes straight over the WebSocket (channel
        // race.{code}); other clients just read it and shift the mascot in the Alpine
        // store without a server round-trip.
        SafeBroadcast::run(fn () => broadcast(new RaceProgressUpdated($this->roomCode, Auth::id(), [
            'progress_percent' => $progressPercent,
            'wpm' => $netWpm,
            'accuracy' => $accuracy,
            'finished' => $justFinished,
        ]))->toOthers());

        // First player finishes -> sudden death starts. Send the same end timestamp to
        // all clients so the countdown stays in sync; the server remains the final gate
        // via checkSuddenDeath().
        if ($suddenDeathJustStarted) {
            SafeBroadcast::run(fn () => broadcast(new SuddenDeathTriggered(
                $this->roomCode,
                // Time LEFT, not an end timestamp: a client comparing an absolute time to its
                // own Date.now() reads its clock skew as elapsed time -- a clock running fast
                // computed "0 left" and locked the player out of a race they had a full
                // window to finish. Reuses the same accessor the initial render uses, so the
                // two can never drift apart.
                $this->suddenDeathRemaining,
                $room->countdown_started_at->copy()->addSeconds(self::SUDDEN_DEATH_SECONDS)->toIso8601String(),
            )));
        }

        // Lifecycle (not just movement): the room changed (badge, modal, place) -> re-render via RoomUpdated.
        if ($justFinished) {
            // Fast-path: if every participant has finished, close the room without waiting for the timeout.
            $unfinished = RoomMember::where('room_id', $room->id)
                ->where('role', RoomMember::ROLE_PLAYER)
                ->whereNull('finished_time_seconds')
                ->count();

            if ($unfinished === 0) {
                $room->update(['status' => 'finished']);
                $this->finalizeRace($room->id);
                $this->captureResultSnapshot();
            }

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
        }
    }

    /** Concede the current race: mark the caller DNF, maybe start sudden death, maybe finalize. */
    public function giveUp(): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if (! $room || $room->status !== 'racing') {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        if (! $member || ! is_null($member->finished_time_seconds)) {
            return;
        }

        // A give-up is a concession (DNF), not a valid finish, so it takes no numbered
        // place -- finalizeRace re-ranks only valid finishers and confirms this null.
        $member->update([
            'finished_time_seconds' => RoomMember::DNF_SENTINEL_SECONDS,
            'place' => null,
        ]);
        $this->forgetRoomCache();

        $this->hasGivenUp = true;

        // Deliberately does NOT start sudden death: the clock only runs once someone
        // finishes with a VALID result (same rule as updateRaceProgress). Conceding must
        // not cut the race short for the honest players still typing.

        $this->dispatch('force-finish');

        $unfinished = RoomMember::where('room_id', $room->id)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->whereNull('finished_time_seconds')
            ->count();

        if ($unfinished === 0) {
            $room->update(['status' => 'finished']);
            $this->finalizeRace($room->id);
            $this->showResultModal = true;
            $this->captureResultSnapshot();
        }

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
    }

    /**
     * Backstop gate for a PAUSED player: when someone stops typing their progress emits
     * stop, so the real-time path in updateRaceProgress() can't fire -- the client's local
     * sudden-death timer then calls this once at 0 to close the race. Both paths run the
     * same server-authoritative check, resolveSuddenDeathIfElapsed().
     */
    public function checkSuddenDeath(): void
    {
        if (! $this->roomCode || $this->step !== 'racing') {
            return;
        }

        $room = Room::where('code', $this->roomCode)->first();
        if (! $room) {
            return;
        }

        $this->resolveSuddenDeathIfElapsed($room);
    }

    /**
     * Client-timer gate for the race deadlines (start grace + hard ceiling).
     *
     * This is the path that matters most for these two, and it is the mirror image of
     * checkSuddenDeath(): the progress path can only enforce a deadline while SOMEONE is
     * still emitting progress, and the whole point here is the case where nobody is typing
     * at all. The client watches the same server-issued countdown and calls this once it
     * reaches zero; the server re-decides everything (see resolveRaceDeadlinesIfElapsed).
     */
    public function checkRaceDeadline(): void
    {
        if (! $this->roomCode || $this->step !== 'racing') {
            return;
        }

        $room = Room::where('code', $this->roomCode)->first();
        if (! $room) {
            return;
        }

        $this->resolveRaceDeadlinesIfElapsed($room);
    }

    /**
     * Finalize the race if the sudden-death window has elapsed. THE single place the deadline
     * is decided, called from BOTH updateRaceProgress() (real time, driven by a still-typing
     * player's own emits ~8x/second) and checkSuddenDeath() (a paused player's client timer).
     *
     * Why this exists: the race used to be closed ONLY when some client's local timer fired
     * checkSuddenDeath() at 0. For a player who is still typing, the server therefore never
     * enforced the deadline in real time from that player's own activity -- it sat on
     * countdown_started_at and waited for a single client ping, which a slow server, a
     * throttled tab, or clock skew could delay or drop. Enforcing it on the progress path
     * makes the still-typing player's own keystrokes close the race the instant time runs out.
     *
     * Always measured from countdown_started_at on the SERVER clock, never a client's. Race
     * safe: the conditional `where('status', 'racing')` update means exactly ONE caller -- of
     * any number of concurrent progress emits and timer pings -- performs the finalize; every
     * other no-ops. Returns true only on the call that actually closed the race.
     */
    private function resolveSuddenDeathIfElapsed(Room $room): bool
    {
        if (! $room->countdown_started_at
            || now()->diffInSeconds($room->countdown_started_at, true) < self::SUDDEN_DEATH_SECONDS) {
            return false;
        }

        return $this->closeRaceNow($room);
    }

    /**
     * Enforce the two race deadlines: the start grace window and the hard ceiling.
     *
     * Companion to resolveSuddenDeathIfElapsed() and deliberately built the same way -- one
     * place decides, an atomic conditional update makes exactly one caller finalize, and it
     * is reached from both the progress path (real time, driven by whoever is still typing)
     * and a client timer (the only signal available when EVERYONE has stopped).
     *
     * $includeStartGrace is false everywhere EXCEPT the client timer, and that asymmetry is
     * the point rather than an optimisation. The ceiling and the no-racers case end the race
     * for everyone, so any caller may decide them. Dropping an individual idle player is a
     * judgement about ONE person, and only the live arena that watched them sit through the
     * countdown has the standing to make it:
     *
     *   - from updateRaceProgress() it would judge the caller on the stale zero their own
     *     in-flight update is about to replace -- killing a player with their first keystroke;
     *   - from mount() it would judge a player on a page that has only just arrived.
     *
     * Returns true only on the call that actually closed the race.
     */
    private function resolveRaceDeadlinesIfElapsed(Room $room, bool $includeStartGrace = true): bool
    {
        if ($room->status !== 'racing') {
            return false;
        }

        // A race with no racers left cannot finish itself: finalizeRace is only ever reached
        // from a finish or a give-up, and there is nobody left to produce either. This happens
        // for real -- the last racer may leave mid-race through the leave-confirm overlay --
        // and it strands any spectators in an empty arena forever. Checked before the clocks
        // below because it is true the moment it happens, not after a delay.
        if ($room->players()->count() === 0) {
            return $this->closeRaceNow($room);
        }

        // Both deadlines hang off race_starts_at, which startRace() always sets. Without it
        // there is no clock to measure against, so nothing is enforced (rather than guessing
        // from updated_at, which any progress write would move).
        if (! $room->race_starts_at) {
            return false;
        }

        $elapsed = now()->diffInSeconds($room->race_starts_at, true);

        if ($elapsed >= self::MAX_RACE_SECONDS) {
            return $this->closeRaceNow($room);
        }

        if (! $includeStartGrace || $elapsed < self::START_GRACE_SECONDS) {
            return false;
        }

        // Drop the racers who never started. One conditional statement rather than a
        // read-then-write: a player typing their first character at this exact moment either
        // lands before it (progress > 0, so excluded) or after it (their row already carries
        // the sentinel, and updateRaceProgress refuses a finished member). Neither order can
        // DNF someone who did type.
        $dropped = RoomMember::where('room_id', $room->id)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->whereNull('finished_time_seconds')
            ->where('progress_percent', '<=', 0)
            ->update([
                'finished_time_seconds' => RoomMember::DNF_SENTINEL_SECONDS,
                'place' => null,
            ]);

        if ($dropped === 0) {
            return false;
        }

        $this->forgetRoomCache();

        // Everyone idled out -> nobody is left to finish, so close now rather than leaving a
        // race of pure DNFs running to the hard ceiling.
        $stillRacing = RoomMember::where('room_id', $room->id)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->whereNull('finished_time_seconds')
            ->count();

        if ($stillRacing === 0) {
            return $this->closeRaceNow($room);
        }

        // The race continues for whoever is genuinely typing; the others just see the dropped
        // players marked DNF.
        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));

        return false;
    }

    /**
     * Close a running race right now: DNF everyone unfinished, finalize, and tell every client.
     *
     * Extracted because THREE deadlines end a race the same way (sudden death, the start
     * grace window, the hard ceiling) and they must not drift into three subtly different
     * definitions of "the race is over".
     *
     * Race safe: the conditional `where('status', 'racing')` update means exactly ONE caller
     * -- of any number of concurrent progress emits and timer pings -- performs the finalize;
     * every other no-ops. Returns true only on the call that actually closed the race.
     */
    private function closeRaceNow(Room $room): bool
    {
        // Atomic racing -> finished: only the winner of this update runs the finalize below.
        $claimed = Room::where('id', $room->id)
            ->where('status', 'racing')
            ->update(['status' => 'finished']);

        if (! $claimed) {
            return false;
        }

        // Default (DNF) placement for players who didn't finish. Racers only: spectators
        // have no finished_time_seconds and are not DNF.
        RoomMember::where('room_id', $room->id)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->whereNull('finished_time_seconds')
            ->update([
                'finished_time_seconds' => RoomMember::DNF_SENTINEL_SECONDS,
            ]);

        $this->forgetRoomCache();

        $this->finalizeRace($room->id);
        $this->showResultModal = true;
        $this->captureResultSnapshot();

        // Lock the triggering client's input now; everyone else reacts to the RoomUpdated
        // broadcast below (roomUpdated() flips their showResultModal from the finished status).
        $this->dispatch('force-finish');

        // Broadcast to everyone (not toOthers): the triggering client also needs the final status.
        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));

        return true;
    }

    /** Host-only: reset the finished room back to waiting with fresh text for a rematch. */
    public function playAgain(): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if ($room && $room->host_id === Auth::id()) {
            $room->update([
                'status' => 'waiting',
                // Reset so checkSuddenDeath() doesn't auto-finish immediately off the old race timer.
                'countdown_started_at' => null,
                // race_starts_at is deliberately NOT cleared here. startRace() is the single
                // writer of that column (locked by RaceTrackDesignTest, because a second write
                // mid-race would reset every mascot), and the deadline resolver already refuses
                // any room that is not 'racing' -- so the stale value is unreachable, and
                // clearing it would cost more than it protects.
                // Rematch keeps the room's language.
                'text_to_type' => $this->generateRaceText($room->language),
            ]);
            RoomMember::where('room_id', $room->id)->update([
                'is_ready' => false,
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
                'place' => null,
                'xp_earned' => null, // next race can award XP again
            ]);

            $this->step = 'waiting';
            $this->resetRaceOutcome();

            $this->forgetRoomCache();

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
        }
    }

    public function render()
    {
        // Last guard before the view is evaluated: if we still hold a roomCode but the
        // room is gone (host left), recover to the choose page. Without this every view
        // block fails its condition -> blank page.
        if ($this->step !== 'choose' && $this->roomCode !== ''
            && ! Room::where('code', $this->roomCode)->exists()) {
            $this->resetToChoose();
            $this->dispatch('leave-room');
        }

        return view('livewire.multiplayer-lobby')->layout('layouts.app');
    }

    /** Host-only: schedule the synced countdown and flip the room to 'racing'. */
    public function startRace(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room || $room->host_id !== Auth::id()) {
            return;
        }

        // A race needs at least two RACERS -- spectators never count, however many there are.
        // The old rule only refused an empty grid, so one racer plus any number of watchers
        // started a "race" with a single competitor: a countdown, a finish line and a
        // placement for someone who had nobody to beat.
        //
        // Checked here and not only behind the button because the button is a hint, not a
        // gate: startRace() is a public Livewire method any client can call, and the racer
        // count can also drop between the render that enabled the button and the click that
        // fires it (someone switches to spectator, or leaves).
        if ($room->players()->count() < self::MIN_PLAYERS_TO_START) {
            session()->flash('error', __('multiplayer.error_not_enough_players'));

            return;
        }

        RoomMember::where('room_id', $room->id)->update([
            'progress_percent' => 0,
            'wpm' => 0,
            'accuracy' => 100,
            'finished_time_seconds' => null,
            'place' => null,
            'xp_earned' => null, // defense in depth: ensure XP can be awarded once more
        ]);

        $room->update([
            'status' => 'racing',
            // Defense in depth: ensure the sudden death timer is clean for each new race.
            'countdown_started_at' => null,
            // Start is set by the server: now() + 3 seconds; all clients count down to this
            // absolute time so the countdown stays in sync.
            'race_starts_at' => now()->addSeconds(self::COUNTDOWN_SECONDS),
        ]);

        $this->step = 'racing';
        $this->resetRaceOutcome();

        $this->forgetRoomCache();

        // toOthers(): the host ALREADY entered 'racing' above. Without this the host also
        // receives their own room.updated -> Livewire re-renders mid-countdown -> the arena
        // is morphed -> the Alpine countdown restarts from the beginning.
        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
    }
}
