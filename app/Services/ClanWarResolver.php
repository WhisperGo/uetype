<?php

namespace App\Services;

use App\Enums\ClanWarStatus;
use App\Models\ClanWar;
use App\Models\ClanWarModeClaim;

/**
 * Menutup Clan War yang sudah waktunya diselesaikan: tantangan Pending yang
 * lewat batas accept 1 jam jadi Expired, dan war Ongoing yang sudah lewat
 * ends_at (3 hari) dihitung hasilnya (menang/seri/kalah dari akumulasi POIN
 * mode-klaim kedua clan) lalu power kedua clan diupdate lewat EloCalculator.
 *
 * Dipanggil on-the-fly dari App\Livewire\ClanWar::mount() -- war siapa pun
 * yang sudah lewat waktu otomatis tertutup begitu ada yang membuka halaman
 * Clan War, tanpa perlu command/scheduler terjadwal. Command
 * `clan-war:resolve` memanggil method yang sama untuk pemakaian manual.
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
        $due = ClanWar::where('status', ClanWarStatus::Ongoing)
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($due as $war) {
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
        }
    }

    /**
     * Total poin war sebuah clan: jumlah `points` dari klaim mode yang SUDAH
     * disubmit (typing_result_id terisi). Klaim yang cuma terkunci tapi tak
     * pernah dikerjakan sampai war berakhir bernilai 0 (tak terhitung).
     */
    private function clanWarPoints(int $clanWarId, int $clanId): float
    {
        return (float) ClanWarModeClaim::where('clan_war_id', $clanWarId)
            ->where('clan_id', $clanId)
            ->whereNotNull('typing_result_id')
            ->sum('points');
    }
}
