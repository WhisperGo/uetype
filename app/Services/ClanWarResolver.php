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
use Illuminate\Support\Facades\DB;

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

    /**
     * Score and close Ongoing wars that are due, updating both clans' power.
     *
     * KNOWN SCALING LIMIT, recorded so it is found by decision rather than by a slow page.
     * resolveDue() runs from ClanWar::mount(), so this sweeps EVERY ongoing war in the system
     * each time any player opens /clan-war -- including wars they have nothing to do with. A
     * war already past ends_at costs nothing extra (the short-circuit below skips the checks),
     * but one still running costs bothClansHaveNothingLeftToPlay(): a claims query per side,
     * plus a roster query when a side looks finished. So the work per page load grows linearly
     * with the number of wars running anywhere.
     *
     * Fine at this project's scale -- a handful of concurrent wars is a handful of queries --
     * and lazy resolution is deliberate (no scheduler; same pattern as the room sweep). If the
     * count ever grows, the fix is to narrow this loop to the caller's own war and let the
     * existing `clan-war:resolve` command carry the rest, rather than to abandon lazy
     * resolution wholesale.
     */
    private function resolveFinishedWars(): void
    {
        // Close Ongoing wars that are past their time or finished early.
        $ongoing = ClanWar::where('status', ClanWarStatus::Ongoing)->get();

        foreach ($ongoing as $war) {
            if (! $war->ends_at?->isPast() && ! $this->bothClansHaveNothingLeftToPlay($war)) {
                continue;
            }

            $this->settleWar($war);
        }
    }

    /**
     * Score ONE due war and apply its Elo. Returns true only for the caller that settled it.
     *
     * Public because "settle this war" is the unit of resolution, and because the only way to
     * prove the guard below works is to hand it a war a competing caller has already closed.
     *
     * WHY THE CONDITIONAL UPDATE COMES FIRST. resolveDue() runs from ClanWar::mount(), so every
     * member opening /clan-war triggers it -- and the moment two of them are most likely to do
     * that at once is exactly when their war has just ended. This used to write Finished AFTER
     * increment('power'), which meant the status was not a gate at all: two requests both
     * reading Ongoing both applied the delta, and each clan's power moved twice.
     *
     * increment() is atomic per column, but that never protected against running the whole
     * settlement twice. The damage is permanent because clans.power is never recomputed from
     * war history -- once it has drifted there is nothing left to correct it from.
     *
     * Same shape as MultiplayerLobby::closeRaceNow() and FinalizesRace::startSuddenDeathIfNeeded():
     * of any number of concurrent callers, exactly one gets affected-rows = 1 and does the work.
     * Inside a transaction so the claim and what it authorises commit together -- a crash between
     * them would leave a war marked Finished that no retry could ever reach again.
     */
    public function settleWar(ClanWar $war): bool
    {
        // A submitted probation result reserves its slot with zero points. Give the automatic
        // integrity resolver one final deterministic pass before the war snapshot is claimed;
        // anything still uncorroborated remains zero and can never mutate a finished war later.
        app(AutomaticResultResolver::class)->reconcileWar($war->id);

        $settled = DB::transaction(function () use ($war) {
            $claimed = ClanWar::where('id', $war->id)
                ->where('status', ClanWarStatus::Ongoing)
                ->update(['status' => ClanWarStatus::Finished]);

            if (! $claimed) {
                return null;
            }

            return $this->applySettlement($war);
        });

        if ($settled === null) {
            return false;
        }

        // Outside the transaction: broadcasting is a side effect that shouldn't hold the row
        // locks the settlement above took on both clans.
        $this->notifyResult($war, $settled['result'], $settled['challenger'], $settled['opponent']);

        return true;
    }

    /**
     * Compute the outcome and move both clans' power. Only ever reached by the caller that won
     * the claim above, and only from inside its transaction.
     *
     * @return array{result: string, challenger: int, opponent: int}
     */
    private function applySettlement(ClanWar $war): array
    {
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

        // Null-safe because clan_wars keeps finished rows after a clan disbands (the FK is
        // nullOnDelete). disbandClan() refuses to run mid-war so this should be unreachable,
        // but the war is already claimed by the time we get here: crashing now would leave it
        // Finished with no result written and no way back in.
        $war->challenger?->increment('power', $deltaChallenger);
        $war->opponent?->increment('power', $deltaOpponent);

        $war->update([
            'result' => $result,
            'challenger_power_delta' => $deltaChallenger,
            'opponent_power_delta' => $deltaOpponent,
        ]);

        return ['result' => $result, 'challenger' => $deltaChallenger, 'opponent' => $deltaOpponent];
    }

    /**
     * Notify both leaders of the result via real-time toast (each side's view flipped).
     *
     * These three sentences were the only hardcoded Indonesian left in shipped code, which meant
     * an English player was told 'Clan-mu MENANG Clan War' -- the one clan message that never
     * went through the translation files it belongs in.
     *
     * KNOWN LIMITATION, shared with every other clan notification: __() renders in the locale of
     * whoever's page load happened to trigger resolveDue(), not the recipient's. Clans::notify()
     * has always worked this way, so fixing it here alone would make the two disagree; it needs
     * one change across all of them (resolve each recipient's preferences['locale'] and render
     * per addressee), which is a bigger job than this finding.
     */
    private function notifyResult(ClanWar $war, string $result, int $deltaChallenger, int $deltaOpponent): void
    {
        $label = fn (string $r) => match ($r) {
            'win' => __('clan.notify.war_won'),
            'loss' => __('clan.notify.war_lost'),
            default => __('clan.notify.war_drawn'),
        };

        $opponentResult = match ($result) {
            'win' => 'loss',
            'loss' => 'win',
            default => 'draw',
        };

        // Null-safe for the same reason applySettlement() is: a finished war outlives a clan
        // that later disbands, and the name falls back to the snapshot taken at creation.
        $challengerLeaderId = $war->challenger?->leader_id;
        $opponentLeaderId = $war->opponent?->leader_id;

        if ($challengerLeaderId) {
            SafeBroadcast::run(fn () => broadcast(new ClanUpdated($challengerLeaderId, [
                'type' => 'war-result',
                'message' => $label($result).' vs '.$war->opponentNameFor($war->challenger_clan_id)
                    .' ('.$this->signed($deltaChallenger).' power)',
            ])));
        }

        if ($opponentLeaderId) {
            SafeBroadcast::run(fn () => broadcast(new ClanUpdated($opponentLeaderId, [
                'type' => 'war-result',
                'message' => $label($opponentResult).' vs '.$war->opponentNameFor($war->opponent_clan_id)
                    .' ('.$this->signed($deltaOpponent).' power)',
            ])));
        }
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

        // mode/mode_config come along because the expiry check below reads them: a slot's clock
        // is defined by which slot it is.
        $claims = ClanWarModeClaim::where('clan_war_id', $war->id)
            ->where('clan_id', $clanId)
            ->get(['user_id', 'typing_result_id', 'attempt_started_at', 'mode', 'mode_config', 'attempt_grace_used']);

        if ($claims->whereNotNull('typing_result_id')->count() >= $target) {
            return true;
        }

        $clock = app(ClanWarAttempt::class);
        $staleBefore = now()->subMinutes(ClanWarAttempt::STALE_MINUTES);

        // An unsubmitted claim is only PENDING while it could still turn into something.
        //
        // The staleness clock stays, but it is now the fallback rather than the whole test.
        // It is a 15-minute proxy for "this is never coming", and for a `time 60` slot anchored
        // three minutes ago the real answer is already available and exact -- waiting out the
        // proxy just held the war open past the point of any doubt. `words` has no clock of its
        // own, so there STALE_MINUTES is still the only thing that can answer.
        $hasPending = $claims->contains(fn (ClanWarModeClaim $c) => $c->typing_result_id === null
            && ! $clock->isClaimExpired($c)
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
