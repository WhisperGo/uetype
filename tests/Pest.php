<?php

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
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
 * Path setiap berkas Blade milik aplikasi, untuk test bergaya grep.
 *
 * `views/vendor/` dikecualikan: itu markup paket pihak ketiga (dashboard monitoring), jadi
 * konvensi kita tak berlaku di sana dan memasukkannya hanya melahirkan kegagalan atas kode
 * yang bukan milik kita.
 *
 * Dulu berupa fungsi lokal di SharedComponentsTest. Dipindah ke sini saat TouchTargetTest
 * membutuhkannya juga -- menyalinnya akan mengulangi persis kesalahan yang dijaga oleh test
 * yang memakainya.
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

/**
 * Href tombol "kembali" yang dirender, dicari lewat aria-label-nya.
 *
 * Ditambatkan pada aria-label lalu SELURUH tag pembukanya dibaca, bukan mencocokkan atribut
 * secara berurutan: tag-nya juga membawa handler Alpine `@click` berisi `> 1`, jadi `[^>]*`
 * mana pun yang menyusuri tag akan berhenti di karakter yang salah. `(?:[^>"]|"[^"]*")*`
 * melompati apa pun di dalam nilai berkutip, sehingga `>` di handler itu tak lagi menutup
 * tag lebih awal.
 *
 * Tag dibaca utuh, bukan cuma sampai posisi aria-label, karena panahnya kini <x-icon-button>
 * dan ComponentAttributeBag::merge() memancarkan default milik komponen (aria-label, title)
 * SEBELUM atribut pemanggil -- jadi href duduk setelah label.
 *
 * Tinggal di sini, bukan di berkas test yang memakainya: fungsi yang dideklarasikan di sebuah
 * berkas test baru global saat suite dijalankan penuh, jadi menjalankan satu berkas sendirian
 * akan fatal -- alasan yang sama persis dengan bladeViews() di atas.
 */
function backArrowHref(string $html, string $label): ?string
{
    $needle = 'aria-label="'.$label.'"';
    $labelPos = strpos($html, $needle);

    if ($labelPos === false) {
        return null;
    }

    $tagStart = strrpos(substr($html, 0, $labelPos), '<a ');

    if ($tagStart === false) {
        return null;
    }

    if (! preg_match('/<a\s(?:[^>"]|"[^"]*")*>/', substr($html, $tagStart), $tag)) {
        return null;
    }

    preg_match('/href="([^"]*)"/', $tag[0], $m);

    return $m[1] ?? null;
}

/**
 * Href tautan "kembali" di kepala layout tamu (/login, pilih-username).
 *
 * Regex sederhana `<a ... >` CUKUP di sini, dan itu bukan kebetulan: layout tamu tak memuat
 *
 * @livewireScripts, jadi Alpine tak pernah menyala dan tak ada handler @click berisi `> 1`
 * yang memotong tag lebih awal seperti di panah profil. Kalau suatu saat Alpine masuk ke
 * halaman tamu, test yang memakai ini akan gagal -- dan itu memang sinyal yang diinginkan:
 * pindahkan pemanggilnya ke backArrowHref().
 */
function guestBackHref(string $html): ?string
{
    $labelPos = strpos($html, '>'.__('auth.back'));

    if ($labelPos === false) {
        return null;
    }

    $tagStart = strrpos(substr($html, 0, $labelPos), '<a ');

    if ($tagStart === false) {
        return null;
    }

    preg_match('/href="([^"]*)"/', substr($html, $tagStart, $labelPos - $tagStart), $m);

    return $m[1] ?? null;
}

/**
 * Palsukan balasan Google supaya callback bisa diuji tanpa keluar jaringan.
 *
 * Tinggal di sini, bukan di GoogleAuthFlowTest tempat ia lahir: StaySignedInTest memakainya
 * juga, dan fungsi yang dideklarasikan di sebuah berkas test baru global saat suite
 * dijalankan penuh -- alasan yang sama dengan bladeViews() dan backArrowHref().
 */
function fakeGoogleUser(string $id = 'g-12345', string $email = 'pemain@gmail.com'): SocialiteUser
{
    $googleUser = new SocialiteUser;
    $googleUser->id = $id;
    $googleUser->email = $email;
    $googleUser->avatar = 'https://lh3.googleusercontent.com/a/foto';

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    return $googleUser;
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
