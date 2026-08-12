<?php

return [
    'title' => 'CLAN',
    'war_title' => 'CLAN WAR',
    'leaderboard_title' => 'PAPAN PERINGKAT CLAN',

    'leaderboard' => 'Papan Peringkat',
    // Tanpa panah di dalam string: chevron-nya markup (<x-header-link back>), jadi bisa diatur
    // ukurannya dan disembunyikan dari screen reader.
    'back_to_clan' => 'Kembali ke Clan',
    'clan_war' => 'Clan War',
    'chat' => 'Chat Clan',

    'level' => 'Lv :level',
    'power' => 'power',
    'user_level' => 'level :level',
    'members' => ':count / :max anggota',
    'members_count' => ':count anggota',
    'members_heading' => 'Anggota (:count)',
    'power_inline' => 'power :value',

    'tab' => [
        // Tanpa kunci 'my_clan': tab itu tak punya tombol. Begitu kamu punya clan, halaman
        // ini ADALAH clanmu -- tak ada yang perlu dipindah, jadi baris tabnya tak dirender.
        //
        // Label tak memuat "Clan": judul halaman, item nav, dan judul browser sudah
        // menyebutnya, dan pengulangannya membuat kedua chip cukup panjang untuk berdempetan.
        'browse' => 'Jelajahi',
        'create' => 'Buat',
        'aria' => 'Tab clan',
    ],

    'my_clan' => [
        'leave' => 'Keluar Clan',
        'join_requests' => 'Permintaan Gabung (:count)',
        'accept' => 'Terima',
        'reject' => 'Tolak',
        'kick' => 'Keluarkan',
        'manage' => 'Kelola Clan',
        'edit' => 'Ubah Identitas Clan',
        'edit_heading' => 'Ubah Identitas Clan',
        'edit_save' => 'Simpan Perubahan',
        'edit_cancel' => 'Batal',
        'promote' => 'Jadikan Co-Leader',
        'demote' => 'Turunkan ke Anggota',
        'transfer' => 'Serahkan Kepemimpinan',
        'disband' => 'Bubarkan Clan',
    ],

    'browse' => [
        'search_placeholder' => 'Cari berdasarkan nama clan…',
        'joined' => 'Tergabung',
        'request_sent' => 'Permintaan Terkirim',
        'cancel_request' => 'Batalkan',
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
        // Ditampilkan ke member biasa sebagai ganti tombol Terima/Tolak: tantangannya tetap
        // terlihat, yang hilang cuma aksi yang memang bukan haknya.
        'awaiting_officers' => 'Menunggu keputusan leader atau co-leader clan-mu.',
        'waiting' => 'Menunggu respons',
        'waiting_prefix' => 'Menunggu',
        'waiting_suffix' => '(power :power) merespons tantanganmu.',
        'expires_at' => 'Hangus otomatis :time kalau tidak direspons',
        'cancel_challenge' => 'Batalkan Tantangan',
        'ongoing' => 'War Berlangsung',
        'vs_prefix' => 'vs',
        'vs_suffix' => '(power :power)',
        'ends_at' => 'Berakhir :time',
        'your_points' => 'Poin Kamu',
        'opponent_points' => 'Lawan',
        'modes_heading' => 'Mode War',
        'modes_hint' => 'Klaim mode kosong, lalu mulai percobaanmu. Tiap mode hanya bisa dikerjakan sekali oleh clan-mu, dan kamu dapat satu percobaan. Kamu boleh mengklaim maksimal :max mode.',
        'mode_label' => ':mode · :config',
        'ceiling' => 'maks :points poin',
        'claim_play' => 'Klaim',
        'claimed_by' => 'diklaim :name',
        'play' => 'Main',
        'start_attempt' => 'Mulai Percobaan',
        'resume' => 'Lanjutkan',
        'in_progress' => 'percobaan berjalan',
        'attempt_expired' => 'waktu percobaan habis',
        'no_quota' => 'Jatah klaim habis',
        'confirm_start_title' => 'Mulai percobaanmu?',
        'confirm_start_body' => 'Kamu dapat satu percobaan untuk mode ini. Jamnya mulai sekarang dan terus berjalan — refresh atau keluar halaman tidak akan mengulanginya.',
        'cancel_claim' => 'Batalkan Klaim',
        'done_by' => ':name',
        'stat_survival' => 'bertahan :value s',
        'stat_wpm' => 'wpm :value',
        'stat_accuracy' => 'akurasi :value%',
        'challenge_heading' => 'Tantang Clan',
        'challenge' => 'Tantang',
        'challengeable_empty' => 'Belum ada clan yang bisa ditantang saat ini.',
        'not_in_war' => 'Clan-mu sedang tidak berperang. Minta leader atau co-leader-mu menantang clan lain.',
        'history_heading' => 'Riwayat War',
        'history_vs' => 'vs :name',
        'opponent_disbanded' => 'clan yang sudah bubar',
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
        'cancel_challenge_title' => 'Batalkan tantangan ini?',
        'cancel_challenge_body' => 'Clan lawan akan diberi tahu kamu membatalkannya. Kedua clan langsung bebas memulai war baru.',
        'cancel_challenge_confirm' => 'Batalkan Tantangan',
        'promote_title' => 'Jadikan :name Co-Leader?',
        'promote_body' => 'Co-Leader bisa menerima permintaan gabung dan mengeluarkan anggota, tapi tidak bisa membubarkan clan atau mengubah kepemimpinan.',
        'promote_confirm' => 'Jadikan Co-Leader',
        'demote_title' => 'Turunkan :name ke anggota?',
        'demote_body' => 'Dia akan kehilangan akses mengelola permintaan gabung dan mengeluarkan anggota.',
        'demote_confirm' => 'Turunkan',
        'transfer_title' => 'Serahkan kepemimpinan ke :name?',
        'transfer_body' => 'Dia akan menjadi Leader dan kamu turun menjadi anggota biasa. Tindakan ini tidak bisa dibatalkan sendiri - hanya Leader baru yang bisa mengembalikannya.',
        'transfer_confirm' => 'Serahkan Kepemimpinan',
        'disband_title' => 'Bubarkan :name?',
        'disband_body' => 'Seluruh anggota akan dikeluarkan dan clan ini dihapus permanen beserta riwayat war-nya. Tindakan ini tidak bisa dibatalkan.',
        'disband_prompt' => 'Ketik :name untuk mengonfirmasi',
        'disband_confirm' => 'Bubarkan Clan',
    ],

    'aria' => [
        'back_leaderboard' => 'Kembali ke papan peringkat',
        'emblem' => ':key',
        'color' => ':key',
        'member_actions' => 'Aksi untuk :name',
    ],

    'role' => [
        'leader' => 'Leader',
        'co-leader' => 'Co-Leader',
    ],

    'roster' => [
        'last_seen' => 'aktif :time',
        'never_seen' => 'belum pernah aktif',
        'war_points' => ':points poin war',
        'joined' => 'gabung :time',
    ],

    'attr' => [
        'name' => 'nama clan',
        'tag' => 'tag clan',
    ],

    'error' => [
        'max_members' => 'Clan sudah mencapai batas maksimal :max anggota.',
        'mode_taken' => 'Mode ini baru saja diambil oleh anggota lain.',
        'max_claims' => 'Kamu sudah mengambil semua :max slot jatahmu di war ini.',
        'attempt_expired' => 'Waktu percobaan itu sudah habis. Satu slot war dapat satu percobaan, dan jamnya terus berjalan begitu dibuka.',
        'disband_during_war' => 'Clan tidak bisa dibubarkan selama masih ada Clan War berjalan. Selesaikan war-nya dulu.',
        'disband_name_mismatch' => 'Nama clan tidak cocok.',
    ],

    // Notification messages to ANOTHER user (toast). Same pattern as friends.notify.*.
    'notify' => [
        'join_request' => ':name meminta bergabung ke :clan',
        'join_accepted' => 'Permintaanmu bergabung ke :clan diterima',
        'war_challenged' => ':clan menantang clan-mu untuk Clan War',
        'war_accepted' => ':clan menerima tantangan Clan War-mu',
        'war_declined' => ':clan menolak tantangan Clan War-mu',
        'war_cancelled' => ':clan membatalkan tantangan Clan War-nya',
        'leadership_received' => 'Kamu sekarang Leader dari :clan',
        'promoted' => 'Kamu diangkat menjadi Co-Leader di :clan',
        'demoted' => 'Kamu kembali menjadi anggota biasa di :clan',
        'disbanded' => ':clan telah dibubarkan oleh leader-nya',
        'identity_updated' => 'Identitas clan-mu kini menjadi :clan',
        // Hasil war. Masing-masing disambung " vs <clan> (<+/-N> power)" di ClanWarResolver,
        // jadi ini pembuka kalimat, bukan kalimat utuh.
        'war_won' => 'Clan-mu MENANG Clan War',
        'war_lost' => 'Clan-mu KALAH Clan War',
        'war_drawn' => 'Clan War berakhir SERI',
    ],
];
