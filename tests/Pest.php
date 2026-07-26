<?php

use App\Models\Message;
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

/**
 * Badan SATU metode komponen raceArena, dipotong pada penutup metode berikutnya.
 *
 * Pendahulunya (handleSpaceSource di RaceProgressIntegrityTest) memotong dari nama metode
 * sampai AKHIR FILE, jadi ia sebetulnya menguji "ada di suatu tempat di bawah sini" -- sebuah
 * assertion urutan di dalamnya bisa hijau karena kode di metode yang sama sekali lain. Lebih
 * halus lagi: ia memakai kemunculan PERTAMA nama itu, sehingga sebuah komentar yang menyebut
 * nama metode menggeser jendela pemeriksaan tanpa satu pun test berubah warna.
 *
 * $signature ditulis lengkap dengan tanda kurungnya (mis. 'checkInput()', 'handleSpace(e)')
 * supaya ia tak cocok dengan pemanggilan `this.checkInput()` di tempat lain.
 */
function raceMethodSource(string $signature): string
{
    $js = file_get_contents(resource_path('js/race-arena.js'));
    $start = strpos($js, "\n        {$signature} {");

    expect($start)->not->toBeFalse("Metode `{$signature}` tak ditemukan di race-arena.js");

    // Setiap metode komponen ditutup oleh `},` pada indentasi 8 spasi.
    $end = strpos($js, "\n        },", $start);

    expect($end)->not->toBeFalse("Penutup metode `{$signature}` tak ditemukan.");

    return substr($js, $start, $end - $start);
}

/**
 * Markup Blade tanpa blok komentar `{{-- ... --}}`.
 *
 * Konvensi proyek ini menyuruh komentar menjelaskan ALASAN sebuah keputusan, yang berarti
 * komentar sering menyebut justru pola yang sudah dilarang ("dulu menambat di X, sekarang
 * tidak"). Assertion yang membaca teks mentah akan menuduh prosanya sendiri dan gagal
 * dengan alasan yang salah. Pakai ini kalau yang diuji adalah markup AKTIF.
 */
function tanpaKomentarBlade(string $markup): string
{
    return preg_replace('/\{\{--.*?--\}\}/s', '', $markup);
}

/**
 * Sumber JavaScript tanpa komentar satu baris maupun komentar blok.
 *
 * Padanan tanpaKomentarBlade() untuk berkas JS, dan ada karena alasan yang sama: komentar di
 * proyek ini menjelaskan ALASAN, jadi ia sering menyebut justru pola yang sedang dilarang
 * ("sebuah `return` di atas baris ini akan membocorkan spasinya"). Assertion yang memeriksa
 * URUTAN dua string akan menemukan kata itu di dalam prosa lebih dulu dan gagal dengan alasan
 * yang sepenuhnya salah. Wajib dipakai sebelum membandingkan posisi (strpos), tak perlu untuk
 * sekadar memeriksa keberadaan.
 */
function tanpaKomentarJs(string $source): string
{
    return preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $source);
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

/**
 * Assert a chat message was stored with the given metadata AND body.
 *
 * The `body` column is encrypted at rest (Message::$casts), so its ciphertext differs on
 * every write (random IV) -- assertDatabaseHas('messages', ['body' => 'Halo']) can never
 * match. Instead: match the row by its non-encrypted metadata, then confirm the DECRYPTED
 * body through the model. This also proves the encrypt/decrypt round-trip works.
 *
 * $meta is the non-body attributes to match (sender_id, recipient_id, clan_id, reply_to_id…).
 */
function assertMessageStored(array $meta, string $expectedBody): void
{
    test()->assertDatabaseHas('messages', $meta);

    $message = Message::query()->where($meta)->latest('id')->first();

    expect($message)->not->toBeNull()
        ->and($message->body)->toBe($expectedBody);
}
