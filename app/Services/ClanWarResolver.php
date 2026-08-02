<?php

namespace App\Services;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Models\ClanMember;
use App\Models\ClanWar;
use App\Models\ClanWarModeClaim;
use App\Support\SafeBroadcast;
use Illuminate\Support\Collection;

/**
 * Settles due Clan Wars: expires unaccepted challenges and scores finished wars
 * (via ends_at or early finish), updating power through EloCalculator. Called
 * on-the-fly from ClanWar::mount() (no scheduler); also the `clan-war:resolve` command.
 */
class ClanWarResolver
{
    /** Expire unaccepted challenges and settle any finished wars. */
    public function resolveDue(): void
    {
        $this->expirePendingChallenges();
        $this->resolveFinishedWars();
    }

    /** Mark Pending challenges past their accept deadline as Expired. */
    private function expirePendingChallenges(): void
    {
        ClanWar::where('status', ClanWarStatus::Pending)
            ->where('accept_deadline_at', '<=', now())
            ->update(['status' => ClanWarStatus::Expired]);
    }

    /** Score and close Ongoing wars that are due, updating both clans' power. */
    private function resolveFinishedWars(): void
    {
        // Close Ongoing wars that are past their time or finished early.
        $ongoing = ClanWar::where('status', ClanWarStatus::Ongoing)->get();

        foreach ($ongoing as $war) {
            if (! $war->ends_at?->isPast() && ! $this->bothClansHaveNothingLeftToPlay($war)) {
                continue;
            }

            $pointsChallenger = $this->clanWarPoints($war->id, $war->challenger_clan_id);
            $pointsOpponent = $this->clanWarPoints($war->id, $war->opponent_clan_id);

            if ($pointsChallenger > $pointsOpponent) {
                $scoreChallenger = 1.0;
                $result = 'win';
            } elseif ($pointsChallenger < $pointsOpponent) {
                $scoreChallenger = 0.0;
                $result = 'loss';
            } else {
                $scoreChallenger = 0.5;
                $result = 'draw';
            }

            [$deltaChallenger, $deltaOpponent] = EloCalculator::calculate(
                $war->challenger_power_before,
                $war->opponent_power_before,
                $scoreChallenger
            );

            $war->challenger->increment('power', $deltaChallenger);
            $war->opponent->increment('power', $deltaOpponent);

            $war->update([
                'status' => ClanWarStatus::Finished,
                'result' => $result,
                'challenger_power_delta' => $deltaChallenger,
                'opponent_power_delta' => $deltaOpponent,
            ]);

            $this->notifyResult($war, $result, $deltaChallenger, $deltaOpponent);
        }
    }

    /** Notify both leaders of the result via real-time toast (each side's view flipped). */
    private function notifyResult(ClanWar $war, string $result, int $deltaChallenger, int $deltaOpponent): void
    {
        $label = fn (string $r) => match ($r) {
            'win' => 'Clan-mu MENANG Clan War',
            'loss' => 'Clan-mu KALAH Clan War',
            default => 'Clan War berakhir SERI',
        };

        $opponentResult = match ($result) {
            'win' => 'loss',
            'loss' => 'win',
            default => 'draw',
        };

        SafeBroadcast::run(fn () => broadcast(new ClanUpdated($war->challenger->leader_id, [
            'type' => 'war-result',
            'message' => $label($result).' vs '.$war->opponent->name.' ('.$this->signed($deltaChallenger).' power)',
        ])));

        SafeBroadcast::run(fn () => broadcast(new ClanUpdated($war->opponent->leader_id, [
            'type' => 'war-result',
            'message' => $label($opponentResult).' vs '.$war->challenger->name.' ('.$this->signed($deltaOpponent).' power)',
        ])));
    }

    /** Format a delta with an explicit +/- sign. */
    private function signed(int $n): string
    {
        return ($n >= 0 ? '+' : '').$n;
    }

    /** True once NEITHER clan has anything left to play, allowing an early finish before ends_at. */
    private function bothClansHaveNothingLeftToPlay(ClanWar $war): bool
    {
        return $this->clanHasNothingLeftToPlay($war, $war->challenger_clan_id)
            && $this->clanHasNothingLeftToPlay($war, $war->opponent_clan_id);
    }

    /**
     * Has this clan finished everything it is CAPABLE of finishing?
     *
     * This used to be "9 of 9 submitted", which quietly held wars open forever. A clan that
     * cannot reach 9 -- because its roster shrank, or because a member opened an attempt and
     * abandoned it -- never satisfied it, so the OPPOSING clan was kept waiting out the full
     * three days having done everything right. The penalty landed on the side at no fault.
     *
     * A lazy clan still cannot trigger an early finish, because idleness always reads as
     * PENDING: an unclaimed slot is pending forever, and an open attempt is pending until it
     * goes stale. Only a clan that literally cannot claim or play anything more counts as done.
     */
    private function clanHasNothingLeftToPlay(ClanWar $war, int $clanId): bool
    {
        $target = count(ClanWarModeCatalog::MODES);

        $claims = ClanWarModeClaim::where('clan_war_id', $war->id)
            ->where('clan_id', $clanId)
            ->get(['user_id', 'typing_result_id', 'attempt_started_at']);

        if ($claims->whereNotNull('typing_result_id')->count() >= $target) {
            return true;
        }

        $staleBefore = now()->subMinutes(ClanWarAttempt::STALE_MINUTES);

        $hasPending = $claims->contains(fn (ClanWarModeClaim $c) => $c->typing_result_id === null
            && ($c->attempt_started_at === null || $c->attempt_started_at->gt($staleBefore)));

        if ($hasPending) {
            return false;
        }

        // Every slot is spoken for; the unsubmitted ones are dead attempts worth 0.
        if ($claims->count() >= $target) {
            return true;
        }

        return $this->remainingClaimQuota($war, $clanId, $claims) === 0;
    }

    /**
     * How many more slots this clan's members could still claim between them.
     *
     * Zero means the grid is unreachable for them -- the honest definition of "finished
     * everything it is able to". Uses the war's SNAPSHOT cap, so a clan cannot shrink its own
     * roster to reach zero faster than the members it dropped would have scored.
     *
     * @param  Collection<int, ClanWarModeClaim>  $claims
     */
    private function remainingClaimQuota(ClanWar $war, int $clanId, $claims): int
    {
        $cap = $war->maxClaimsFor($clanId);
        $held = $claims->countBy('user_id');

        return ClanMember::query()
            ->where('clan_id', $clanId)
            ->where('status', ClanMemberStatus::Active)
            ->pluck('user_id')
            ->sum(fn (int $userId) => max(0, $cap - ($held[$userId] ?? 0)));
    }

    /** A clan's total war points: sum of submitted claims' points (locked-but-unplayed = 0). */
    private function clanWarPoints(int $clanWarId, int $clanId): float
    {
        return (float) ClanWarModeClaim::where('clan_war_id', $clanWarId)
            ->where('clan_id', $clanId)
            ->whereNotNull('typing_result_id')
            ->sum('points');
    }
}
