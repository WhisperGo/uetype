<?php

namespace App\Support;

/**
 * Source of truth for achievement definitions (in code, not the DB). Static:
 * evaluated from existing data (typing_results, users.total_xp/highest_wpm), with
 * no rule engine or streak/temporal tracking. user_achievements only records who
 * and when. Each `check` is a closure(array $stats): bool; $stats is derived in
 * AchievementService.
 */
class AchievementDefinitions
{
    /**
     * @return array<int, array{
     *   key:string, title:string, description:string, category:string,
     *   icon_value:string, icon_unit:string, check:callable
     * }>
     */
    public static function all(): array
    {
        return [
            // ===== WPM (dari users.highest_wpm) =====
            [
                'key' => 'speed_demon',
                'title' => 'Speed Demon',
                'description' => 'Reach 100 WPM',
                'category' => 'wpm',
                'icon_value' => '100',
                'icon_unit' => 'WPM',
                'check' => fn (array $s) => $s['highest_wpm'] >= 100,
            ],
            [
                'key' => 'supersonic',
                'title' => 'Supersonic',
                'description' => 'Reach 150 WPM',
                'category' => 'wpm',
                'icon_value' => '150',
                'icon_unit' => 'WPM',
                'check' => fn (array $s) => $s['highest_wpm'] >= 150,
            ],
            [
                'key' => 'untouchable',
                'title' => 'Untouchable',
                'description' => 'Reach 200 WPM',
                'category' => 'wpm',
                'icon_value' => '200',
                'icon_unit' => 'WPM',
                'check' => fn (array $s) => $s['highest_wpm'] >= 200,
            ],

            // ===== TESTS (COUNT baris typing_results) =====
            [
                'key' => 'century',
                'title' => 'Century',
                'description' => 'Complete 100 tests',
                'category' => 'tests',
                'icon_value' => '100',
                'icon_unit' => 'TESTS',
                'check' => fn (array $s) => $s['total_tests'] >= 100,
            ],
            [
                'key' => 'dedicated',
                'title' => 'Dedicated',
                'description' => 'Complete 500 tests',
                'category' => 'tests',
                'icon_value' => '500',
                'icon_unit' => 'TESTS',
                'check' => fn (array $s) => $s['total_tests'] >= 500,
            ],
            [
                'key' => 'veteran',
                'title' => 'Veteran',
                'description' => 'Complete 1,000 tests',
                'category' => 'tests',
                'icon_value' => '1K',
                'icon_unit' => 'TESTS',
                'check' => fn (array $s) => $s['total_tests'] >= 1000,
            ],

            // ===== LEVEL (turunan dari users.total_xp) =====
            [
                'key' => 'rising_star',
                'title' => 'Rising Star',
                'description' => 'Reach Level 10',
                'category' => 'level',
                'icon_value' => '10',
                'icon_unit' => 'LEVEL',
                'check' => fn (array $s) => $s['level'] >= 10,
            ],
            [
                'key' => 'elite',
                'title' => 'Elite',
                'description' => 'Reach Level 25',
                'category' => 'level',
                'icon_value' => '25',
                'icon_unit' => 'LEVEL',
                'check' => fn (array $s) => $s['level'] >= 25,
            ],
            [
                'key' => 'legend',
                'title' => 'Legend',
                'description' => 'Reach Level 50',
                'category' => 'level',
                'icon_value' => '50',
                'icon_unit' => 'LEVEL',
                'check' => fn (array $s) => $s['level'] >= 50,
            ],

            // ===== ACCURACY (dari accuracy di typing_results) =====
            [
                'key' => 'perfectionist',
                'title' => 'Perfectionist',
                'description' => 'Get 100% accuracy',
                'category' => 'accuracy',
                'icon_value' => '100%',
                'icon_unit' => 'ACC',
                'check' => fn (array $s) => $s['perfect_runs'] >= 1,
            ],
            [
                'key' => 'flawless',
                'title' => 'Flawless',
                'description' => '100% acc 10 times',
                'category' => 'accuracy',
                'icon_value' => '100%',
                'icon_unit' => 'x10',
                'check' => fn (array $s) => $s['perfect_runs'] >= 10,
            ],
            [
                'key' => 'robot',
                'title' => 'Robot',
                'description' => '100% acc 50 times',
                'category' => 'accuracy',
                'icon_value' => '100%',
                'icon_unit' => 'x50',
                'check' => fn (array $s) => $s['perfect_runs'] >= 50,
            ],

            // ===== CHARACTERS (SUM correct_chars) =====
            [
                'key' => 'word_smith',
                'title' => 'Word Smith',
                'description' => 'Type 50,000 characters',
                'category' => 'characters',
                'icon_value' => '50K',
                'icon_unit' => 'CHARS',
                'check' => fn (array $s) => $s['total_chars'] >= 50000,
            ],
            [
                'key' => 'marathon',
                'title' => 'Marathon',
                'description' => 'Type 200,000 chars',
                'category' => 'characters',
                'icon_value' => '200K',
                'icon_unit' => 'CHARS',
                'check' => fn (array $s) => $s['total_chars'] >= 200000,
            ],
            [
                'key' => 'unstoppable',
                'title' => 'Unstoppable',
                'description' => 'Type 1,000,000 chars',
                'category' => 'characters',
                'icon_value' => '1M',
                'icon_unit' => 'CHARS',
                'check' => fn (array $s) => $s['total_chars'] >= 1000000,
            ],
        ];
    }

    /**
     * Kategori untuk filter chips di UI (urutan sesuai tampilan).
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
