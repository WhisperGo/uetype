<?php

namespace App\Livewire;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Services\ClanWarModeCatalog;
use App\Services\ClanWarResolver;
use App\Support\ClanWarBroadcast;
use App\Support\SafeBroadcast;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Clan War hub: issue/accept/decline challenges, claim mode slots, view the
 * battle. Lazily resolves due wars on mount (ClanWarResolver), so no scheduler.
 */
class ClanWar extends Component
{
    // Durasi war setelah tantangan diterima.
    public const WAR_DURATION_DAYS = 3;

    // How long a challenge has to be accepted before it auto-expires.
    public const ACCEPT_WINDOW_HOURS = 1;

    /**
     * Claims this member may still make in the current war.
     *
     * The cap is per-war and per-side (ClanWar::maxClaimsFor), so it has to be read from the
     * war rather than from a constant here -- a flat 4 is what made a 2-member clan unable to
     * fill 9 slots at all. Memoised by Livewire so the grid and this share one pair of queries.
     */
    public function getMyRemainingClaimsProperty(): int
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Ongoing || ! $this->myClan) {
            return 0;
        }

        $held = ClanWarModeClaim::where('clan_war_id', $war->id)
            ->where('clan_id', $this->myClan->id)
            ->where('user_id', Auth::id())
            ->count();

        return max(0, $war->maxClaimsFor($this->myClan->id) - $held);
    }

    /** Close overdue wars (expire Pending, resolve Ongoing) before computed properties read data. */
    public function mount(ClanWarResolver $resolver): void
    {
        $resolver->resolveDue();
    }

    /**
     * Echo listener for clan.{me}: raised by the bridge script at the foot of clan-war.blade.php
     * whenever toasts.js sees a `.clan.updated` on our channel (a teammate claimed a slot, the
     * opposing clan scored, a challenge was answered).
     *
     * Deliberately EMPTY, and that is the whole fix. Every datum on this page comes from a
     * legacy getXProperty() computed property, which Livewire memoises in the per-component
     * store -- a store rebuilt from the snapshot on every round-trip. Merely HANDLING the event
     * therefore re-runs every query. (Clans::refreshClan() has a body only because that
     * component uses #[Computed] and mutates the same data inside one request.)
     *
     * The bridge existed long before this method did; without a listener the dispatch landed
     * nowhere and the page only ever changed on a full reload.
     */
    #[On('clan-updated')]
    public function refreshWar(): void {}

    // ---- DATA (computed) ----

    public function getMyMembershipProperty(): ?ClanMember
    {
        return ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->first();
    }

    public function getMyClanProperty(): ?Clan
    {
        return $this->myMembership?->clan;
    }

    /**
     * Declaring, accepting and declining a war stakes the clan's permanent Elo power,
     * so it stays with the single leader -- a co-leader's remit is the roster.
     */
    public function getIsLeaderProperty(): bool
    {
        return $this->myMembership?->role === ClanRole::Leader;
    }

    /** Roster powers (leader or co-leader); enough to cancel a teammate's stale claim. */
    public function getCanManageMembersProperty(): bool
    {
        return (bool) $this->myMembership?->role->canManageMembers();
    }

    public function getMyActiveWarProperty(): ?ClanWarModel
    {
        return $this->myClan?->activeWar();
    }

    /** Incoming challenge (Pending war where our clan is the opponent); only relevant to leaders. */
    public function getIncomingChallengeProperty(): ?ClanWarModel
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Pending || ! $this->myClan) {
            return null;
        }

        return $war->opponent_clan_id === $this->myClan->id ? $war : null;
    }

    /** Other clans free to challenge; only for a leader whose own clan is also free. */
    public function getChallengeableClansProperty()
    {
        if (! $this->isLeader || $this->myActiveWar) {
            return collect();
        }

        // The "currently at war" filter runs IN THE DATABASE via a single subquery.
        // Previously the full list was pulled then filter()ed in PHP by calling activeWar()
        // per clan -- one extra query for EVERY clan, every time this page renders. With 50
        // clans that's 51 queries for one list.
        $busyClanIds = ClanWarModel::query()
            ->whereIn('status', [ClanWarStatus::Pending, ClanWarStatus::Ongoing])
            ->select('challenger_clan_id')
            ->union(
                ClanWarModel::query()
                    ->whereIn('status', [ClanWarStatus::Pending, ClanWarStatus::Ongoing])
                    ->select('opponent_clan_id')
            );

        // populated(): a clan with no active members cannot field a single mode claim, so
        // challenging one is free Elo rather than a war.
        return Clan::populated()
            ->where('id', '!=', $this->myClan->id)
            ->whereNotIn('id', $busyClanIds)
            ->orderByDesc('power')
            ->get();
    }

    /** This clan's own finished war history, newest first. */
    public function getWarHistoryProperty()
    {
        if (! $this->myClan) {
            return collect();
        }

        return ClanWarModel::with(['challenger', 'opponent'])
            ->where(function ($q) {
                $q->where('challenger_clan_id', $this->myClan->id)
                    ->orWhere('opponent_clan_id', $this->myClan->id);
            })
            ->where('status', ClanWarStatus::Finished)
            ->latest('updated_at')
            ->take(5)
            ->get();
    }

    /**
     * The 9-mode grid required for an Ongoing war. Each entry: mode/config/ceiling +
     * status ('open' | 'claimed' | 'in_progress' | 'done') plus the claim row if any.
     *
     * 'in_progress' exists because a claim now has three lives, not two: RESERVED (claimed,
     * never opened, still cancellable), IN PROGRESS (the one attempt has been opened, so it
     * is spent whatever happens), and DONE. Without the middle state the screen would offer
     * Cancel on a slot that has already been played.
     */
    public function getModeGridProperty()
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Ongoing || ! $this->myClan) {
            return collect();
        }

        // Fetch all claims at once (avoid N queries); typingResult eager-loaded for the
        // already-finished mode cards.
        $claims = ClanWarModeClaim::with(['user', 'typingResult'])
            ->where('clan_war_id', $war->id)
            ->where('clan_id', $this->myClan->id)
            ->get()
            ->keyBy(fn (ClanWarModeClaim $c) => $c->mode.'|'.$c->mode_config);

        return collect(ClanWarModeCatalog::MODES)->map(function (array $m) use ($claims) {
            $claim = $claims->get($m['mode'].'|'.$m['config']);

            $status = 'open';
            if ($claim) {
                $status = match (true) {
                    $claim->isSubmitted() => 'done',
                    $claim->attemptStarted() => 'in_progress',
                    default => 'claimed',
                };
            }

            return [
                'mode' => $m['mode'],
                'config' => $m['config'],
                'ceiling' => $m['ceiling'],
                'status' => $status,
                'claim' => $claim,
            ];
        });
    }

    /** This clan's total points so far (only submitted modes). */
    public function getMyClanPointsProperty(): float
    {
        $war = $this->myActiveWar;

        if (! $war || ! $this->myClan) {
            return 0.0;
        }

        return (float) ClanWarModeClaim::where('clan_war_id', $war->id)
            ->where('clan_id', $this->myClan->id)
            ->whereNotNull('typing_result_id')
            ->sum('points');
    }

    /**
     * The opposing clan's total points so far (only submitted modes) -- the missing half of
     * the scoreboard: a war you can't see the enemy's score in is just solo practice with a
     * countdown. Same "submitted only" rule as our own total, so both sides are compared on
     * the same footing (a locked-but-unplayed slot is worth 0 for either clan).
     */
    public function getOpponentClanPointsProperty(): float
    {
        $war = $this->myActiveWar;

        if (! $war || ! $this->myClan) {
            return 0.0;
        }

        $opponentClanId = $war->challenger_clan_id === $this->myClan->id
            ? $war->opponent_clan_id
            : $war->challenger_clan_id;

        return (float) ClanWarModeClaim::where('clan_war_id', $war->id)
            ->where('clan_id', $opponentClanId)
            ->whereNotNull('typing_result_id')
            ->sum('points');
    }

    // ---- ACTIONS ----

    /**
     * RESERVE one of the 9 modes for this member. It does not start playing it.
     *
     * Claiming used to redirect straight into the typing engine, which made claim and attempt
     * the same act -- and once opening the page became the moment the one attempt starts, that
     * would have burned a slot on a misclick and left cancelClaim() unreachable. Reserving and
     * starting are now separate steps, with the confirmation on the one that cannot be undone.
     *
     * The DB unique constraint is the last safety net against two members claiming at once.
     */
    public function claimMode(string $mode, string $config): void
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Ongoing || ! $this->myClan) {
            return;
        }

        if (! ClanWarModeCatalog::isValidMode($mode, $config)) {
            return;
        }

        try {
            // The closure returns a REASON on failure rather than a bare null: all three ways
            // to fail used to collapse into "this mode was just taken by another member",
            // which is simply false for a member who has run out of quota -- and it sent them
            // looking for a teammate who did not exist.
            $outcome = DB::transaction(function () use ($war, $mode, $config) {
                // First check this clan hasn't already claimed this slot.
                $existing = ClanWarModeClaim::where('clan_war_id', $war->id)
                    ->where('clan_id', $this->myClan->id)
                    ->where('mode', $mode)
                    ->where('mode_config', $config)
                    ->first();

                if ($existing) {
                    return 'mode_taken';
                }

                // One member may not hold every slot: a war is meant to be a clan effort, and
                // concentrating all 9 in one account lets a single compromised or cheating
                // player decide it alone. The cap scales with the roster the war was accepted
                // with, so a small clan can still fill the grid (ClanWarModeCatalog).
                $mine = ClanWarModeClaim::where('clan_war_id', $war->id)
                    ->where('clan_id', $this->myClan->id)
                    ->where('user_id', Auth::id())
                    ->count();

                if ($mine >= $war->maxClaimsFor($this->myClan->id)) {
                    return 'max_claims';
                }

                return ClanWarModeClaim::create([
                    'clan_war_id' => $war->id,
                    'clan_id' => $this->myClan->id,
                    'user_id' => Auth::id(),
                    'mode' => $mode,
                    'mode_config' => $config,
                    'claimed_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // Unique violation: another member really did just take this slot.
            $outcome = 'mode_taken';
        }

        if (! $outcome instanceof ClanWarModeClaim) {
            session()->flash('clan_war_claim_error', $outcome === 'max_claims'
                ? __('clan.error.max_claims', ['max' => $war->maxClaimsFor($this->myClan->id)])
                : __('clan.error.mode_taken'));

            return;
        }

        // The grid is shared state: this slot just stopped being available to everyone else in
        // the clan, and the opposing side's view of the war changed too.
        ClanWarBroadcast::refresh($war);
    }

    /**
     * Enter the typing engine for one of MY claims -- reserved or already in progress.
     *
     * A server action rather than an <a href> built in the blade, and the reason is a bug this
     * replaces: the link's Alpine handler interpolated the route with @js(), which Blade does
     * NOT compile inside a component tag's attribute (<x-btn-gold>). The directive reached the
     * browser verbatim, Alpine choked on the `=>` in the PHP array, and the whole @click.prevent
     * died -- .prevent still cancelled the navigation, so the button did precisely nothing.
     * Routing through Livewire keeps URLs out of Alpine expressions entirely, so the failure
     * mode cannot come back.
     *
     * It also puts the ownership check on the SERVER. The grid only ever hid the button behind
     * an @if, while the guard that mattered lived in TypingEngine::resolveWarClaim(); that guard
     * is still there, and this is a second lock on the door rather than a replacement for it.
     *
     * NOT wire:navigate: the typing engine has to be entered by a full page load, because an SPA
     * navigation leaves the previous component's Alpine state in place and this is the hand-off
     * that anchors a fresh attempt. $this->redirect() without navigate: true is exactly that.
     */
    public function startAttempt(int $claimId)
    {
        if (! $this->myClan) {
            return null;
        }

        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Ongoing) {
            return null;
        }

        // Mirrors TypingEngine::resolveWarClaim(): mine, my clan's, in this war, not yet played.
        // A submitted claim is finished -- re-entering it would offer a second attempt at a slot
        // that already scored.
        $claim = ClanWarModeClaim::where('id', $claimId)
            ->where('clan_war_id', $war->id)
            ->where('clan_id', $this->myClan->id)
            ->where('user_id', Auth::id())
            ->whereNull('typing_result_id')
            ->first();

        if (! $claim) {
            return null;
        }

        return $this->redirect(route('typing', ['war_claim' => $claim->id]));
    }

    /**
     * Hand back a RESERVED claim (the mode reopens); the claimer themselves or a leader.
     *
     * Only a claim whose attempt was never opened. An opened attempt is spent whatever it
     * produced, because the alternative is a third way to restart: cancel, re-claim, play
     * again -- and in Words mode the text is frozen and identical for everyone on that config,
     * so that is unlimited practice on a paper the player has already read.
     *
     * The cost is real and deliberate: a member who opens an attempt and vanishes leaves a
     * dead slot worth 0. ClanWarResolver stops that trapping the opposing clan, but the slot
     * itself is not recoverable.
     */
    public function cancelClaim(int $claimId): void
    {
        if (! $this->myClan) {
            return;
        }

        $claim = ClanWarModeClaim::where('id', $claimId)
            ->where('clan_id', $this->myClan->id)
            ->whereNull('typing_result_id')
            ->whereNull('attempt_started_at')
            ->first();

        if (! $claim) {
            return;
        }

        // The claimer, or anyone with roster powers, may free a slot sitting unplayed.
        if ($claim->user_id !== Auth::id() && ! $this->canManageMembers) {
            return;
        }

        $claim->delete();

        // The slot is open again -- a teammate staring at a grey "claimed" tile would otherwise
        // keep seeing it until they reloaded. myActiveWar is already memoised from the guards
        // above, so this costs no extra query (unlike $claim->war).
        ClanWarBroadcast::refresh($this->myActiveWar);
    }

    public function challengeClan(int $opponentClanId): void
    {
        if (! $this->isLeader || $this->myActiveWar) {
            return;
        }

        // populated(): re-checked HERE, not just filtered out of the list above. Hiding an
        // empty clan from the picker is presentation; without this gate the id could still
        // be posted directly, and an unplayable clan is a free 3-day walkover.
        $opponent = Clan::populated()->find($opponentClanId);

        // Server-side re-validation: don't trust the list shown on the client.
        if (! $opponent || $opponent->id === $this->myClan->id || $opponent->activeWar() !== null) {
            return;
        }

        $war = ClanWarModel::create([
            'challenger_clan_id' => $this->myClan->id,
            'opponent_clan_id' => $opponent->id,
            'status' => ClanWarStatus::Pending,
            'accept_deadline_at' => now()->addHours(self::ACCEPT_WINDOW_HOURS),
        ]);

        $this->notify($opponent->leader_id, [
            'type' => 'war-challenge',
            'message' => __('clan.notify.war_challenged', ['clan' => $this->myClan->name]),
        ]);

        // Our own members' pages just gained a "waiting for a response" panel, and the opposing
        // clan's members gained an incoming challenge. The opposing LEADER is excluded because
        // the toast above already carries them through the same refresh path in toasts.js.
        ClanWarBroadcast::refresh($war, exceptUserIds: [$opponent->leader_id]);
    }

    public function acceptChallenge(int $warId): void
    {
        $war = $this->pendingChallengeForMyLeadership($warId);
        if (! $war) {
            return;
        }

        $war->update([
            'status' => ClanWarStatus::Ongoing,
            'challenger_power_before' => $war->challenger->power,
            'opponent_power_before' => $war->opponent->power,
            // Claim caps are SNAPSHOTTED here, alongside the power figures and for the same
            // reason: both describe the war as agreed, and neither may move while it runs.
            // Recomputing the cap live would let a clan kick members mid-war to raise its own
            // cap and pile every slot onto one account -- the concentration the cap prevents.
            'challenger_max_claims' => ClanWarModeCatalog::claimCapFor($war->challenger->activeMembers()->count()),
            'opponent_max_claims' => ClanWarModeCatalog::claimCapFor($war->opponent->activeMembers()->count()),
            'started_at' => now(),
            'ends_at' => now()->addDays(self::WAR_DURATION_DAYS),
        ]);

        $this->notify($war->challenger->leader_id, [
            'type' => 'war-accepted',
            'message' => __('clan.notify.war_accepted', ['clan' => $war->opponent->name]),
        ]);

        // The leader is not the only person staring at "waiting for a response": every ordinary
        // member of BOTH clans is on a page whose entire branch just changed (waiting -> ongoing
        // on one side, incoming challenge -> ongoing on the other).
        ClanWarBroadcast::refresh($war, exceptUserIds: [$war->challenger->leader_id]);
    }

    public function declineChallenge(int $warId): void
    {
        $war = $this->pendingChallengeForMyLeadership($warId);
        if (! $war) {
            return;
        }

        $war->update(['status' => ClanWarStatus::Declined]);

        $this->notify($war->challenger->leader_id, [
            'type' => 'war-declined',
            'message' => __('clan.notify.war_declined', ['clan' => $war->opponent->name]),
        ]);

        // Same defect class as accept: without this, the challenger clan's ordinary members keep
        // the "waiting for a response" panel for a war that no longer exists.
        ClanWarBroadcast::refresh($war, exceptUserIds: [$war->challenger->leader_id]);
    }

    /** A Pending war where our clan is the opponent and we're its leader. */
    private function pendingChallengeForMyLeadership(int $warId): ?ClanWarModel
    {
        if (! $this->isLeader || ! $this->myClan) {
            return null;
        }

        return ClanWarModel::with(['challenger', 'opponent'])
            ->where('id', $warId)
            ->where('opponent_clan_id', $this->myClan->id)
            ->where('status', ClanWarStatus::Pending)
            ->first();
    }

    private function notify(int $otherUserId, ?array $notification = null): void
    {
        SafeBroadcast::run(fn () => broadcast(new ClanUpdated($otherUserId, $notification)));
    }

    public function render()
    {
        return view('livewire.clan-war')->layout('layouts.app');
    }
}
