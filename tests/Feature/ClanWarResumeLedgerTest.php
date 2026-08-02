<?php

use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\ClanWarAttempt;
use App\Services\SoloSessionGuard;

/**
 * Sebuah attempt yang di-refresh harus dinilai atas SELURUH kerja yang benar-benar dilakukan,
 * bukan cuma potongan terakhirnya.
 *
 * Resume sudah bekerja: pemain kembali ke kata tempat ia berhenti. Tapi karakter yang dipulihkan
 * itu dikreditkan ke hitungan keystroke, sementara jam klien baru mulai pada keystroke pertama
 * SETELAH refresh. Jadi pembilangnya mencakup seluruh attempt dan penyebutnya hanya sesi
 * terakhir -- WPM melar, dan karena poin Clan War = ceiling x (wpm / 150), refresh membeli poin.
 *
 * Kebocoran keduanya lebih senyap: karakter yang dipulihkan semuanya ditandai BENAR, jadi
 * kesalahan yang dibuat sebelum refresh lenyap. accuracyMultiplier (0.5-1.0x) di ClanWarScorer
 * membacanya, jadi refresh juga mencuci akurasi.
 *
 * Perbaikannya adalah sebuah BUKU BESAR di klaim: tiap sesi melaporkan waktu ketik & jumlah
 * keystroke-nya sendiri, server menjumlahkan. Angka akhir jadi benar secara konstruksi, bukan
 * hasil menebak seberapa besar grace yang pantas.
 */

/** Panjang teks yang benar-benar diterbitkan untuk klaim ini (tiap mode/config berbeda). */
function warAttemptTextLength(ClanWarModeClaim $claim): int
{
    return mb_strlen((string) $claim->refresh()->attempt_text);
}

/**
 * Majukan jam jangkar seolah sesi yang berjalan sudah mengetik selama $seconds detik.
 *
 * Wajib dipanggil SEBELUM ping, dan itu bukan detail test melainkan aturan yang sedang diuji:
 * recordProgress membatasi tiap angka terhadap jam yang dipegang server, jadi ping dari sebuah
 * attempt yang jangkarnya baru sedetik lalu memang harus terpotong hampir habis. Nge-ping lebih
 * dulu lalu memundurkan jam menguji dunia yang tak pernah ada.
 */
function warAttemptAged(ClanWarModeClaim $claim, int $seconds): void
{
    $claim->update(['attempt_started_at' => now()->subSeconds($seconds)]);
}

it('scores a resumed words attempt over every second actually typed', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);

    // Sesi 1: 50 detik mengetik sungguhan, 205 keystroke -- lalu pemain me-refresh.
    warAttemptAged($claim, 52);
    pingWarAttempt($player, $claim, chars: 60, typedMs: 50_000, totalKeystrokes: 205, correctKeystrokes: 200);

    // Sesi 2: mount ulang (inilah yang menyegel buku besar sesi 1), lalu 20 detik lagi.
    $resumed = remountWarAttempt($player, $claim);

    app(SoloSessionGuard::class)->backdate(20);
    $claim->update(['attempt_started_at' => now()->subSeconds(78)]);

    // Klien kini hanya melaporkan sesinya SENDIRI -- karakter yang dipulihkan bukan miliknya.
    $resumed->call('saveResult', ['durationMs' => 20_000, 'totalKeystrokes' => 80, 'correctKeystrokes' => 80])
        ->assertRedirect(route('typing.result'));

    $result = TypingResult::latest('id')->first();

    // 70 detik mengetik benar-benar terjadi (50 + 20), bukan 20 yang dilihat sesi terakhir.
    expect((float) $result->duration_seconds)->toBeGreaterThanOrEqual(69.0)
        ->and((float) $result->duration_seconds)->toBeLessThanOrEqual(71.0);

    // 280 karakter benar / 5 / (70/60) = ~48 WPM. Jalur lama membacanya jauh di atas ini.
    expect((float) $result->net_wpm)->toBeLessThan(55.0);
});

it('carries characters typed before a refresh into the submitted total', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);
    warAttemptAged($claim, 52);
    pingWarAttempt($player, $claim, chars: 60, typedMs: 50_000, totalKeystrokes: 205, correctKeystrokes: 200);

    $resumed = remountWarAttempt($player, $claim);

    app(SoloSessionGuard::class)->backdate(20);
    $claim->update(['attempt_started_at' => now()->subSeconds(78)]);

    $resumed->call('saveResult', ['durationMs' => 20_000, 'totalKeystrokes' => 80, 'correctKeystrokes' => 80]);

    $result = TypingResult::latest('id')->first();

    // Pemain memang mengetik 285 keystroke sepanjang attempt ini; hasilnya harus mengatakan itu.
    // Kalau hanya 80 yang tercatat, resume menghukum pemain jujur alih-alih sekadar memulihkannya.
    expect($result->correct_chars + $result->incorrect_chars)->toBe(285);
});

it('keeps errors made before a refresh inside the submitted accuracy', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);

    // 205 keystroke, 180 benar: akurasi sesi 1 = 87.8%.
    warAttemptAged($claim, 52);
    pingWarAttempt($player, $claim, chars: 55, typedMs: 50_000, totalKeystrokes: 205, correctKeystrokes: 180);

    $resumed = remountWarAttempt($player, $claim);

    app(SoloSessionGuard::class)->backdate(20);
    $claim->update(['attempt_started_at' => now()->subSeconds(78)]);

    // Sesi 2 sempurna. Gabungannya 260/285 = 91.2% -- BUKAN 100%.
    $resumed->call('saveResult', ['durationMs' => 20_000, 'totalKeystrokes' => 80, 'correctKeystrokes' => 80]);

    expect((float) TypingResult::latest('id')->first()->accuracy)
        ->toBeGreaterThan(90.0)
        ->toBeLessThan(93.0);
});

it('does not let a refresh outscore the same work done in one sitting', function () {
    // Invarian yang sebenarnya diperjuangkan, dan satu-satunya yang tak bergantung pada bentuk
    // payload: kerja yang sama harus bernilai sama, dipotong refresh atau tidak.
    $onePass = function (): float {
        [$player, $claim] = warAttemptScenario('words', '50');

        $component = remountWarAttempt($player, $claim);

        app(SoloSessionGuard::class)->backdate(70);
        $claim->update(['attempt_started_at' => now()->subSeconds(78)]);

        $component->call('saveResult', ['durationMs' => 70_000, 'totalKeystrokes' => 285, 'correctKeystrokes' => 280]);

        return (float) $claim->refresh()->points;
    };

    $refreshed = function (): float {
        [$player, $claim] = warAttemptScenario('words', '50');

        remountWarAttempt($player, $claim);
        warAttemptAged($claim, 52);
        pingWarAttempt($player, $claim, chars: 60, typedMs: 50_000, totalKeystrokes: 205, correctKeystrokes: 200);

        $resumed = remountWarAttempt($player, $claim);

        app(SoloSessionGuard::class)->backdate(20);
        $claim->update(['attempt_started_at' => now()->subSeconds(78)]);

        $resumed->call('saveResult', ['durationMs' => 20_000, 'totalKeystrokes' => 80, 'correctKeystrokes' => 80]);

        return (float) $claim->refresh()->points;
    };

    $clean = $onePass();
    $reloaded = $refreshed();

    expect($clean)->toBeGreaterThan(0.0)
        ->and($reloaded)->toBeGreaterThan(0.0)
        // Boleh sedikit lebih rendah (refresh memang memakan waktu), tak pernah lebih tinggi.
        ->and($reloaded)->toBeLessThanOrEqual($clean * 1.02);
});

it('leaves a single-session attempt untouched by the ledger', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    $component = remountWarAttempt($player, $claim);

    // Ping sesi berjalan TIDAK boleh ikut dijumlahkan ke kiriman sesi yang sama -- kiriman itu
    // sudah memuat sesi ini secara utuh. Ini bug ganda-hitung yang paling mudah tak sengaja
    // ditulis: satu-satunya yang disegel adalah sesi yang ditinggalkan, dan penyegelnya adalah
    // page load berikutnya.
    pingWarAttempt($player, $claim, chars: 40, typedMs: 30_000, totalKeystrokes: 150, correctKeystrokes: 148);

    app(SoloSessionGuard::class)->backdate(70);
    $claim->update(['attempt_started_at' => now()->subSeconds(78)]);

    $component->call('saveResult', ['durationMs' => 70_000, 'totalKeystrokes' => 285, 'correctKeystrokes' => 280])
        ->assertRedirect(route('typing.result'));

    $result = TypingResult::latest('id')->first();

    expect((float) $result->duration_seconds)->toBe(70.0)
        ->and($result->correct_chars + $result->incorrect_chars)->toBe(285);
});

it('bounds a forged ledger by the wall clock the attempt has really held', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);

    // Jangkarnya baru 10 detik lalu. Sebuah ping yang mengaku 5 menit mengetik dan 4000
    // keystroke sedang mengarang waktu yang tak pernah lewat -- dan menaikkan waktu justru
    // MENURUNKAN WPM, jadi arah serangannya adalah mengarang KARAKTER lalu memendekkan waktu.
    // Keduanya harus terpotong oleh jam yang dipegang server.
    $claim->update(['attempt_started_at' => now()->subSeconds(10)]);

    pingWarAttempt($player, $claim, chars: 100, typedMs: 300_000, totalKeystrokes: 4000, correctKeystrokes: 4000);

    $claim->refresh();

    // Tak lebih dari 10 detik yang bisa dibukukan, dan tak lebih karakter dari yang bisa
    // diketik dalam 10 detik itu (SoloSessionGuard::MAX_CHARS_PER_SECOND). Batas atasnya dihitung
    // dari 11 detik, bukan 10: jangkarnya jam sungguhan dan terus berjalan selama request test,
    // jadi mematoknya persis di 10 berarti menguji ketepatan waktu, bukan aturannya. Yang
    // diperjuangkan adalah jarak ke angka palsunya -- 4000 karakter menyusut jadi ratusan.
    expect((float) $claim->attempt_live_ms)->toBeLessThanOrEqual(11_000.0)
        ->and((int) $claim->attempt_live_total_chars)
        ->toBeLessThanOrEqual((int) (11 * SoloSessionGuard::MAX_CHARS_PER_SECOND));
});

it('never lowers a ledger already banked for the running session', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);
    warAttemptAged($claim, 60);

    pingWarAttempt($player, $claim, chars: 60, typedMs: 40_000, totalKeystrokes: 200, correctKeystrokes: 195);
    // Mundur adalah cara memutar ulang bentangan teks yang mudah -- ditolak, sama seperti
    // attempt_chars yang hanya pernah naik.
    pingWarAttempt($player, $claim, chars: 20, typedMs: 5_000, totalKeystrokes: 20, correctKeystrokes: 20);

    $claim->refresh();

    expect((float) $claim->attempt_live_ms)->toBe(40_000.0)
        ->and((int) $claim->attempt_live_total_chars)->toBe(200)
        ->and((int) $claim->attempt_chars)->toBe(60);
});

it('seals the live session into the carried ledger on the next page load', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);
    warAttemptAged($claim, 60);

    pingWarAttempt($player, $claim, chars: 60, typedMs: 40_000, totalKeystrokes: 200, correctKeystrokes: 195);

    expect((int) $claim->refresh()->attempt_carried_total_chars)->toBe(0);

    remountWarAttempt($player, $claim);

    $claim->refresh();

    // Sesi yang ditinggalkan kini permanen; slot live kosong lagi untuk sesi yang baru dimulai.
    expect((float) $claim->attempt_carried_ms)->toBe(40_000.0)
        ->and((int) $claim->attempt_carried_total_chars)->toBe(200)
        ->and((int) $claim->attempt_carried_correct_chars)->toBe(195)
        ->and((float) $claim->attempt_live_ms)->toBe(0.0)
        ->and((int) $claim->attempt_live_total_chars)->toBe(0);
});

it('refuses an attempt-progress ping for a claim the caller does not own', function () {
    [, $claim] = warAttemptScenario('words', '50');

    $outsider = User::factory()->create();

    pingWarAttempt($outsider, $claim, chars: 90, typedMs: 60_000, totalKeystrokes: 300, correctKeystrokes: 300)
        ->assertForbidden();

    expect((int) $claim->refresh()->attempt_chars)->toBe(0)
        ->and((float) $claim->attempt_live_ms)->toBe(0.0);
});

it('reports war progress often enough that a refresh loses at most a word', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);
    warAttemptAged($claim, 60);

    // Posisi disimpan dalam KARAKTER, bukan persen bulat. Pada teks ~280 karakter, 1% adalah
    // hampir 3 karakter, dan pembulatan turun ke batas kata membuang sisanya -- itu sebabnya
    // pemain mendarat beberapa kata sebelum tempatnya berhenti.
    pingWarAttempt($player, $claim, chars: 137, typedMs: 40_000, totalKeystrokes: 137, correctKeystrokes: 137);

    $lock = remountWarAttempt($player, $claim)->get('warLock');

    $textLength = warAttemptTextLength($claim);

    expect($textLength)->toBeGreaterThan(100)
        // Klien menerima posisi karakter yang tepat, lalu ia sendiri yang mundur ke batas kata.
        ->and($lock['chars'])->toBe(137);
});

it('holds a resumed time slot to the clock it has really burned', function () {
    [$player, $claim] = warAttemptScenario('time', '60');

    remountWarAttempt($player, $claim);
    warAttemptAged($claim, 42);
    pingWarAttempt($player, $claim, chars: 50, typedMs: 40_000, totalKeystrokes: 160, correctKeystrokes: 160);

    $resumed = remountWarAttempt($player, $claim);

    // Slot 60 detik yang jangkarnya sudah berjalan 72 detik: COUNTDOWN_GRACE memberi ruang
    // page load, dan tanpa penjumlahan buku besar, 72 detik kerja itu dinilai atas 60.
    app(SoloSessionGuard::class)->backdate(25);
    $claim->update(['attempt_started_at' => now()->subSeconds(72)]);

    $resumed->call('saveResult', ['durationMs' => 25_000, 'totalKeystrokes' => 100, 'correctKeystrokes' => 100]);

    $result = TypingResult::latest('id')->first();

    // 65 detik mengetik (40 + 25) melampaui nominal slot, jadi itulah penyebutnya -- bukan 60.
    expect((float) $result->duration_seconds)->toBeGreaterThanOrEqual(64.0);
});

it('leaves survival out of the ledger entirely', function () {
    [$player, $claim] = warAttemptScenario('survival', 'hard');

    remountWarAttempt($player, $claim);

    // Survival tak pernah resume: kurva stamina tak bisa dilanjutkan, jadi ia me-restart di
    // dalam anggaran yang menyusut (ClanWarAttempt::SURVIVAL_BUDGET_SECONDS). Menjumlahkan
    // buku besar di sini justru akan MENAIKKAN skornya, satu-satunya hal yang survival tak
    // boleh berikan atas sebuah percobaan yang ditinggalkan.
    pingWarAttempt($player, $claim, chars: 80, typedMs: 40_000, totalKeystrokes: 200, correctKeystrokes: 200);

    $claim->refresh();

    expect((float) $claim->attempt_live_ms)->toBe(0.0)
        ->and((int) $claim->attempt_live_total_chars)->toBe(0)
        ->and(app(ClanWarAttempt::class)->open($claim, 'english')->resumeChars)->toBe(0);
});
