<?php

return [
    'mode' => [
        'time' => 'Time · :config'.'s',
        'words' => 'Words · :config',
        'survival' => 'Survival · :config',
    ],

    'game_over' => 'game over',
    'survived' => 'bertahan',
    'wpm' => 'wpm',
    'stamina_depleted_at' => 'stamina habis di :time',
    'new_personal_best' => 'rekor pribadi baru',
    'pb' => 'PB :time',
    'pb_short_by' => 'kurang :seconds d lagi',
    'beat_ghost' => 'mengalahkan ghost',
    'lost_ghost' => 'kalah dari ghost',
    'vs_ghost' => 'vs :label (:wpm wpm)',
    'afk_not_recorded' => 'Kamu meninggalkan tes ini cukup lama, jadi hasilnya tidak disimpan ke riwayat maupun statistikmu.',

    'integrity' => [
        'clear' => 'Hasil diterima dan dapat dihitung untuk rekor serta leaderboard.',
        'pending_title' => 'Hasil sedang diverifikasi otomatis',
        'pending_body' => 'Hasil tetap tersimpan, tetapi belum digunakan untuk rekor, Ghost, atau leaderboard. Backend akan memeriksanya kembali dari hasil berikutnya tanpa persetujuan admin.',
        'auto_cleared' => ':count hasil yang sebelumnya diverifikasi kini diterima otomatis.',
    ],

    // Judul toast. Tetap wajar untuk satu maupun beberapa unlock -- namanya menyusul sebagai
    // daftar, jadi tak perlu bentuk jamak terpisah. Tautan "lihat" toast memakai notif.view.
    'achievement_unlocked' => 'Achievement terbuka',

    'stat' => [
        'avg_wpm' => 'rata-rata wpm',
        'accuracy' => 'akurasi',
        'words' => 'kata',
        'drain_events' => 'drain events',
        'consistency' => 'konsistensi',
        'characters' => 'karakter',
        'difficulty' => 'tingkat',
        'raw_wpm' => 'raw wpm',
        'duration' => 'durasi',
    ],

    'xp_earned' => 'xp didapat',
    'xp_gained' => '+:amount XP',
    'xp_progress' => ':progress / :needed XP',
    'xp_level_up' => 'Level :from → :to',

    'chart_stamina' => 'stamina',
    'chart_performance' => 'performa',

    'play_again_title' => 'Main Lagi',
    'next_test_title' => 'Tes Berikutnya',
    'retry_title' => 'Ulangi Tes',
    'back_to_war' => 'Kembali ke Clan War',

    'war' => [
        'heading' => 'Kontribusi War',
        'points' => ':points / :ceiling poin',
        'factor_pace_wpm' => 'kecepatan :value wpm → :ratio× dari :scale',
        'factor_pace_survival' => 'bertahan :value dtk → :ratio× dari :scale dtk',
        'factor_accuracy' => 'akurasi :value% → :multiplier×',
        'not_counted_title' => 'Tak dihitung untuk war',
        'not_counted_body' => 'Slot ini sudah disubmit, atau war berakhir sebelum kamu selesai. Hasil ketikmu tetap tersimpan.',
        'verification_pending_title' => 'Poin war sedang diverifikasi',
        'verification_pending_body' => 'Slot sudah tersimpan, tetapi poin tetap 0 sampai backend menerima hasil secara otomatis.',
    ],

    'error_heatmap' => 'heatmap kesalahan',
    'miss_count' => ':count salah',

    'error_axis' => 'kesalahan',
    'error_hint' => 'klik penanda di grafik untuk melihat apa yang salah',
    'error_at' => 'detik :second',
    'error_expected' => 'butuh :expected',
    'error_typed' => 'kamu menekan :actual',
    'error_skipped' => 'dilewati — kamu pindah kata sebelum mengetiknya',
    'error_no_word' => 'kata tak diketahui',
    'error_legend_needed' => 'tuts yang dibutuhkan',
    'error_legend_pressed' => 'tuts yang kamu tekan',
    'error_none' => 'bersih — tanpa kesalahan',
];
