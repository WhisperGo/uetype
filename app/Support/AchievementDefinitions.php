<?php

namespace App\Support;

/**
 * Source of truth for achievement RULES (in code, not the DB). Each `check`
 * is a closure(array $stats): bool, evaluated from data derived in AchievementService.
 *
 * Deliberately holds no title or description. Both views render them through
 * `__('achievements.defs.<key>.title')`, so a copy kept here would never reach a
 * screen -- it used to, and editing it silently changed nothing. The lang files are
 * the only place that text lives; AchievementsPageTest guards that every key here
 * has an entry in en AND id.
 */
class AchievementDefinitions
{
    /**
     * All achievement definitions.
     *
     * @return array<int, array{
     *   key:string, category:string, icon_value:string, icon_unit:string, check:callable
     * }>
     */
    public static function all(): array
    {
        // ===== WPM (MAX net_wpm over non-survival typing_results) =====
        return [
            [
                'key' => 'speed_demon',
                'category' => 'wpm',
                'icon_value' => '100',
                'icon_unit' => 'WPM',
                'check' => fn (array $s) => $s['highest_wpm'] >= 100,
            ],
            [
                'key' => 'supersonic',
                'category' => 'wpm',
                'icon_value' => '150',
                'icon_unit' => 'WPM',
                'check' => fn (array $s) => $s['highest_wpm'] >= 150,
            ],
            [
                'key' => 'untouchable',
                'category' => 'wpm',
                'icon_value' => '200',
                'icon_unit' => 'WPM',
                'check' => fn (array $s) => $s['highest_wpm'] >= 200,
            ],

            // ===== TESTS (COUNT of typing_results rows) =====
            [
                'key' => 'century',
                'category' => 'tests',
                'icon_value' => '100',
                'icon_unit' => 'TESTS',
                'check' => fn (array $s) => $s['total_tests'] >= 100,
            ],
            [
                'key' => 'dedicated',
                'category' => 'tests',
                'icon_value' => '500',
                'icon_unit' => 'TESTS',
                'check' => fn (array $s) => $s['total_tests'] >= 500,
            ],
            [
                'key' => 'veteran',
                'category' => 'tests',
                'icon_value' => '1K',
                'icon_unit' => 'TESTS',
                'check' => fn (array $s) => $s['total_tests'] >= 1000,
            ],

            // ===== LEVEL (derived from users.total_xp) =====
            [
                'key' => 'rising_star',
                'category' => 'level',
                'icon_value' => '10',
                'icon_unit' => 'LEVEL',
                'check' => fn (array $s) => $s['level'] >= 10,
            ],
            [
                'key' => 'elite',
                'category' => 'level',
                'icon_value' => '25',
                'icon_unit' => 'LEVEL',
                'check' => fn (array $s) => $s['level'] >= 25,
            ],
            [
                'key' => 'legend',
                'category' => 'level',
                'icon_value' => '50',
                'icon_unit' => 'LEVEL',
                'check' => fn (array $s) => $s['level'] >= 50,
            ],

            // ===== ACCURACY (from accuracy in typing_results) =====
            [
                'key' => 'perfectionist',
                'category' => 'accuracy',
                'icon_value' => '100%',
                'icon_unit' => 'ACC',
                'check' => fn (array $s) => $s['perfect_runs'] >= 1,
            ],
            [
                'key' => 'flawless',
                'category' => 'accuracy',
                'icon_value' => '100%',
                'icon_unit' => 'x10',
                'check' => fn (array $s) => $s['perfect_runs'] >= 10,
            ],
            [
                'key' => 'robot',
                'category' => 'accuracy',
                'icon_value' => '100%',
                'icon_unit' => 'x50',
                'check' => fn (array $s) => $s['perfect_runs'] >= 50,
            ],

            // ===== CHARACTERS (SUM correct_chars) =====
            [
                'key' => 'word_smith',
                'category' => 'characters',
                'icon_value' => '50K',
                'icon_unit' => 'CHARS',
                'check' => fn (array $s) => $s['total_chars'] >= 50000,
            ],
            [
                'key' => 'marathon',
                'category' => 'characters',
                'icon_value' => '200K',
                'icon_unit' => 'CHARS',
                'check' => fn (array $s) => $s['total_chars'] >= 200000,
            ],
            [
                'key' => 'unstoppable',
                'category' => 'characters',
                'icon_value' => '1M',
                'icon_unit' => 'CHARS',
                'check' => fn (array $s) => $s['total_chars'] >= 1000000,
            ],
        ];
    }

    /**
     * Categories for the UI filter chips (order matches the display).
     *
     * @return array<int, array{key:string, label:string}>
     */
    public static function categories(): array
    {
        return [
            ['key' => 'all', 'label' => 'All'],
            ['key' => 'wpm', 'label' => 'WPM'],
            ['key' => 'tests', 'label' => 'Tests'],
            ['key' => 'level', 'label' => 'Level'],
            ['key' => 'accuracy', 'label' => 'Accuracy'],
            ['key' => 'characters', 'label' => 'Characters'],
        ];
    }
}
