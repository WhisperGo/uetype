<?php

namespace App\Livewire;

use App\Events\RaceProgressUpdated;
use App\Events\RoomMessageSent;
use App\Events\RoomPresenceChanged;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Livewire\Concerns\FinalizesRace;
use App\Livewire\Concerns\ManagesRoomMembership;
use App\Livewire\Concerns\ReadsRoomState;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\AntiCheatService;
use App\Services\RoomMembershipService;
use App\Services\TextGeneratorService;
use App\Support\SafeBroadcast;
use App\Support\TypingLanguage;
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
     * Restore the caller straight into their room on load (no re-entering the code).
     *
     * The component has no persisted client state across a full page load (the nav is a
     * plain anchor, not wire:navigate), but the room_members row survives in the DB. So on
     * mount we look up the user's current membership and hydrate $roomCode/$step from it.
     * A not-ready player who truly left elsewhere had their row removed by the leave beacon
     * (see MultiplayerPresenceController), so no stale row is restored; a ready/host member
     * (whom the beacon skips) is brought right back.
     */
    public function mount(): void
    {
        $member = RoomMember::where('user_id', Auth::id())->first();

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

    /** Create a fresh room, make the caller its host, and subscribe to its channel. */
    public function createRoom(): void
    {
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

        $room = Room::where('code', $code)->where('status', 'waiting')->first();

        if (! $room) {
            session()->flash('error', __('multiplayer.error_room_not_found'));

            return;
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

            return;
        }

        $this->roomCode = $code;
        $this->step = 'waiting';
        $this->forgetRoomCache();

        $this->dispatch('subscribe-room', room: $code);

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($code))->toOthers());

        // Presence notice to other members: "<user> joined". ->toOthers() so the
        // joiner doesn't see a notice about themselves.
        SafeBroadcast::run(fn () => broadcast(new RoomPresenceChanged($code, Auth::user()->username, 'join'))->toOthers());
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
     * Max race-progress updates accepted per second per player. The honest client emits
     * ~8/sec (120ms throttle); 20 leaves room for bursts + the trailing flush while still
     * capping a scripted flood (each accepted update also broadcasts to the whole room).
     */
    private const MAX_PROGRESS_UPDATES_PER_SECOND = 20;

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
        // totalChars = correctChars: progress only rises from correct characters, so net
        // WPM can't be pumped by typing garbage.
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

            // TEMPORARY place, not authoritative. This count-then-plus-one is not atomic:
            // two players finishing in the same millisecond can read the same count and
            // get identical numbers.
            //
            // Deliberately NOT locked, and the duplicate is never seen by anyone: this
            // column is NOT rendered while racing. The live "who's ahead" number comes
            // from rankOf() in resources/js/race-arena.js, computed client-side from
            // progress. By the time placement IS displayed (the result screen) this value
            // has been fully overwritten by finalizeRace(), which re-ranks every player in
            // one query inside a transaction -- the single source of truth for placement.
            // Locking here would pay contention cost on the hottest path of the race to
            // deduplicate a number nobody reads and that is discarded anyway.
            $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
                ->where('role', RoomMember::ROLE_PLAYER)
                ->whereNotNull('finished_time_seconds')
                ->count();

            $updateData['place'] = $alreadyFinishedCount + 1;

            $suddenDeathJustStarted = $this->startSuddenDeathIfNeeded($room);
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

        // Same provisional, unlocked count-then-plus-one as in updateRaceProgress() --
        // see the comment there for why that is deliberate and harmless. Do not "fix"
        // this one in isolation.
        $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->whereNotNull('finished_time_seconds')
            ->count();

        $member->update([
            'finished_time_seconds' => RoomMember::DNF_SENTINEL_SECONDS,
            'place' => $alreadyFinishedCount + 1,
        ]);
        $this->forgetRoomCache();

        $this->hasGivenUp = true;

        if ($this->startSuddenDeathIfNeeded($room)) {
            SafeBroadcast::run(fn () => broadcast(new SuddenDeathTriggered(
                $this->roomCode,
                $room->countdown_started_at->copy()->addSeconds(self::SUDDEN_DEATH_SECONDS)->toIso8601String(),
            )));
        }

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

    /** Poll-driven gate: once the sudden-death window elapses, force-finish the race. */
    public function checkSuddenDeath(): void
    {
        if (! $this->roomCode || $this->step !== 'racing') {
            return;
        }

        $room = Room::where('code', $this->roomCode)->first();
        if (! $room || ! $room->countdown_started_at) {
            return;
        }

        // Seconds elapsed since sudden death began (counts up, 0 -> 15); only the
        // auto-finish condition. For the countdown display, use getSuddenDeathRemainingProperty().
        $secondsPassed = now()->diffInSeconds($room->countdown_started_at, true);

        if ($secondsPassed >= self::SUDDEN_DEATH_SECONDS) {
            $room->update(['status' => 'finished']);

            // Default (DNF) placement for players who didn't finish. Racers only:
            // spectators have no finished_time_seconds and are not DNF.
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

            // Lock this client's input now; the guard above keeps the method idempotent
            // even if triggered by several clients at once.
            $this->dispatch('force-finish');

            // Broadcast to everyone (not toOthers): the triggering client also needs the final status.
            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
        }
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

        // Can't start without racers: the host may be a spectator, and if everyone is a
        // spectator, no one is competing.
        if ($room->players()->count() === 0) {
            session()->flash('error', __('multiplayer.error_no_players'));

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
