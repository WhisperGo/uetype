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
 */
function bladeViews(): array
{
    $paths = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('views'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if ($file->getExtension() === 'php' && ! str_contains($path, '/views/vendor/')) {
            $paths[] = $path;
        }
    }

    return $paths;
}

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

it('menyediakan komponen bersama yang diharapkan', function () {
    foreach (['empty-state', 'btn-gold', 'btn-ghost', 'friend-avatar', 'toast-stack'] as $name) {
        expect(file_exists(resource_path("views/components/{$name}.blade.php")))
            ->toBeTrue("Komponen {$name} hilang");
    }
});

it('membuang komponen tombol yang tak dipakai siapa pun', function () {
    foreach (['secondary-button', 'danger-button'] as $mati) {
        expect(file_exists(resource_path("views/components/{$mati}.blade.php")))
            ->toBeFalse("{$mati} sudah tak dipakai, seharusnya dihapus");
    }
});
