<?php

return [
    'header' => 'ACHIEVEMENTS',
    'back_to_profile' => 'Profile',
    'unlocked_count' => ':count / :total unlocked',
    'earned' => 'Earned',
    'locked' => 'Locked',

    'categories' => [
        'all' => 'All',
        'wpm' => 'WPM',
        'tests' => 'Tests',
        'level' => 'Level',
        'accuracy' => 'Accuracy',
        'characters' => 'Characters',
    ],

    'defs' => [
        // The WPM record is the best non-survival result, so it can come from any Time
        // or Words config -- saying so beats letting players guess. Budget: <= 35 chars,
        // which is two lines in the card at its narrowest (4 columns).
        'speed_demon' => ['title' => 'Speed Demon', 'description' => 'Reach 100 WPM (Time or Words)'],
        'supersonic' => ['title' => 'Supersonic', 'description' => 'Reach 150 WPM (Time or Words)'],
        'untouchable' => ['title' => 'Untouchable', 'description' => 'Reach 200 WPM (Time or Words)'],
        'century' => ['title' => 'Century', 'description' => 'Complete 100 tests'],
        'dedicated' => ['title' => 'Dedicated', 'description' => 'Complete 500 tests'],
        'veteran' => ['title' => 'Veteran', 'description' => 'Complete 1,000 tests'],
        'rising_star' => ['title' => 'Rising Star', 'description' => 'Reach Level 10'],
        'elite' => ['title' => 'Elite', 'description' => 'Reach Level 25'],
        'legend' => ['title' => 'Legend', 'description' => 'Reach Level 50'],
        'perfectionist' => ['title' => 'Perfectionist', 'description' => 'Get 100% accuracy'],
        'flawless' => ['title' => 'Flawless', 'description' => '100% acc 10 times'],
        'robot' => ['title' => 'Robot', 'description' => '100% acc 50 times'],
        'word_smith' => ['title' => 'Word Smith', 'description' => 'Type 50,000 characters'],
        'marathon' => ['title' => 'Marathon', 'description' => 'Type 200,000 chars'],
        'unstoppable' => ['title' => 'Unstoppable', 'description' => 'Type 1,000,000 chars'],
    ],
];
