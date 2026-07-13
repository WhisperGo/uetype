<?php

namespace App\Services;

use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Models\ClanWar;
use App\Models\ClanWarModeClaim;
use App\Support\SafeBroadcast;

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
            if (! $war->ends_at?->isPast() && ! $this->bothClansFinishedAllModes($war)) {
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

    /** True once both clans submitted all 9 modes, allowing an early finish before ends_at. */
    private function bothClansFinishedAllModes(ClanWar $war): bool
    {
        $target = count(ClanWarModeCatalog::MODES);

        return $this->submittedCount($war->id, $war->challenger_clan_id) >= $target
            && $this->submittedCount($war->id, $war->opponent_clan_id) >= $target;
    }

    /** How many modes a clan has actually submitted (played) in a war. */
    private function submittedCount(int $clanWarId, int $clanId): int
    {
        return ClanWarModeClaim::where('clan_war_id', $clanWarId)
            ->where('clan_id', $clanId)
            ->whereNotNull('typing_result_id')
            ->count();
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
