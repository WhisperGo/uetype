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
     * All three aggregates are pulled in ONE query (previously one query per figure,
     * over the exact same table and filter).
     *
     * @return array{highest_wpm:float, level:int, total_tests:int, total_chars:int, perfect_runs:int}
     */
    public function computeStats(User $user): array
    {
        $agg = TypingResult::where('user_id', $user->id)
            ->selectRaw('
                COUNT(*) as total_tests,
                COALESCE(SUM(correct_chars), 0) as total_chars,
                COALESCE(SUM(CASE WHEN accuracy >= 100 THEN 1 ELSE 0 END), 0) as perfect_runs
            ')
            ->first();

        return [
            'highest_wpm' => (float) ($user->highest_wpm ?? 0),
            'level' => $user->levelData()['level'],
            'total_tests' => (int) $agg->total_tests,
            'total_chars' => (int) $agg->total_chars,
            'perfect_runs' => (int) $agg->perfect_runs,
        ];
    }

    /**
     * List all achievements with earned status + unlock date. READ-ONLY.
     *
     * This method used to also WRITE (UserAchievement::create) mid page render.
     * Two consequences: (1) a GET request gained a write side effect, and since
     * Livewire re-renders on every small interaction, that write path ran many
     * times; (2) new achievements "unlocked" only when the page happened to be
     * opened -- players who never visited /stats or /achievements were never recorded.
     *
     * Recording now happens at the point the accomplishment actually occurs, via
     * syncUnlocks() (called after a typing/race result is saved).
     *
     * @return array{
     *   items: array<int, array{key:string,title:string,description:string,category:string,icon_value:string,icon_unit:string,earned:bool,unlocked_at:?Carbon}>,
     *   earned_count: int,
     *   total: int
     * }
     */
    public function evaluate(User $user): array
    {
        $stats = $this->computeStats($user);
        $definitions = AchievementDefinitions::all();

        // Existing unlock records, used for the "unlocked at" date.
        $existing = UserAchievement::where('user_id', $user->id)
            ->get()
            ->keyBy('achievement_key');

        $items = [];
        $earnedCount = 0;

        foreach ($definitions as $def) {
            $earned = (bool) ($def['check'])($stats);

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
                // null if the condition is met but not yet recorded (e.g. achieved
                // before this change). The display stays correct; syncUnlocks() will
                // record it on the next session.
                'unlocked_at' => $earned ? $existing->get($def['key'])?->unlocked_at : null,
            ];
        }

        return [
            'items' => $items,
            'earned_count' => $earnedCount,
            'total' => count($definitions),
        ];
    }

    /**
     * Record NEWLY met achievements. This is the only write path, called at the
     * point the accomplishment happens (after a typing/race result is saved), not
     * during page render.
     *
     * Idempotent: already-recorded ones are skipped, so it is safe to call repeatedly.
     *
     * @return array<int, string> keys of the newly unlocked achievements
     */
    public function syncUnlocks(User $user): array
    {
        $stats = $this->computeStats($user);

        $existing = UserAchievement::where('user_id', $user->id)
            ->pluck('achievement_key')
            ->all();

        $newlyUnlocked = [];
        $now = now();

        foreach (AchievementDefinitions::all() as $def) {
            if (in_array($def['key'], $existing, true)) {
                continue;
            }

            if (! ($def['check'])($stats)) {
                continue;
            }

            UserAchievement::create([
                'user_id' => $user->id,
                'achievement_key' => $def['key'],
                'unlocked_at' => $now,
            ]);

            $newlyUnlocked[] = $def['key'];
        }

        return $newlyUnlocked;
    }
}
