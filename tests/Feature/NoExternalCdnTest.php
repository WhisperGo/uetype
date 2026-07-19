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

it('tidak memuat aset dari CDN eksternal di view aplikasi', function () use ($hostTerlarang) {
    $pelanggaran = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
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
