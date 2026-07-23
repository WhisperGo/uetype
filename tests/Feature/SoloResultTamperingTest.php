<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * saveResult() used to take the client's duration and keystroke counts at face value.
 * Recomputing WPM from those numbers is not verification -- a forged payload recomputes
 * to exactly the fake figure it claims, so 299 WPM sailed through every check and became
 * the user's highest_wpm. These tests pin the server-side reference (SoloSessionGuard).
 */
function playSolo(User $user, string $main = 'time', string $sub = '30')
{
    return Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', $main, $sub);
}

it('refuses a fabricated high-wpm payload in time mode', function () {
    $user = User::factory()->create();

    // 1495 correct chars claimed in 60s = 299 WPM, just under the 300 ceiling. This used
    // to be stored verbatim and become the user's highest_wpm.
    playSolo($user, 'time', '30')
        ->call('saveResult', 60000, 1495, 1495, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('ignores a shortened duration in time mode', function () {
    $user = User::factory()->create();

    // Claiming 5s for a 30s test would multiply WPM sixfold.
    $component = playSolo($user, 'time', '30');
    app(SoloSessionGuard::class)->backdate(30);

    $component->call('saveResult', 5000, 400, 400, [], [], [], 0, 0, '', 0);

    $result = TypingResult::where('user_id', $user->id)->first();

    // Duration is taken from the sub-mode, not the payload.
    expect((float) $result->duration_seconds)->toBe(30.0);
});

it('refuses a character count the issued text could not produce', function () {
    $user = User::factory()->create();

    // words 10 is a short text; 5000 chars is far beyond anything it contains.
    playSolo($user, 'words', '10')
        ->call('saveResult', 60000, 5000, 5000, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0);
});

it('rejects a result whose mode no longer matches the issued text', function () {
    $user = User::factory()->create();

    // Take a 15-second test, then submit it as a 120-second one to stretch the WPM window.
    $component = playSolo($user, 'time', '15');

    $component->set('subMode', '120')
        ->call('saveResult', 120000, 2000, 2000, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

/**
 * Mounting the component legitimately issues a text, so "no session at all" only happens
 * once a result has already been banked and the guard consumed. That second submission is
 * the replay attempt, and it must not reach the result page.
 */
it('rejects a submission once the issued session has been consumed', function () {
    $user = User::factory()->create();

    $component = playSolo($user, 'time', '30');
    app(SoloSessionGuard::class)->backdate(30);
    $component->call('saveResult', 30000, 300, 290, [], [], [], 0, 0, '', 0);

    $component->call('saveResult', 30000, 300, 290, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(1);
});

it('refuses to bank the same finished session twice', function () {
    $user = User::factory()->create();

    $component = playSolo($user, 'time', '30');
    $component->call('saveResult', 30000, 300, 290, [], [], [], 0, 0, '', 0);

    $countAfterFirst = TypingResult::where('user_id', $user->id)->count();

    // Replaying the identical payload must not add a second row or more XP.
    $xpAfterFirst = $user->fresh()->total_xp;
    $component->call('saveResult', 30000, 300, 290, [], [], [], 0, 0, '', 0);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe($countAfterFirst)
        ->and($user->fresh()->total_xp)->toBe($xpAfterFirst);
});

it('still accepts an honest session', function () {
    $user = User::factory()->create();

    // ~300 chars over a 30-second test is a realistic ~60 WPM.
    $component = playSolo($user, 'time', '30');

    // A real player spends the 30 seconds typing; a test calls saveResult() instantly,
    // which would otherwise look like an automated forgery to the elapsed-time guard.
    app(SoloSessionGuard::class)->backdate(30);

    $component->call('saveResult', 30000, 300, 290, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing.result'));

    $result = TypingResult::where('user_id', $user->id)->first();

    expect($result)->not->toBeNull()
        ->and((float) $result->net_wpm)->toBeGreaterThan(0.0)
        ->and((float) $result->net_wpm)->toBeLessThan(120.0);
});

it('does not let a client rewrite the issued text', function () {
    $user = User::factory()->create();

    // #[Locked] makes this attempt an error rather than a silent swap.
    expect(fn () => playSolo($user, 'words', '10')->set('textToType', str_repeat('a ', 5000)))
        ->toThrow(Exception::class);
});
