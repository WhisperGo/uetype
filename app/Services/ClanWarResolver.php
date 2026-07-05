<?php

namespace App\Services;

use App\Enums\ClanWarStatus;
use App\Models\Clan;
use App\Models\ClanWar;
use App\Models\TypingResult;
use Carbon\CarbonInterface;

/**
 * Menutup Clan War yang sudah waktunya diselesaikan: tantangan Pending yang
 * lewat batas accept 1 jam jadi Expired, dan war Ongoing yang sudah lewat
 * ends_at (3 hari) dihitung hasilnya (menang/seri/kalah dari akumulasi
 * xp_earned) lalu power kedua clan diupdate lewat EloCalculator.
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
            $xpChallenger = $this->clanXp($war->challenger_clan_id, $war->started_at, $war->ends_at);
            $xpOpponent = $this->clanXp($war->opponent_clan_id, $war->started_at, $war->ends_at);

            if ($xpChallenger > $xpOpponent) {
                $scoreChallenger = 1.0;
                $result = 'win';
            } elseif ($xpChallenger < $xpOpponent) {
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

    private function clanXp(int $clanId, CarbonInterface $start, CarbonInterface $end): int
    {
        $memberIds = Clan::find($clanId)->activeMembers()->pluck('user_id');

        return (int) TypingResult::whereIn('user_id', $memberIds)
            ->whereBetween('created_at', [$start, $end])
            ->sum('xp_earned');
    }
}
