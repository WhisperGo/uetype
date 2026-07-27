<?php

/**
 * ===== SATUAN VIEWPORT & AMBANG ZOOM iOS =====
 *
 * 1. TINGGI MENGIKUTI PROTOTYPE. Seluruh aplikasi memakai `vh` (`min-h-screen`,
 *    `max-h-[70vh]`), dan itu tak diubah.
 *
 *    `dvh` sempat dicoba untuk `min-h` container terluar -- `100vh` di iOS Safari adalah
 *    tinggi viewport TANPA address bar, jadi tiap halaman punya scroll palsu ~60-100px --
 *    lalu dikembalikan: tinggi adalah keputusan desain, bukan keputusan kode. Kalau scroll
 *    palsu di iOS mau ditangani nanti, ia perlu keputusan desain lebih dulu, bukan
 *    penggantian satuan sepihak.
 *
 *    Khusus drawer chat ada alasan TEKNIS tambahan untuk tetap `vh`: `dvh` berubah saat
 *    address bar muncul/hilang, dan drawer itu berisi daftar pesan yang auto-scroll ke
 *    bawah -- tinggi berubah -> posisi scroll bergeser -> runtime chat men-scroll ulang.
 *    Komentar di chat-overlay menyatakan `max-h-[70vh]` dipilih supaya "no JS measuring is
 *    needed".
 *
 * 2. Field input tak boleh di bawah 16px. iOS Safari MEMPERBESAR seluruh halaman saat field
 *    ber-font-size < 16px difokus, dan tak mengembalikannya -- user terjebak di halaman
 *    ter-zoom sampai ia mencubitnya sendiri. Ini murni perilaku platform, bukan tinggi
 *    elemen, jadi ia tak bertabrakan dengan aturan di atas.
 *
 * CATATAN cakupan: KONTRAK MARKUP. Proyek ini tak mengeksekusi CSS di test, jadi ini
 * mengunci kelasnya, bukan membuktikan tingginya di perangkat.
 */
it('mempertahankan satuan tinggi seperti di prototype', function () {
    // LARANGAN, bukan kelalaian: jangan tukar ke `dvh` tanpa keputusan desain lebih dulu.
    foreach ([
        'views/layouts/app.blade.php',
        'views/layouts/guest.blade.php',
        'views/errors/layout.blade.php',
        'views/livewire/chat-overlay.blade.php',
        'views/livewire/chat.blade.php',
    ] as $view) {
        $markup = tanpaKomentarBlade(file_get_contents(resource_path($view)));

        expect($markup)->not->toContain('dvh');
    }

    // Tiga layout terluar tetap `min-h-screen`; drawer chat tetap `70vh`.
    foreach (['views/layouts/app.blade.php', 'views/layouts/guest.blade.php', 'views/errors/layout.blade.php'] as $view) {
        expect(tanpaKomentarBlade(file_get_contents(resource_path($view))))->toContain('min-h-screen');
    }

    expect(tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat-overlay.blade.php'))))
        ->toContain('max-h-[70vh]')
        ->and(tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/chat.blade.php'))))
        ->toContain('h-[70vh]');
});

it('menjaga setiap field teks di atas ambang zoom iOS', function () {
    // 16px = `text-base`. Di bawahnya iOS memperbesar halaman saat field difokus.
    // type=hidden/checkbox/radio tak pernah difokus untuk mengetik, jadi dikecualikan.
    $pelanggar = [];

    foreach (bladeViews() as $path) {
        // Halaman style-guide memperagakan skala tipe itu sendiri; contohnya bukan field
        // yang benar-benar diisi user.
        if (str_contains($path, 'style-guide')) {
            continue;
        }

        $src = tanpaKomentarBlade(file_get_contents($path));

        preg_match_all('/<input\b[^>]*>/s', $src, $m);

        foreach ($m[0] as $tag) {
            if (preg_match('/type="(hidden|checkbox|radio|range|color)"/', $tag)) {
                continue;
            }

            if (preg_match('/\btext-(xs|sm|small|x-small)\b/', $tag, $t)) {
                $pelanggar[] = basename($path).' ('.$t[0].')';
            }
        }
    }

    expect($pelanggar)->toBeEmpty(
        'Field < 16px membuat iOS Safari mem-zoom halaman saat difokus: '.implode(', ', $pelanggar)
    );
});

it('memberi komponen text-input ambang 16px sebagai default', function () {
    // Komponennya baru dipakai satu tempat sementara ada 22 <input> mentah, jadi ini bukan
    // jalan pintas yang menyelesaikan semuanya -- tapi ia memastikan setiap pemakai BARU
    // mewarisi ambangnya tanpa perlu mengingatnya.
    $markup = file_get_contents(resource_path('views/components/text-input.blade.php'));

    expect($markup)->toContain('text-base');
});
