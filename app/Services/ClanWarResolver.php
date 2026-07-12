<?php

namespace App\Services;

use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Models\ClanWar;
use App\Models\ClanWarModeClaim;
use App\Support\SafeBroadcast;

/**
 * Settles Clan Wars whose time has come: Pending challenges past their accept
 * deadline become Expired, and Ongoing wars that are done (via ends_at, or an
 * early finish once both clans complete all 9 modes) are scored from accumulated
 * mode-claim points and power is updated through EloCalculator.
 *
 * Called on-the-fly from App\Livewire\ClanWar::mount(), so no scheduler is needed;
 * the `clan-war:resolve` command invokes the same method for manual use.
 */
class ClanWarResolver
{
    public function resolveDue(): void
    {
        $this->expirePendingChallenges();
        $this->resolveFinishedWars();
    }

    private function expirePendingChallenges(): void
    {
        ClanWar::where('status', ClanWarStatus::Pending)
            ->where('accept_deadline_at', '<=', now())
            ->update(['status' => ClanWarStatus::Expired]);
    }

    private function resolveFinishedWars(): void
    {
        // Tutup war Ongoing yang sudah lewat waktu atau early finish.
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

    /** Beri tahu kedua leader hasil war lewat toast real-time; sudut pandang tiap sisi dibalik dengan benar. */
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

    private function signed(int $n): string
    {
        return ($n >= 0 ? '+' : '').$n;
    }

    /** War beres lebih cepat kalau kedua clan sudah submit seluruh 9 mode; tak perlu menunggu ends_at. */
    private function bothClansFinishedAllModes(ClanWar $war): bool
    {
        $target = count(ClanWarModeCatalog::MODES);

        return $this->submittedCount($war->id, $war->challenger_clan_id) >= $target
            && $this->submittedCount($war->id, $war->opponent_clan_id) >= $target;
    }

    private function submittedCount(int $clanWarId, int $clanId): int
    {
        return ClanWarModeClaim::where('clan_war_id', $clanWarId)
            ->where('clan_id', $clanId)
            ->whereNotNull('typing_result_id')
            ->count();
    }

    /** Total poin war: jumlah `points` klaim mode yang sudah disubmit; klaim terkunci tapi belum dikerjakan bernilai 0. */
    private function clanWarPoints(int $clanWarId, int $clanId): float
    {
        return (float) ClanWarModeClaim::where('clan_war_id', $clanWarId)
            ->where('clan_id', $clanId)
            ->whereNotNull('typing_result_id')
            ->sum('points');
    }
}
