<?php

namespace App\Services;

use App\Models\TypingResult;
use App\Models\User;
use App\Models\UserAchievement;
use App\Support\AchievementDefinitions;
use Illuminate\Support\Carbon;

/**
 * Evaluates achievements lazily: computed from existing data (typing_results +
 * users) when a page loads, not a continuously running rule engine. Definitions
 * live in code (AchievementDefinitions); the user_achievements table only records
 * the first unlock (who earned what, and when).
 */
class AchievementService
{
    /**
     * Compute every metric the rules need in one cheap query pass.
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
     * List all achievements with earned status + unlock date, recording new unlocks.
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

        // Existing unlock records, for the "unlocked at" date.
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

            // Newly met and never recorded: log the unlock now.
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
