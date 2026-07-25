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

    // Same speed (~185 net), but the per-second curve fluctuates like real typing -> the
    // consistency score drops below the floor, so the run is a legit elite result.
    $wobbly = [];
    for ($i = 0; $i < 120; $i++) {
        $wobbly[] = 185 + ($i % 5 - 2) * 18; // swings roughly 149..221
    }

    $c->call('saveResult', [
        'durationMs' => 120000,
        'totalKeystrokes' => 1850,
        'correctKeystrokes' => 1850,
        'maxIdleMs' => 100,
        'wpmHistory' => $wobbly,
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

it('does not flag a run too short to score consistency', function () {
    // < 2 samples -> computeConsistency returns null -> isImpossiblyConsistent is false.
    $anti = app(AntiCheatService::class);

    expect($anti->isImpossiblyConsistent(null, 200.0))->toBeFalse()
        ->and($anti->isImpossiblyConsistent(100, 200.0))->toBeTrue()
        ->and($anti->isImpossiblyConsistent(100, 60.0))->toBeFalse()   // low WPM exempt
        ->and($anti->isImpossiblyConsistent(90, 200.0))->toBeFalse();  // not flat enough
});
