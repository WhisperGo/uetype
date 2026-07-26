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
use App\Support\SafeBroadcast;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
     * Slots one member may claim in a single war, out of the 9 available.
     *
     * A war is meant to be a clan effort. Without a cap, one account can claim every slot
     * and decide the outcome alone -- which also means a single cheating or compromised
     * member is enough to win, and the damage lands on the opposing clan.
     */
    public const MAX_CLAIMS_PER_MEMBER = 4;

    /** Close overdue wars (expire Pending, resolve Ongoing) before computed properties read data. */
    public function mount(ClanWarResolver $resolver): void
    {
        $resolver->resolveDue();
    }

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
     * status ('open' | 'claimed' | 'done') plus the claim row if any.
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
                $status = $claim->isSubmitted() ? 'done' : 'claimed';
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

    // ---- ACTIONS ----

    /**
     * Claim one of the 9 modes, then redirect to the typing engine with the mode locked.
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
            $claim = DB::transaction(function () use ($war, $mode, $config) {
                // First check this clan hasn't already claimed this slot.
                $existing = ClanWarModeClaim::where('clan_war_id', $war->id)
                    ->where('clan_id', $this->myClan->id)
                    ->where('mode', $mode)
                    ->where('mode_config', $config)
                    ->first();

                if ($existing) {
                    return null;
                }

                // One member may not hold every slot. A war is meant to be a clan effort,
                // and concentrating all 9 slots in one account makes a single compromised
                // or cheating player able to decide the whole war by themselves.
                $mine = ClanWarModeClaim::where('clan_war_id', $war->id)
                    ->where('clan_id', $this->myClan->id)
                    ->where('user_id', Auth::id())
                    ->count();

                if ($mine >= self::MAX_CLAIMS_PER_MEMBER) {
                    return null;
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
            // Unique violation: another member just took this slot.
            $claim = null;
        }

        if (! $claim) {
            session()->flash('clan_war_claim_error', __('clan.error.mode_taken'));

            return;
        }

        $this->redirect(route('typing', ['war_claim' => $claim->id]), navigate: true);
    }

    /** Cancel an unsubmitted claim (the mode reopens); the claimer themselves or a leader. */
    public function cancelClaim(int $claimId): void
    {
        if (! $this->myClan) {
            return;
        }

        $claim = ClanWarModeClaim::where('id', $claimId)
            ->where('clan_id', $this->myClan->id)
            ->whereNull('typing_result_id')
            ->first();

        if (! $claim) {
            return;
        }

        // The claimer, or anyone with roster powers, may free a slot sitting unplayed.
        if ($claim->user_id !== Auth::id() && ! $this->canManageMembers) {
            return;
        }

        $claim->delete();
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
            'started_at' => now(),
            'ends_at' => now()->addDays(self::WAR_DURATION_DAYS),
        ]);

        $this->notify($war->challenger->leader_id, [
            'type' => 'war-accepted',
            'message' => __('clan.notify.war_accepted', ['clan' => $war->opponent->name]),
        ]);
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
