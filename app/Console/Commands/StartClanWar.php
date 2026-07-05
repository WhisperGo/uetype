<?php

namespace App\Console\Commands;

use App\Enums\ClanWarStatus;
use App\Models\Clan;
use App\Models\ClanWar;
use App\Models\ClanWarParticipant;
use App\Models\TypingResult;
use Illuminate\Console\Command;

class StartClanWar extends Command
{
    protected $signature = 'clan-war:start';

    protected $description = 'Tutup clan war yang sudah lewat waktunya, lalu mulai clan war baru berdurasi 1 minggu untuk semua clan yang ada.';

    public function handle(): int
    {
        $this->closeExpiredWars();
        $this->startNewWar();

        return self::SUCCESS;
    }

    private function closeExpiredWars(): void
    {
        $expired = ClanWar::where('status', ClanWarStatus::Ongoing)
            ->where('ends_at', '<=', now())
            ->get();

        foreach ($expired as $war) {
            $totals = ClanWarParticipant::where('clan_war_id', $war->id)->get()
                ->map(function (ClanWarParticipant $participant) use ($war) {
                    $memberIds = $participant->clan->activeMembers()->pluck('user_id');

                    $total = TypingResult::whereIn('user_id', $memberIds)
                        ->whereBetween('created_at', [$war->starts_at, $war->ends_at])
                        ->sum('xp_earned');

                    return [$participant, (int) $total];
                })
                ->sortByDesc(fn ($pair) => $pair[1])
                ->values();

            foreach ($totals as $placement => [$participant, $total]) {
                $participant->update([
                    'total_contribution' => $total,
                    'placement' => $placement + 1,
                ]);
            }

            $war->update(['status' => ClanWarStatus::Finished]);

            $this->info("War #{$war->id} ditutup ({$totals->count()} clan).");
        }
    }

    private function startNewWar(): void
    {
        $war = ClanWar::create([
            'starts_at' => now(),
            'ends_at' => now()->addWeek(),
            'status' => ClanWarStatus::Ongoing,
        ]);

        Clan::all()->each(function (Clan $clan) use ($war) {
            ClanWarParticipant::create([
                'clan_war_id' => $war->id,
                'clan_id' => $clan->id,
                'total_contribution' => 0,
            ]);
        });

        $this->info("War #{$war->id} dimulai, berakhir {$war->ends_at->toDateTimeString()}.");
    }
}
