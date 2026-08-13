<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use App\Services\SurvivalPlausibility;
use Livewire\Livewire;

/**
 * Stamina Survival disimulasikan SEPENUHNYA di browser: server hanya diberi tahu berapa lama
 * pemain bertahan, tak pernah menyaksikannya bertahan hidup. Di seluruh proyek ini server
 * selalu menghitung ulang yang penting; ini satu-satunya besaran berskor yang tak bisa, jadi
 * klien yang menghapus kondisi matinya sendiri bebas melaporkan durasi apa pun.
 *
 * Di solo itu nyaris tak berarti (durasi lebih panjang justru menurunkan WPM). Di CLAN WAR ia
 * hadiah utamanya: `survival/hard` berplafon tertinggi di grid (150) dan poinnya naik seiring
 * durasi sampai 90 detik.
 *
 * Yang diuji di sini adalah LANTAI FISIK, bukan simulasi: stamina terkuras oleh waktu dan
 * terisi per karakter benar, jadi bertahan lebih lama menuntut mengetik lebih banyak -- dan
 * batas bawahnya bisa dihitung, apa pun yang terjadi di antaranya.
 */
it('asks nothing of a short run, which the starting bar alone covers', function () {
    $floor = app(SurvivalPlausibility::class);

    expect($floor->minimumCorrectChars('hard', 5))->toBe(0.0)
        ->and($floor->reviewReasonFor('hard', 5, 0))->toBeNull();
});

it('demands more typing the longer the run claims to be', function () {
    $floor = app(SurvivalPlausibility::class);

    $at30 = $floor->minimumCorrectChars('hard', 30);
    $at60 = $floor->minimumCorrectChars('hard', 60);
    $at90 = $floor->minimumCorrectChars('hard', 90);

    // Drain-nya berakselerasi, jadi tuntutannya naik lebih cepat daripada linear.
    expect($at30)->toBeGreaterThan(0.0)
        ->and($at60)->toBeGreaterThan($at30 * 2)
        ->and($at90)->toBeGreaterThan($at60);
});

it('is gentler on easy than on hard for the same duration', function () {
    $floor = app(SurvivalPlausibility::class);

    expect($floor->minimumCorrectChars('easy', 60))
        ->toBeLessThan($floor->minimumCorrectChars('hard', 60));
});

it('judges nothing on a difficulty it does not know', function () {
    // Mode yang tak dikenali tak boleh dinilai dengan aturan ini -- menebak berarti menuduh.
    expect(app(SurvivalPlausibility::class)->reviewReasonFor('nightmare', 90, 0))->toBeNull();
});

it('automatically rejects a long survival that nobody could have typed their way through', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'survival', 'hard');

    // Klien "bertahan" 90 detik penuh di hard sambil hanya mengetik 60 karakter. Waktunya nyata
    // (jam server digeser mundur, jadi gerbang durasi lolos) -- yang mustahil adalah bertahan
    // hidup selama itu dengan isi ulang sesedikit itu.
    app(SoloSessionGuard::class)->backdate(90);

    $component->call('saveResult', [
        'durationMs' => 90000, 'totalKeystrokes' => 60, 'correctKeystrokes' => 60,
    ]);

    $result = TypingResult::where('user_id', $user->id)->first();

    // Ini invariant fisik yang sudah sangat konservatif, sehingga tidak dibuat pending tanpa
    // resolver. Backend menyelesaikannya langsung dan memberi pesan faktual kepada pemain.
    expect($result)->toBeNull()
        ->and(session('result_rejected'))->toBe(__('typing.result_survival_unverified'));
});

it('leaves an honest survival run alone', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'survival', 'hard');

    app(SoloSessionGuard::class)->backdate(60);

    // 60 detik di hard menuntut ~230 karakter benar; 400 adalah pemain cepat yang wajar
    // (sekitar 80 WPM) dan harus lewat tanpa disentuh.
    $component->call('saveResult', [
        'durationMs' => 60000, 'totalKeystrokes' => 410, 'correctKeystrokes' => 400,
    ]);

    $result = TypingResult::where('user_id', $user->id)->first();

    expect($result)->not->toBeNull()
        ->and($result->review_status)->toBe(TypingResult::REVIEW_CLEAR);
});

it('keeps the server presets identical to the ones the browser plays by', function () {
    // Nilainya disalin, dan salinan bisa hanyut. Alternatifnya lebih buruk: klien butuh angka
    // ini tiap frame, server butuh untuk membatasi klaim, dan mengirimkannya dari klien berarti
    // terdakwa yang menentukan hukumnya. Jadi salinannya dipatok di sini -- berubah di sisi mana
    // pun, suite yang merah, bukan lantai yang diam-diam melonggar.
    $js = file_get_contents(resource_path('js/typing-game.js'));

    $expected = [
        'easy' => ['sStart' => 120, 'graceSec' => 4, 'dStart' => 3.0, 'dAccel' => 0.11, 'refill' => 2.4],
        'medium' => ['sStart' => 100, 'graceSec' => 3, 'dStart' => 3.8, 'dAccel' => 0.20, 'refill' => 1.9],
        'hard' => ['sStart' => 70, 'graceSec' => 0, 'dStart' => 5.5, 'dAccel' => 0.40, 'refill' => 1.3],
    ];

    foreach ($expected as $difficulty => $values) {
        expect($js)->toMatch("/{$difficulty}:\s*\{[^}]*sStart:\s*{$values['sStart']}\b/")
            ->and($js)->toMatch("/{$difficulty}:\s*\{[^}]*graceSec:\s*{$values['graceSec']}\b/")
            ->and($js)->toMatch("/{$difficulty}:\s*\{[^}]*dStart:\s*".preg_quote((string) $values['dStart'], '/').'/')
            ->and($js)->toMatch("/{$difficulty}:\s*\{[^}]*refill:\s*".preg_quote((string) $values['refill'], '/').'/');
    }

    // Faktor perisai terbaik: satu-satunya angka lain yang ikut menentukan lantainya.
    expect($js)->toMatch('/SHIELD_DRAIN_FACTOR\s*=\s*0\.35/');
});
