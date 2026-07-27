<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\AntiCheatService;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * Consistency as an anti-cheat signal (report §7.2). A near-flat per-second WPM curve at
 * high speed is a bot posting a fixed WPM each tick -- no human is that even -- so it is
 * rejected. The floor is speed-gated: high consistency at LOW WPM is a careful beginner and
 * must still save. computeConsistency() runs server-side on the reported wpmHistory; the
 * client can't just assert a "human" score, it has to supply a curve that recomputes to one.
 */
function playConsistency(User $user, string $sub = '30')
{
    return Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', $sub);
}

it('rejects a perfectly flat WPM curve at high speed (the scripted 185-for-120s case)', function () {
    $user = User::factory()->create();

    $c = playConsistency($user, '120');
    app(SoloSessionGuard::class)->backdate(120);

    // 1850 correct chars over 120s = 185 WPM, and a wpmHistory that is EXACTLY 185 every
    // second -> consistency 100%. High speed + perfect steadiness = impossible for a human.
    $c->call('saveResult', [
        'durationMs' => 120000,
        'totalKeystrokes' => 1850,
        'correctKeystrokes' => 1850,
        'maxIdleMs' => 100,
        'wpmHistory' => array_fill(0, 120, 185),
    ])->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('keeps a high-speed run that has a natural, uneven WPM curve', function () {
    $user = User::factory()->create();

    $c = playConsistency($user, '120');
    app(SoloSessionGuard::class)->backdate(120);

    // ~150 net WPM (1500 chars / 120s = 12.5 cps, within the 20 cps char ceiling), still
    // above the consistency speed gate (120).
    //
    // The swing here is deliberately SMALL (+/- 4 WPM). It used to be +/- 32, which is not
    // an elite typist holding a steady pace -- it is someone stalling and sprinting. Passing
    // the gate with that curve proved nothing, and it is why the gate could reject real
    // players for two releases without a single test going red. A fast, even human belongs
    // on the honest side of this line, so that is what the test now asserts.
    $natural = [];
    for ($i = 0; $i < 120; $i++) {
        $natural[] = 150 + ($i % 5 - 2) * 2; // swings roughly 146..154
    }

    $c->call('saveResult', [
        'durationMs' => 120000,
        'totalKeystrokes' => 1500,
        'correctKeystrokes' => 1500,
        'maxIdleMs' => 100,
        'wpmHistory' => $natural,
    ]);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(1);
});

it('keeps a perfectly steady but SLOW run (a careful beginner is not a cheat)', function () {
    $user = User::factory()->create();

    $c = playConsistency($user, '60');
    app(SoloSessionGuard::class)->backdate(60);

    // 300 chars over 60s = 60 WPM, flat curve -> consistency 100% but WELL below the speed
    // gate (120). This is exactly the exempt case: it must still save.
    $c->call('saveResult', [
        'durationMs' => 60000,
        'totalKeystrokes' => 300,
        'correctKeystrokes' => 300,
        'maxIdleMs' => 100,
        'wpmHistory' => array_fill(0, 60, 60),
    ]);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(1);
});

it('keeps a fast SHORT run whose consistency is high only because the sample is tiny', function () {
    // The reported bug, end to end: `words/10` in Indonesian, ~174 WPM, rejected with
    // "rejected by server validation (implausible)".
    //
    // wpmHistory is sampled once per second, so a 4-second test yields THREE samples. Over
    // three samples 1 - sd/mean measures sample size, not steadiness: 170/174/176 -- an
    // ordinary human curve -- scores 99 and cleared the 97 floor. The run below is honest
    // and must save.
    $user = User::factory()->create();

    $c = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'words', '10');
    app(SoloSessionGuard::class)->backdate(4);

    // 57 chars (a 10-word Indonesian text) in 3.93s = ~174 WPM.
    $c->call('saveResult', [
        'durationMs' => 3930,
        'totalKeystrokes' => 57,
        'correctKeystrokes' => 57,
        'maxIdleMs' => 300,
        'wpmHistory' => [170, 174, 176],
    ]);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(1);
});

it('still rejects a flat curve once the sample is long enough to judge', function () {
    // The floor must not simply be disabled: with enough samples a perfectly flat high-speed
    // curve is still a bot. This is the guard against "fixed the false positive by deleting
    // the check" -- 120 identical samples is far past MIN_CONSISTENCY_SAMPLES.
    $user = User::factory()->create();

    $c = playConsistency($user, '120');
    app(SoloSessionGuard::class)->backdate(120);

    $c->call('saveResult', [
        'durationMs' => 120000,
        'totalKeystrokes' => 1850,
        'correctKeystrokes' => 1850,
        'maxIdleMs' => 100,
        'wpmHistory' => array_fill(0, 120, 185),
    ]);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0);
});

it('does not flag a run too short to score consistency', function () {
    // < 2 samples -> computeConsistency returns null -> isImpossiblyConsistent is false.
    $anti = app(AntiCheatService::class);

    expect($anti->isImpossiblyConsistent(null, 200.0))->toBeFalse()
        ->and($anti->isImpossiblyConsistent(100, 200.0))->toBeTrue()
        ->and($anti->isImpossiblyConsistent(100, 60.0))->toBeFalse()   // low WPM exempt
        ->and($anti->isImpossiblyConsistent(90, 200.0))->toBeFalse();  // not flat enough
});

it('leaves the 97-98 band to humans and flags only a near-identical curve', function () {
    // Pins the raised floor. 97 and 98 are reachable by a real typist holding a steady pace
    // (a 120s run varying by under +/-4 WPM lands there), so they must NOT be treated as
    // cheating; 100 is the fixed-WPM bot and must be.
    $anti = app(AntiCheatService::class);

    expect($anti->isImpossiblyConsistent(97, 200.0))->toBeFalse()
        ->and($anti->isImpossiblyConsistent(98, 200.0))->toBeFalse()
        ->and($anti->isImpossiblyConsistent(99, 200.0))->toBeTrue()
        ->and($anti->isImpossiblyConsistent(100, 200.0))->toBeTrue();
});
