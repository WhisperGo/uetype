<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * The "patient bot" (report §4.1): the attack that survived the SoloSessionGuard fix. It
 * waits out the real duration (a free sleep()), then forges a full-length payload. Before
 * §7.3 + §7.5 it landed ~200 WPM on the leaderboard. These tests pin that it no longer does
 * -- caught first by the tightened char ceiling, and whatever slips under it is held for
 * review, never public.
 */
function playPatient(User $user, string $sub, int $durationMs, int $chars, array $extra = [])
{
    $c = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', $sub);
    app(SoloSessionGuard::class)->backdate($durationMs / 1000);

    return $c->call('saveResult', array_merge([
        'durationMs' => $durationMs,
        'totalKeystrokes' => $chars,
        'correctKeystrokes' => $chars,
        'maxIdleMs' => 100,
    ], $extra));
}

it('rejects the exact §4.1 payload (500 chars in 30s = 200 WPM) at the char ceiling', function () {
    $user = User::factory()->create();

    // 500 chars / 30s = 16.7 cps, now over the 13 cps (= 440-char) ceiling -> refused
    // outright, never saved. This is the forged 200-WPM run from the report.
    playPatient($user, '30', 30000, 500)->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('holds a patient bot that paces UNDER the char ceiling for review (never public)', function () {
    $user = User::factory()->create();

    // The smart patient bot aims just under the ceiling: 430 chars / 30s ~= 172 WPM, which
    // clears the char guard. But with no history it's a >=150 debut -> held `pending`, so it
    // stays off the leaderboard and doesn't become the player's PB.
    playPatient($user, '30', 30000, 430);

    $r = TypingResult::where('user_id', $user->id)->latest('id')->first();

    expect($r)->not->toBeNull()
        ->and($r->review_status)->toBe(TypingResult::REVIEW_PENDING)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('caps the best a patient bot can even CLAIM well below world-record territory', function () {
    // Sanity pin on §7.3: the char ceiling now limits a full-duration forge to ~176 WPM in
    // time 30, not the ~200 it used to reach. Anything higher is refused before scoring.
    $user = User::factory()->create();

    // 445 chars / 30s ~= 178 WPM: just over the 440 ceiling -> refused.
    playPatient($user, '30', 30000, 445)->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0);
});
