<?php

use App\Models\User;

/**
 * Mesin ketik dulunya 846 baris <script> inline di dalam Blade: tak bisa di-lint,
 * di-minify, maupun di-cache browser sebagai aset terpisah.
 *
 * Test ini bergaya grep dan karenanya rapuh -- sengaja ditulis seminimal mungkin.
 * Ia hanya mengunci LOKASI kode, bukan perilakunya. Proyek ini tidak punya test
 * yang mengeksekusi JavaScript sama sekali, jadi perilaku mesin ketik tetap harus
 * diverifikasi manual di browser.
 */
it('menyimpan mesin ketik di modul js, bukan di dalam blade', function () {
    $blade = file_get_contents(resource_path('views/livewire/typing-engine.blade.php'));

    expect($blade)->not->toContain('function typingGame');

    expect(file_exists(resource_path('js/typing-game.js')))->toBeTrue();
});

it('mendaftarkan typingGame lewat bundle', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toContain("import typingGame from './typing-game'")
        ->and($appJs)->toContain('window.typingGame = typingGame');
});

/**
 * Objek ini di-SPREAD ke dalam x-data. Spread mengevaluasi getter satu kali lalu
 * membekukan hasilnya -- itulah sebabnya sparkline WPM tidak pernah tergambar:
 * saat spread, wpmHistory masih kosong sehingga nilainya terkunci di ''.
 * Getter apa pun di modul ini akan mengulang bug yang sama.
 */
it('tidak memakai getter di komponen yang di-spread', function () {
    $module = file_get_contents(resource_path('js/typing-game.js'));

    expect($module)->not->toMatch('/^\s{8}get \w+\(\)/m');
});

it('memanggil sparklinePoints sebagai method di markup', function () {
    $blade = file_get_contents(resource_path('views/livewire/typing-engine.blade.php'));

    expect($blade)->toContain(':points="sparklinePoints()"');
});

/** Guard bfcache khas halaman /typing, tak boleh ikut pindah ke app.js. */
it('mempertahankan guard bfcache di halaman typing saja', function () {
    $blade = file_get_contents(resource_path('views/livewire/typing-engine.blade.php'));
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($blade)->toContain('pageshow')
        ->and($appJs)->not->toContain('pageshow');
});

it('tetap merender halaman typing tanpa error', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('typing'))->assertOk();
    $this->get(route('typing'))->assertOk();
});
