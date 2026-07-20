<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Jumlah query yang dijalankan selama $callback. Dipakai untuk MENGUNCI budget
 * query sebuah halaman: kalau nanti ada yang menambahkan N+1, test-nya gagal.
 *
 * Bukan sekadar alat ukur sekali pakai -- ini jaring pengaman permanen terhadap
 * regresi performa, yang tak bisa ditangkap oleh test fungsional biasa (halaman
 * dengan 500 query tetap "lulus" kalau outputnya benar).
 */
/**
 * Seluruh sumber sisi-klien arena balapan: markup Blade (tempat lane x-data hidup)
 * digabung dengan kedua modul JS-nya.
 *
 * Logika arena pindah dari <script> inline ke resources/js/race-*.js. Test yang
 * membaca Blade saja akan gagal dengan alasan yang salah -- "string tak ditemukan"
 * padahal kodenya hanya berpindah file. Digabung supaya kontraknya tetap terjaga
 * di mana pun kodenya tinggal.
 */
function arenaSourceAll(): string
{
    return implode("\n", [
        file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php')),
        file_get_contents(resource_path('js/race-arena.js')),
        file_get_contents(resource_path('js/race-echo.js')),
    ]);
}

function countQueries(Closure $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $callback();

        return count(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
        DB::flushQueryLog();
    }
}
