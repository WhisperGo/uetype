<?php

namespace App\Services;

use App\Models\TypingResult;
use App\Models\User;
use App\Models\UserAchievement;
use App\Support\AchievementDefinitions;
use Illuminate\Support\Carbon;

/**
 * Evaluasi achievement statis: dihitung dari data yang sudah ada (typing_results +
 * users) saat halaman dibuka, bukan rule engine berjalan terus. Definisi tetap di
 * kode (AchievementDefinitions); tabel user_achievements hanya mencatat unlock
 * pertama (siapa meraih apa & kapan).
 */
class AchievementService
{
    /**
     * Hitung metrik yang dibutuhkan semua aturan, dalam SATU lintasan query murah.
     *
     * @return array{highest_wpm:float, level:int, total_tests:int, total_chars:int, perfect_runs:int}
     */
    public function computeStats(User $user): array
    {
        $base = TypingResult::where('user_id', $user->id);

        return [
            'highest_wpm' => (float) ($user->highest_wpm ?? 0),
            'level' => $user->levelData()['level'],
            'total_tests' => (clone $base)->count(),
            'total_chars' => (int) (clone $base)->sum('correct_chars'),
            'perfect_runs' => (clone $base)->where('accuracy', '>=', 100)->count(),
        ];
    }

    /**
     * Kembalikan daftar achievement lengkap dengan status earned + tanggal unlock.
     * Sekaligus mencatat unlock baru (Pendekatan B) untuk yang baru terpenuhi.
     *
     * @return array{
     *   items: array<int, array{key:string,title:string,description:string,category:string,icon_value:string,icon_unit:string,earned:bool,unlocked_at:?Carbon}>,
     *   earned_count: int,
     *   total: int,
     *   newly_unlocked: array<int, string>
     * }
     */
    public function evaluate(User $user): array
    {
        $stats = $this->computeStats($user);
        $definitions = AchievementDefinitions::all();

        // Catatan unlock yang sudah ada, untuk tanggal "diraih pada".
        $existing = UserAchievement::where('user_id', $user->id)
            ->get()
            ->keyBy('achievement_key');

        $items = [];
        $earnedCount = 0;
        $newlyUnlocked = [];
        $now = now();

        foreach ($definitions as $def) {
            $earned = (bool) ($def['check'])($stats);
            $record = $existing->get($def['key']);
            $unlockedAt = $record?->unlocked_at;

            // Baru terpenuhi & belum pernah dicatat: catat unlock sekarang.
            if ($earned && ! $record) {
                UserAchievement::create([
                    'user_id' => $user->id,
                    'achievement_key' => $def['key'],
                    'unlocked_at' => $now,
                ]);
                $unlockedAt = $now;
                $newlyUnlocked[] = $def['key'];
            }

            if ($earned) {
                $earnedCount++;
            }

            $items[] = [
                'key' => $def['key'],
                'title' => $def['title'],
                'description' => $def['description'],
                'category' => $def['category'],
                'icon_value' => $def['icon_value'],
                'icon_unit' => $def['icon_unit'],
                'earned' => $earned,
                'unlocked_at' => $earned ? $unlockedAt : null,
            ];
        }

        return [
            'items' => $items,
            'earned_count' => $earnedCount,
            'total' => count($definitions),
            'newly_unlocked' => $newlyUnlocked,
        ];
    }
}
