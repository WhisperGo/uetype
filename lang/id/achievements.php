<?php

return [
    'header' => 'Pencapaian',
    'back_to_profile' => 'Profil',
    'unlocked_count' => ':count / :total terbuka',
    'earned' => 'Diraih',
    'locked' => 'Terkunci',

    'categories' => [
        'all' => 'Semua',
        'wpm' => 'WPM',
        'tests' => 'Tes',
        'level' => 'Level',
        'accuracy' => 'Akurasi',
        'characters' => 'Karakter',
    ],

    'defs' => [
        // Rekor WPM adalah hasil terbaik di luar survival, jadi bisa datang dari config
        // Time atau Words mana pun -- lebih baik disebutkan daripada dibiarkan ditebak.
        // Anggaran: <= 35 karakter, yaitu dua baris pada kartu tersempit (4 kolom).
        'speed_demon' => ['title' => 'Speed Demon', 'description' => 'Capai 100 WPM di Time/Words'],
        'supersonic' => ['title' => 'Supersonic', 'description' => 'Capai 150 WPM di Time/Words'],
        'untouchable' => ['title' => 'Untouchable', 'description' => 'Capai 200 WPM di Time/Words'],
        'century' => ['title' => 'Century', 'description' => 'Selesaikan 100 tes'],
        'dedicated' => ['title' => 'Dedicated', 'description' => 'Selesaikan 500 tes'],
        'veteran' => ['title' => 'Veteran', 'description' => 'Selesaikan 1.000 tes'],
        'rising_star' => ['title' => 'Rising Star', 'description' => 'Capai Level 10'],
        'elite' => ['title' => 'Elite', 'description' => 'Capai Level 25'],
        'legend' => ['title' => 'Legend', 'description' => 'Capai Level 50'],
        'perfectionist' => ['title' => 'Perfectionist', 'description' => 'Raih akurasi 100%'],
        'flawless' => ['title' => 'Flawless', 'description' => 'Akurasi 100% 10 kali'],
        'robot' => ['title' => 'Robot', 'description' => 'Akurasi 100% 50 kali'],
        'word_smith' => ['title' => 'Word Smith', 'description' => 'Ketik 50.000 karakter'],
        'marathon' => ['title' => 'Marathon', 'description' => 'Ketik 200.000 karakter'],
        'unstoppable' => ['title' => 'Unstoppable', 'description' => 'Ketik 1.000.000 karakter'],
    ],
];
