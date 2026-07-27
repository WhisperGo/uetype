<?php

return [
    'standard' => 'Standard',
    'survival' => 'Survival',
    'survival_login' => 'Login untuk buka Survival',
    'ghost' => 'Ghost',
    'ghost_pick' => '+ Lawan Ghost',
    'ghost_change' => 'Ubah',
    'ghost_clear' => 'Lepas',
    'ghost_login' => '+ Lawan Ghost',

    'type_time' => 'Time',
    'type_words' => 'Words',

    'stat_left' => 'sisa',
    'stat_time' => 'waktu',
    'stat_wpm' => 'wpm',
    'stat_acc' => 'akurasi',
    'stamina' => 'stamina',

    'restart' => 'ulangi',
    'then' => 'lalu',
    'war_locked_restart' => 'ulangi dikunci (clan war)',
    'war_lock_label' => 'Clan War',
    'war_lock_cancel' => 'Batalkan & kembali ke Clan War',

    'caps_lock' => 'Caps Lock aktif',

    // Khusus perangkat sentuh: input pemunculan keyboard tak terlihat, jadi tanpa ini tak ada
    // apa pun di layar yang memberi tahu bahwa teksnya harus disentuh dulu.
    'tap_to_type' => 'Ketuk untuk mulai mengetik',
    'input_aria' => 'Input tes mengetik',

    // Cadangan umum: khusus untuk hasil yang benar-benar dinilai mustahil oleh server. Dua
    // pesan di bawah ada karena pesan ini dulu dipakai untuk SEMUA jalur penolakan, termasuk
    // yang bukan salah siapa pun -- memberi tahu pemain jujur bahwa hasilnya "tidak masuk
    // akal" padahal ia hanya menyelesaikan tes dengan cepat adalah bug tersendiri.
    'result_rejected' => 'Hasil sesi ini ditolak oleh validasi server (tidak masuk akal) dan tidak disimpan.',
    'result_rate_limited' => 'Terlalu banyak hasil dikirim dalam waktu singkat. Tunggu sebentar sebelum menyelesaikan tes berikutnya.',
    'result_session_expired' => 'Sesi tes ini kedaluwarsa atau dimulai di tab lain, sehingga hasilnya tidak dapat disimpan. Silakan jalankan tesnya lagi.',

    'aria' => [
        'pick_main_mode' => 'Pilih mode utama',
        'mode_standard' => 'Mode Standard',
        'mode_survival' => 'Mode Survival',
        'mode_ghost' => 'Mode Ghost',
        'mode_config' => 'Konfigurasi mode',
        'type' => 'Tipe :label',
        'duration_seconds' => 'Durasi :seconds detik',
        'words_count' => ':count kata',
        'difficulty' => 'Tingkat :level',
        'pick_content_language' => 'Pilih bahasa ketikan',
    ],
];
