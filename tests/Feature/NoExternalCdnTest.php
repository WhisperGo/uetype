<?php

/**
 * Semua aset pihak ketiga harus lewat bundle Vite, bukan CDN runtime.
 *
 * Chart.js sempat dimuat dua jalur: di-bundle lewat app.js DAN ditarik dari
 * cdn.jsdelivr.net sebagai fallback -- tanpa versi terkunci. Jalur CDN praktis
 * mati (window.Chart selalu ada), tapi kalau bundle gagal termuat aplikasi diam-diam
 * mengeksekusi JS pihak ketiga yang tak pernah di-review: permukaan supply-chain,
 * IP pengguna bocor ke host luar, dan rusak saat offline.
 *
 * Test ini jaring pengaman permanen, bukan sekali pakai.
 */
$hostTerlarang = [
    'cdn.jsdelivr.net',
    'unpkg.com',
    'cdnjs.cloudflare.com',
];

it('tidak memuat aset dari CDN eksternal di view maupun modul js', function () use ($hostTerlarang) {
    $pelanggaran = [];

    // Memindai views DAN resources/js: setelah JS dipindah keluar dari Blade,
    // memindai views saja membuat pemeriksaan ini berhenti berjalan untuk kode
    // yang justru paling mungkin menarik dependensi luar.
    $files = new AppendIterator;
    $files->append(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    ));
    $files->append(new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)
    ));

    foreach ($files as $file) {
        if (! in_array($file->getExtension(), ['php', 'js'], true)) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        // View bawaan paket pihak ketiga (LaravelUserMonitoring) di luar kendali kita.
        if (str_contains($path, '/views/vendor/')) {
            continue;
        }

        $isi = file_get_contents($file->getPathname());

        foreach ($hostTerlarang as $host) {
            if (str_contains($isi, $host)) {
                $pelanggaran[] = basename($path).' -> '.$host;
            }
        }
    }

    expect($pelanggaran)->toBeEmpty(
        'Aset harus lewat bundle Vite, bukan CDN runtime: '.implode(', ', $pelanggaran)
    );
});

it('menyediakan Chart secara global dari bundle', function () {
    // Dasar yang membuat fallback CDN tak diperlukan: app.js mengekspos window.Chart.
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toContain('window.Chart');
});
