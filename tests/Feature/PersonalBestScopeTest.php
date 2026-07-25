<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Rekor pribadi mode standard dulu memakai SATU angka global (users.highest_wpm) untuk
 * time DAN words, semua sub-mode sekaligus. Itu apples-to-oranges: tes pendek selalu
 * menghasilkan WPM lebih tinggi, jadi hasil `time 120` praktis selalu kalah dari rekor
 * yang dibuat di `time 15` -- layar hasil terus menampilkan selisih negatif.
 *
 * Survival sudah lama benar (rekornya diturunkan per mode+config dari typing_results);
 * test ini mengunci mode standard mengikuti pola yang sama.
 */
function bestScoreRow(User $user, string $mode, string $config, float $wpm): TypingResult
{
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm + 5,
        'accuracy' => 96,
        'correct_chars' => 300,
        'incorrect_chars' => 10,
        'duration_seconds' => 30,
    ]);
}

/** Mainkan satu sesi solo yang sah: mode di-set, lalu jam server dimundurkan seolah benar-benar diketik. */
function playBestScore(User $user, string $main, string $sub, int $durationMs, int $chars)
{
    $component = Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', $main, $sub);

    app(SoloSessionGuard::class)->backdate($durationMs / 1000);

    return $component->call('saveResult', $durationMs, $chars, $chars);
}

it('treats each mode config as its own record', function () {
    $user = User::factory()->create();

    // Rekor lama 100 WPM di time 15 -- tes sprint, wajar tinggi.
    bestScoreRow($user, 'time', '15', 100);
    $user->update(['highest_wpm' => 100]);

    // Sekarang main time 60 dengan 50 WPM. Dulu ini kalah dari 100 dan tampil "-50 vs record".
    // Padahal ini rekor PERTAMA di time 60, jadi seharusnya personal best.
    playBestScore($user, 'time', '60', 60000, 250);

    expect(session('typing_result')['isPersonalBest'])->toBeTrue();
});

it('keeps time and words records separate', function () {
    $user = User::factory()->create();

    bestScoreRow($user, 'time', '30', 120);
    $user->update(['highest_wpm' => 120]);

    // words 10 belum pernah dimainkan -> hasil pertamanya adalah rekornya sendiri.
    playBestScore($user, 'words', '10', 30000, 100);

    expect(session('typing_result')['isPersonalBest'])->toBeTrue();
});

it('only calls it a personal best when it beats the record for that same config', function () {
    $user = User::factory()->create();

    // Rekor 80 WPM di time 30 (400 karakter benar / 30 detik).
    bestScoreRow($user, 'time', '30', 80);

    // Sesi baru di config yang SAMA, lebih lambat -> bukan personal best.
    playBestScore($user, 'time', '30', 30000, 200); // ~40 WPM

    expect(session('typing_result')['isPersonalBest'])->toBeFalse()
        ->and((float) session('typing_result')['previousBest'])->toBe(80.0);
});

it('recognises a genuine personal best in the same config', function () {
    $user = User::factory()->create();

    bestScoreRow($user, 'time', '30', 40);

    // 500 karakter / 30 detik = 200 WPM, di atas rekor 40 untuk config yang sama.
    playBestScore($user, 'time', '30', 30000, 500);

    expect(session('typing_result')['isPersonalBest'])->toBeTrue();
});

/**
 * highest_wpm TIDAK dibuang: ia tetap "rekor karier" lintas mode yang dipakai kartu profil,
 * daftar teman, dan achievement 100/150/200 WPM. Yang berubah hanya dasar perbandingan di
 * layar hasil.
 */
it('still tracks the career best across modes', function () {
    $user = User::factory()->create();

    bestScoreRow($user, 'time', '15', 100);
    $user->update(['highest_wpm' => 100]);

    // PB baru di time 60 (rekor pertama di config itu), tapi angkanya di bawah rekor karier.
    playBestScore($user, 'time', '60', 60000, 250);

    expect((float) $user->fresh()->highest_wpm)->toBe(100.0);

    // Sesi yang benar-benar melampaui rekor karier tetap menaikkannya.
    playBestScore($user, 'time', '30', 30000, 500); // 200 WPM

    expect((float) $user->fresh()->highest_wpm)->toBe(200.0);
});

/**
 * Layar hasil tak lagi menampilkan "-39 vs record 70.2": rekor hanya disebut saat
 * pemain benar-benar memecahkannya, bukan sebagai pengingat kekalahan tiap sesi.
 */
function bestScorePage(User $user, bool $isPersonalBest)
{
    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 31.0, 'rawWpm' => 33, 'accuracy' => 94.0, 'time' => 60,
        'mode' => 'time', 'subMode' => '60', 'score' => null,
        'totalKeystrokes' => 320, 'correctKeystrokes' => 300, 'incorrectKeystrokes' => 20,
        'wpmHistory' => [30, 31], 'rawHistory' => [32, 33],
        'missedChars' => [], 'xpEarned' => 10,
        'isPersonalBest' => $isPersonalBest, 'previousBest' => 70.2, 'consistency' => 90,
        'levelData' => ['level' => 2, 'progress' => 40, 'needed' => 100, 'next_level' => 3],
        'drainEventCount' => 0, 'survivalPreviousBest' => null, 'isSurvivalPersonalBest' => false,
        'ghostResult' => null, 'afk' => false,
    ]]);

    return actingAs($user)->get('/result');
}

it('says nothing about the record on a session that did not break it', function () {
    bestScorePage(User::factory()->create(), isPersonalBest: false)
        ->assertOk()
        ->assertDontSee('vs record')
        ->assertDontSee(__('result.new_personal_best'));
});

it('celebrates the record only when it is actually broken', function () {
    bestScorePage(User::factory()->create(), isPersonalBest: true)
        ->assertOk()
        ->assertSee(__('result.new_personal_best'))
        ->assertDontSee('vs record');
});
