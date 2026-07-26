<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * The most important test in the anti-cheat report (§9): every gate added here risks catching
 * a HONEST player, and a false positive is more costly than letting one cheater through. This
 * pins that real players -- slow beginners, steady improvers, fast-but-human typists -- all
 * still save and CLEAR across the consistency gate (§7.2), the tighter char ceiling (§7.3),
 * the keystroke-timing check (§7.1), and the longitudinal review (§7.5).
 */
function playHonest(User $user, string $sub, int $durationMs, int $total, int $correct, array $extra = [])
{
    $c = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', $sub);
    app(SoloSessionGuard::class)->backdate($durationMs / 1000);

    return $c->call('saveResult', array_merge([
        'durationMs' => $durationMs,
        'totalKeystrokes' => $total,
        'correctKeystrokes' => $correct,
        'maxIdleMs' => 900,
    ], $extra));
}

/** Uneven, human-looking intervals with occasional thinking pauses. */
function humanCurve(int $n): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $out[] = ($i % 12 === 0) ? 700 : 160 + ($i * 47) % 200;
    }

    return $out;
}

it('saves a slow beginner with many typos and long pauses', function () {
    $user = User::factory()->create();

    // 40 WPM, ~85% accuracy, uneven timing -> everything a real learner does.
    playHonest($user, '60', 60000, 235, 200, ['keyIntervals' => humanCurve(200), 'keyStrokeCount' => 235]);

    $r = TypingResult::where('user_id', $user->id)->first();

    expect($r)->not->toBeNull()
        ->and($r->review_status)->toBe(TypingResult::REVIEW_CLEAR);
});

it('saves a mid-level typist at ~90 WPM with natural variance', function () {
    $user = User::factory()->create();

    // 450 chars / 60s = 90 WPM, well within the char ceiling and below the consistency gate.
    playHonest($user, '60', 60000, 470, 450, ['keyIntervals' => humanCurve(300), 'keyStrokeCount' => 470]);

    $r = TypingResult::where('user_id', $user->id)->first();

    expect($r)->not->toBeNull()
        ->and($r->review_status)->toBe(TypingResult::REVIEW_CLEAR)
        ->and((float) $user->fresh()->highest_wpm)->toBe(90.0);
});

it('saves a fast-but-human typist at ~140 WPM (uneven curve, right under the ceiling)', function () {
    $user = User::factory()->create();

    // Give them an established ~135 history so 140 reads as steady form, not a debut spike.
    for ($i = 0; $i < 6; $i++) {
        TypingResult::create([
            'user_id' => $user->id, 'mode' => 'time', 'mode_config' => '30', 'language' => 'en',
            'net_wpm' => 135, 'raw_wpm' => 140, 'accuracy' => 97,
            'correct_chars' => 338, 'incorrect_chars' => 6, 'duration_seconds' => 30,
            'review_status' => TypingResult::REVIEW_CLEAR,
        ]);
    }

    // 350 chars / 30s = 140 WPM (12 cps < 13 ceiling); above the consistency SPEED gate but
    // the curve is uneven, so consistency stays below the floor -> clears.
    $wobble = [];
    for ($i = 0; $i < 60; $i++) {
        $wobble[] = 140 + ($i % 5 - 2) * 15;
    }

    playHonest($user, '30', 30000, 356, 350, [
        'wpmHistory' => $wobble,
        'keyIntervals' => humanCurve(300),
        'keyStrokeCount' => 356,
    ]);

    $r = TypingResult::where('user_id', $user->id)->latest('id')->first();

    expect($r->review_status)->toBe(TypingResult::REVIEW_CLEAR)
        ->and((float) $user->fresh()->highest_wpm)->toBe(140.0);
});

it('saves a steady improver: each session a small step over the last', function () {
    $user = User::factory()->create();

    // Three honest sessions climbing 80 -> 88 -> 95, each within the +40% window -> all clear.
    foreach ([[200, 80], [220, 88], [238, 95]] as [$chars, $wpm]) {
        playHonest($user, '30', 30000, $chars + 6, $chars, [
            'keyIntervals' => humanCurve(min(300, $chars)),
            'keyStrokeCount' => $chars + 6,
        ]);
    }

    $rows = TypingResult::where('user_id', $user->id)->pluck('review_status')->all();

    expect($rows)->each->toBe(TypingResult::REVIEW_CLEAR);
    expect((float) $user->fresh()->highest_wpm)->toBe(95.2);
});
