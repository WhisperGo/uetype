<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * Fail-safe rollout (§7.1 / §10). A cached OLD client bundle sends no keyIntervals and no
 * keyStrokeCount. That must never break a submit or reject an honest player -- the timing
 * check only logs today, and an empty sample is "no data". This pins that contract so a
 * future change can't silently start rejecting on missing timing.
 */
it('saves an honest run from an old bundle that sends no keyIntervals', function () {
    $user = User::factory()->create();

    $c = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '30');
    app(SoloSessionGuard::class)->backdate(30);

    // Payload WITHOUT keyIntervals / keyStrokeCount -- exactly what a pre-§7.1 bundle sends.
    $c->call('saveResult', [
        'durationMs' => 30000,
        'totalKeystrokes' => 200,
        'correctKeystrokes' => 190,
        'maxIdleMs' => 800,
    ]);

    $r = TypingResult::where('user_id', $user->id)->first();

    expect($r)->not->toBeNull()
        ->and($r->review_status)->toBe(TypingResult::REVIEW_CLEAR);
});

it('saves an honest slow beginner run with typos and pauses (no false positive)', function () {
    $user = User::factory()->create();

    $c = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '60');
    app(SoloSessionGuard::class)->backdate(60);

    // 40 WPM, 88% accuracy, uneven timing, a couple of long thinking pauses -> everything a
    // real learner does. Must save, clear, and count.
    $intervals = [];
    for ($i = 0; $i < 200; $i++) {
        $intervals[] = ($i % 13 === 0) ? 900 : 180 + ($i * 53) % 220;
    }

    $c->call('saveResult', [
        'durationMs' => 60000,
        'totalKeystrokes' => 230,
        'correctKeystrokes' => 202,
        'maxIdleMs' => 900,
        'keyIntervals' => $intervals,
        'keyStrokeCount' => 230,
    ]);

    $r = TypingResult::where('user_id', $user->id)->first();

    expect($r)->not->toBeNull()
        ->and($r->review_status)->toBe(TypingResult::REVIEW_CLEAR);
});
