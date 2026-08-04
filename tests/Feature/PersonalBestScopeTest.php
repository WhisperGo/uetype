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

    return $component->call('saveResult', [
        'durationMs' => $durationMs,
        'totalKeystrokes' => $chars,
        'correctKeystrokes' => $chars,
    ]);
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

    // Established history around 150 WPM so a new best reads as real progress, not an
    // anomaly the longitudinal review (§7.5) would hold pending.
    for ($i = 0; $i < 6; $i++) {
        bestScoreRow($user, 'time', '30', 150);
    }

    // ~172 WPM (430 chars / 30s, within the 20 cps = 650-char ceiling), a plausible step
    // over the 150 history.
    playBestScore($user, 'time', '30', 30000, 430);

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

    // Established ~150 history in time 30 so the career-best bump is genuine progress, not an
    // anomaly the longitudinal review would hold pending (which withholds the PB).
    for ($i = 0; $i < 6; $i++) {
        bestScoreRow($user, 'time', '30', 150);
    }

    // ~170 WPM (425 chars / 30s), a plausible step over 150 that beats the 100 career best.
    playBestScore($user, 'time', '30', 30000, 425);

    expect((float) $user->fresh()->highest_wpm)->toBe(170.0);
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

/**
 * Aturan "hasil ini memecahkan rekor?" hidup di SATU tempat: User::recordPersonalBest().
 *
 * Dulu ia ditulis dua kali -- di TypingEngine::saveResult() saat hasil pertama kali disimpan,
 * dan di ReviewQueue::approve() saat hasil yang ditahan akhirnya diloloskan -- sebagai dua
 * salinan tiga syarat yang wajib selalu identik. Keduanya tak terlihat berhubungan di kode,
 * dan arah kegagalannya senyap: ubah aturannya di satu sisi, sisi lain tetap menjawab dengan
 * versi lama, dan tak ada yang tahu sampai ada pemain yang protes soal PB-nya.
 */
it('defines a personal best in one place, for both the save and the approve path', function () {
    $user = User::factory()->create(['highest_wpm' => 50]);

    $make = fn (array $attributes) => TypingResult::create(array_merge([
        'user_id' => $user->id, 'mode' => 'time', 'mode_config' => '30',
        'net_wpm' => 90, 'raw_wpm' => 95, 'accuracy' => 97,
        'correct_chars' => 250, 'incorrect_chars' => 5, 'duration_seconds' => 30,
    ], $attributes));

    // Survival tak pernah menaikkan rekor WPM: dicapai di bawah tekanan stamina, dan papannya
    // dinilai dari durasi.
    expect($user->recordPersonalBest($make(['mode' => 'survival', 'mode_config' => 'hard'])))->toBeFalse()
        // Hasil yang masih ditahan review juga tidak: angka ber-flag tak boleh mendarat di
        // profil sebelum ada manusia yang meloloskannya.
        ->and($user->recordPersonalBest($make(['review_status' => TypingResult::REVIEW_PENDING])))->toBeFalse()
        ->and((float) $user->fresh()->highest_wpm)->toBe(50.0);

    // Hasil bersih yang benar-benar lebih cepat: barulah rekornya bergerak.
    expect($user->recordPersonalBest($make([])))->toBeTrue()
        ->and((float) $user->fresh()->highest_wpm)->toBe(90.0)
        // ...dan hasil yang lebih lambat sesudahnya tidak menurunkannya.
        ->and($user->recordPersonalBest($make(['net_wpm' => 60])))->toBeFalse()
        ->and((float) $user->fresh()->highest_wpm)->toBe(90.0);
});
