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
     * All four aggregates are pulled in ONE query (previously one query per figure,
     * over the exact same table and filter).
     *
     * The WPM record is DERIVED here rather than read from users.highest_wpm. That
     * column only ever moves up -- it is never lowered -- so once a result row is
     * removed (say by the `typing:audit` review) the column keeps vouching for data
     * that no longer exists, and the badge stays lit on top of nothing. Deriving it
     * follows the same rule the whole Stats page already lives by: the number cannot
     * disagree with typing_results because it IS typing_results.
     *
     * `mode <> 'survival'` mirrors the exact condition TypingEngine applies when it
     * writes the column, rather than a whitelist -- so a future measured mode is
     * picked up by both at once. Survival is excluded because it is achieved under
     * stamina pressure and is not comparable.
     *
     * @return array{highest_wpm:float, level:int, total_tests:int, total_chars:int, perfect_runs:int}
     */
    public function computeStats(User $user): array
    {
        $agg = TypingResult::where('user_id', $user->id)
            ->selectRaw("
                COUNT(*) as total_tests,
                COALESCE(SUM(correct_chars), 0) as total_chars,
                COALESCE(SUM(CASE WHEN accuracy >= 100 THEN 1 ELSE 0 END), 0) as perfect_runs,
                COALESCE(MAX(CASE WHEN mode <> 'survival' THEN net_wpm END), 0) as highest_wpm
            ")
            ->first();

        return [
            'highest_wpm' => (float) $agg->highest_wpm,
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
     * Titles and descriptions are NOT returned: they live only in the lang files
     * (`achievements.defs.<key>.title`), which is where both views read them from.
     *
     * @return array{
     *   items: array<int, array{key:string,category:string,icon_value:string,icon_unit:string,earned:bool,unlocked_at:?Carbon}>,
     *   earned_count: int,
     *   total: int
     * }
     */
    public function evaluate(User $user): array
    {
        $stats = $this->computeStats($user);
        $definitions = AchievementDefinitions::all();

        // Existing unlock records: both the "unlocked at" date AND proof of the unlock.
        $existing = UserAchievement::where('user_id', $user->id)
            ->get()
            ->keyBy('achievement_key');

        $items = [];
        $earnedCount = 0;

        foreach ($definitions as $def) {
            // An unlock is permanent once recorded. Status used to be recomputed from
            // scratch every render, so a badge could quietly disappear whenever the
            // underlying data shrank -- deleting a result row (an anti-cheat review, say)
            // would take an honest player's badge with it. The record is the receipt.
            $earned = $existing->has($def['key']) || (bool) ($def['check'])($stats);

            if ($earned) {
                $earnedCount++;
            }

            $items[] = [
                'key' => $def['key'],
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
