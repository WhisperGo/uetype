<?php

return [
    'title' => 'CLAN',
    'war_title' => 'CLAN WAR',
    'leaderboard_title' => 'PAPAN PERINGKAT CLAN',

    'leaderboard' => 'Papan Peringkat',
    'back_to_clan' => '← Kembali ke Clan',
    'back_to_clan_plain' => 'Kembali ke Clan',
    'clan_war' => 'Clan War',

    'level' => 'Lv :level',
    'power' => 'power',
    'user_level' => 'level :level',
    'members' => ':count / :max anggota',
    'members_count' => ':count anggota',
    'members_heading' => 'Anggota (:count)',
    'power_inline' => 'power :value',

    'tab' => [
        'my_clan' => 'Clan Saya',
        'browse' => 'Jelajahi Clan',
        'create' => 'Buat Clan',
        'aria' => 'Tab clan',
    ],

    'my_clan' => [
        'leave' => 'Keluar Clan',
        'join_requests' => 'Permintaan Gabung (:count)',
        'accept' => 'Terima',
        'reject' => 'Tolak',
        'kick' => 'Keluarkan',
    ],

    'browse' => [
        'search_placeholder' => 'Cari berdasarkan nama clan…',
        'joined' => 'Tergabung',
        'request_sent' => 'Permintaan Terkirim',
        'join' => '+ Gabung',
    ],

    'create' => [
        'preview_name' => 'Nama clan',
        'preview_desc' => 'Pratinjau clan-mu',
        'name_label' => 'Nama Clan',
        'name_placeholder' => 'mis. Speed Demons',
        'tag_label' => 'Tag (opsional)',
        'tag_placeholder' => 'mis. SPD',
        'desc_label' => 'Deskripsi (opsional)',
        'desc_placeholder' => 'Clan-mu tentang apa?',
        'emblem_label' => 'Emblem',
        'color_label' => 'Warna Aksen',
        'submit' => 'Buat Clan',
    ],

    'empty' => [
        'no_clan_title' => 'Kamu belum tergabung di clan',
        'no_clan_body' => 'Jelajahi clan yang ada atau buat clan-mu sendiri',
        'no_results_title' => 'Clan tidak ditemukan',
        'no_results_body' => 'Tidak ada yang cocok dengan ":query"',
        'no_results_alt' => 'Jadilah yang pertama membuat clan',
        'leaderboard_title' => 'Belum ada clan',
        'leaderboard_body' => 'Buat clan untuk muncul di papan peringkat',
        'history' => 'Belum ada war yang selesai.',
    ],

    'war' => [
        'go_to_clans' => 'Ke Halaman Clan',
        'no_clan_title' => 'Gabung clan dulu',
        'no_clan_body' => 'Clan War hanya bisa diikuti kalau kamu sudah tergabung di sebuah clan',
        'clan_power' => ':name · Power :power',
        'incoming' => 'Tantangan Masuk',
        'incoming_suffix' => '(power :power) menantang clan-mu.',
        'respond_before' => 'Harus direspons sebelum :time',
        'accept' => 'Terima',
        'decline' => 'Tolak',
        'waiting' => 'Menunggu respons',
        'waiting_prefix' => 'Menunggu',
        'waiting_suffix' => '(power :power) merespons tantanganmu.',
        'expires_at' => 'Hangus otomatis :time kalau tidak direspons',
        'ongoing' => 'War Berlangsung',
        'vs_prefix' => 'vs',
        'vs_suffix' => '(power :power)',
        'ends_at' => 'Berakhir :time',
        'your_points' => 'Poin Kamu',
        'modes_heading' => 'Mode War',
        'modes_hint' => 'Klaim mode kosong lalu kerjakan. Tiap mode hanya bisa dikerjakan sekali oleh clan-mu.',
        'mode_label' => ':mode · :config',
        'ceiling' => 'maks :points poin',
        'claim_play' => 'Klaim & Main',
        'claimed_by' => 'diklaim :name',
        'play' => 'Main',
        'cancel_claim' => 'Batalkan Klaim',
        'done_by' => ':name',
        'stat_survival' => 'bertahan :value s',
        'stat_wpm' => 'wpm :value',
        'stat_accuracy' => 'akurasi :value%',
        'challenge_heading' => 'Tantang Clan',
        'challenge' => 'Tantang',
        'challengeable_empty' => 'Belum ada clan yang bisa ditantang saat ini.',
        'not_in_war' => 'Clan-mu sedang tidak berperang. Minta leader-mu menantang clan lain.',
        'history_heading' => 'Riwayat War',
        'history_vs' => 'vs :name',
        'match_history' => 'Riwayat Pertandingan',
        'vs_label' => 'vs',
    ],

    'mode' => [
        'words' => 'Words',
        'time' => 'Time',
        'survival' => 'Survival',
        'config_time' => ':n s',
        'config_words' => ':n kata',
        'survival_hard' => 'Sulit',
    ],

    'result' => [
        'win' => 'menang',
        'draw' => 'seri',
        'loss' => 'kalah',
    ],

    'leaderboard_row' => [
        'stats' => 'Lv :level · :members anggota · :wins menang',
        'your_clan' => 'clan-mu',
        'your_clan_dot' => '· clan-mu',
    ],

    'modal' => [
        'cancel' => 'Batal',
        'leave_title' => 'Keluar dari :name?',
        'leave_body' => 'Kamu bisa mengajukan gabung lagi kapan saja, tapi progres keanggotaanmu akan hilang.',
        'leave_confirm' => 'Keluar Clan',
        'kick_title' => 'Keluarkan anggota ini?',
        'kick_body' => 'Anggota ini akan dikeluarkan dari clan dan harus mengajukan gabung lagi bila ingin kembali.',
        'kick_confirm' => 'Keluarkan',
        'cancel_claim_title' => 'Batalkan klaim ini?',
        'cancel_claim_body' => 'Slot mode ini akan terbuka lagi untuk anggota clan lain. Progres yang belum di-submit akan hilang.',
        'cancel_claim_confirm' => 'Batalkan Klaim',
    ],

    'aria' => [
        'back_leaderboard' => 'Kembali ke papan peringkat',
        'emblem' => ':key',
        'color' => ':key',
    ],

    'role' => [
        'leader' => 'Leader',
    ],

    'attr' => [
        'name' => 'nama clan',
        'tag' => 'tag clan',
    ],

    'error' => [
        'max_members' => 'Clan sudah mencapai batas maksimal :max anggota.',
        'mode_taken' => 'Mode ini baru saja diambil oleh anggota lain.',
    ],

    // Notification messages to ANOTHER user (toast). Same pattern as friends.notify.*.
    'notify' => [
        'join_request' => ':name meminta bergabung ke :clan',
        'join_accepted' => 'Permintaanmu bergabung ke :clan diterima',
        'war_challenged' => ':clan menantang clan-mu untuk Clan War',
        'war_accepted' => ':clan menerima tantangan Clan War-mu',
        'war_declined' => ':clan menolak tantangan Clan War-mu',
    ],
];
