<?php

namespace App\Services;

use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Models\ClanWar;
use App\Models\ClanWarModeClaim;
use App\Support\SafeBroadcast;

/**
 * Menutup Clan War yang sudah waktunya diselesaikan: tantangan Pending yang
 * lewat batas accept 1 jam jadi Expired, dan war Ongoing yang SELESAI --
 * baik karena lewat ends_at (3 hari) MAUPUN karena kedua clan sudah
 * menyelesaikan seluruh 9 mode lebih cepat (early finish). Hasilnya dihitung
 * dari akumulasi POIN mode-klaim kedua clan lalu power diupdate lewat
 * EloCalculator.
 *
 * Dipanggil on-the-fly dari App\Livewire\ClanWar::mount() -- war yang sudah
 * selesai otomatis tertutup begitu ada yang membuka halaman Clan War, tanpa
 * perlu command/scheduler terjadwal. Command `clan-war:resolve` memanggil
 * method yang sama untuk pemakaian manual.
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
        // Ambil SEMUA war Ongoing, lalu tutup yang sudah lewat waktu ATAU yang
        // kedua clannya sudah menyelesaikan seluruh 9 mode (early finish).
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

    /**
     * Beri tahu KEDUA leader hasil war lewat toast real-time (channel
     * clan.{leaderId}) begitu war ditutup -- entah karena waktu habis maupun
     * early finish. Sudut pandang masing-masing dibalik dengan benar
     * (menang challenger = kalah opponent).
     */
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

    /**
     * War dianggap "beres lebih cepat" kalau KEDUA clan sudah menyelesaikan
     * (submit) seluruh 9 mode -- tak ada lagi yang bisa dikerjakan, jadi tak
     * perlu menunggu ends_at. Karena tiap klaim tersubmit itu unik per
     * (mode, config) untuk clan, cukup hitung jumlah klaim tersubmit = 9.
     */
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
