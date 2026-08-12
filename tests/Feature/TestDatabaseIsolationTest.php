<?php

/**
 * Suite tidak boleh berjalan di atas database dev.
 *
 * RefreshDatabase menjalankan `migrate:fresh`, yang menghapus setiap tabel lebih dulu. Selama
 * `phpunit.xml` tidak menimpa DB_DATABASE, sasarannya adalah database di `.env` -- database
 * dev itu sendiri -- sehingga satu kali `php artisan test` menghapus seluruh data lokal.
 * Ironisnya `--parallel` justru aman (Laravel membuat `<db>_test_N` sendiri), jadi perintah
 * yang lebih polos yang merusak, dan tak ada yang tahu sampai kena.
 *
 * Test ini menjaga baris di `phpunit.xml` itu tetap ada. Ia sengaja memeriksa NAMA, bukan
 * koneksi: driver tetap boleh datang dari `.env` masing-masing mesin (lihat komentar di
 * `phpunit.xml`), yang tak boleh adalah sasarannya menunjuk balik ke database dev.
 */
it('runs against a dedicated test database, never the dev one', function () {
    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");

    // `--parallel` menambahkan sufiks per proses (uetype_test_1, _2, ...), jadi yang diuji
    // adalah keberadaan penanda 'test', bukan kecocokan persis dengan satu nama.
    expect($database)->toContain('test');
});
