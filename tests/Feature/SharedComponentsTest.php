<?php

/**
 * Pola visual berulang harus punya SATU sumber kebenaran.
 *
 * Sebelum ini, 8 blok "empty state" disalin di 6 file dan sudah menyimpang jadi
 * empat varian (py-16/py-24, w-16/w-16 h-16). Duplikasi avatar bahkan sudah
 * terbukti melahirkan defect: dua atribut `alt` yang hilang berada tepat di
 * tempat yang menulis markup sendiri alih-alih memakai <x-friend-avatar>.
 *
 * Test ini bergaya grep -- ia menjaga agar pola lama tak menyelinap kembali,
 * bukan menguji tampilannya.
 *
 * `bladeViews()` hidup di tests/Pest.php sejak TouchTargetTest membutuhkannya juga.
 */
it('tidak menyisakan markup empty-state yang ditulis tangan', function () {
    $pelanggar = [];

    foreach (bladeViews() as $path) {
        if (basename($path) === 'empty-state.blade.php') {
            continue;
        }

        // Ciri khas blok lama: maskot pudar sebagai penanda keadaan kosong.
        if (preg_match('/uetype_mascot\.png"\s+alt=""/', file_get_contents($path))) {
            $pelanggar[] = basename($path);
        }
    }

    expect($pelanggar)->toBeEmpty(
        'Pakai <x-empty-state>, jangan salin markupnya: '.implode(', ', $pelanggar)
    );
});

it('tidak menyisakan kelas tombol emas yang ditulis tangan', function () {
    $pelanggar = [];

    foreach (bladeViews() as $path) {
        if (basename($path) === 'btn-gold.blade.php') {
            continue;
        }

        $isi = file_get_contents($path);

        // FAB chat overlay sengaja dikecualikan: bulat, punya logika drag,
        // bukan tombol teks -- lihat komentar di komponennya.
        $isi = str_replace('rounded-full bg-gold hover:bg-gold/90', '', $isi);

        if (str_contains($isi, 'bg-gold hover:bg-gold/90')) {
            $pelanggar[] = basename($path);
        }
    }

    expect($pelanggar)->toBeEmpty(
        'Pakai <x-btn-gold>: '.implode(', ', $pelanggar)
    );
});

/**
 * Panel "XP earned + progres level" ada TIGA kali: dua kali identik di layar hasil solo
 * (satu per cabang layout) dan sekali lagi di panel hasil multiplayer -- dan salinan
 * multiplayer sudah menyimpang di belasan detail: bar 1.5px alih-alih 2px, track ber-border,
 * angka `font-black` emas alih-alih `font-bold` foreground, label 10px, dan "Level 1 - 2"
 * ditulis dengan tanda hubung sementara solo memakai panah dari string lang-nya sendiri.
 *
 * Informasi yang sama tampil berbeda tergantung mode yang baru dimainkan. Persis kelas
 * masalah yang file test ini dibuat untuk mencegah.
 */
it('tidak menyisakan markup XP bar yang ditulis tangan', function () {
    $pelanggar = [];

    foreach (bladeViews() as $path) {
        if (basename($path) === 'xp-bar.blade.php') {
            continue;
        }

        $isi = file_get_contents($path);

        // Ciri khas panelnya: label "xp earned" -- baik versi solo maupun multiplayer.
        if (str_contains($isi, "__('result.xp_earned')") || str_contains($isi, "__('multiplayer.xp_earned')")) {
            $pelanggar[] = basename($path);
        }
    }

    expect($pelanggar)->toBeEmpty(
        'Pakai <x-xp-bar>, jangan salin markupnya: '.implode(', ', $pelanggar)
    );
});

it('memakai satu desain XP bar di layar hasil solo maupun multiplayer', function () {
    foreach (['typing-result', 'multiplayer-lobby'] as $view) {
        expect(file_get_contents(resource_path("views/livewire/{$view}.blade.php")))
            ->toContain('<x-xp-bar');
    }
});

it('menyediakan komponen bersama yang diharapkan', function () {
    foreach (['empty-state', 'btn-gold', 'btn-ghost', 'friend-avatar', 'toast-stack', 'room-invite-overlay', 'xp-bar', 'header-link'] as $name) {
        expect(file_exists(resource_path("views/components/{$name}.blade.php")))
            ->toBeTrue("Komponen {$name} hilang");
    }
});

it('memasang overlay undangan room di layout global agar muncul di semua halaman', function () {
    // Overlay harus ada di app.blade.php (bukan di satu halaman) supaya undangan bisa
    // muncul di mana pun user berada, sama seperti toast stack.
    expect(file_get_contents(resource_path('views/layouts/app.blade.php')))
        ->toContain('<x-room-invite-overlay');
});

it('membuang komponen tombol yang tak dipakai siapa pun', function () {
    foreach (['secondary-button', 'danger-button'] as $mati) {
        expect(file_exists(resource_path("views/components/{$mati}.blade.php")))
            ->toBeFalse("{$mati} sudah tak dipakai, seharusnya dihapus");
    }
});
