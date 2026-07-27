<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * One player, more than one open tab.
 *
 * SoloSessionGuard used to keep the issued session under a SINGLE session key, so the second
 * tab a player opened overwrote the first tab's record. The first tab then failed
 * matchesIssuedSession() and its result -- an entirely honest run -- was thrown away with
 * "rejected by server validation (implausible)".
 *
 * Nothing about that is exotic: leaving a typing test open in a background tab and starting
 * another is ordinary browsing. The anti-replay property still has to hold, though, so these
 * tests pin both halves -- concurrent tabs both save, and a replayed session still cannot.
 */
it('saves the first tab result after a second tab opened a different mode', function () {
    $user = User::factory()->create();

    // Tab A: a words/25 test, being typed.
    $tabA = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'words', '25');

    // Tab B: same player opens another tab and picks a different mode. Under the old single
    // key this call silently destroyed tab A's session.
    Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '30');

    app(SoloSessionGuard::class)->backdate(15);

    // Tab A finishes normally: 142 chars in 15s = ~113 WPM, 98.6% accuracy.
    $tabA->call('saveResult', [
        'durationMs' => 15000,
        'totalKeystrokes' => 142,
        'correctKeystrokes' => 140,
        'maxIdleMs' => 300,
        'wpmHistory' => [110, 115, 112, 118, 114, 116, 113, 117, 115, 114, 116, 112, 118, 115, 113],
    ]);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(1);
});

it('saves results from two tabs independently', function () {
    $user = User::factory()->create();

    $tabA = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'words', '25');
    $tabB = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '30');

    app(SoloSessionGuard::class)->backdate(30);

    $payload = [
        'durationMs' => 15000,
        'totalKeystrokes' => 142,
        'correctKeystrokes' => 140,
        'maxIdleMs' => 300,
        'wpmHistory' => [110, 115, 112, 118, 114, 116, 113, 117, 115, 114, 116, 112, 118, 115, 113],
    ];

    $tabA->call('saveResult', $payload);
    $tabB->call('saveResult', array_merge($payload, ['durationMs' => 30000]));

    // Both runs were real and both must be recorded.
    expect(TypingResult::where('user_id', $user->id)->count())->toBe(2);
});

it('refuses a client-chosen tab key', function () {
    // The security property the whole keying rests on. tabKey selects WHICH issued session a
    // submission is validated against, so a client able to set it could aim a forged payload
    // at a session it never received -- turning the guard into a formality. #[Locked] is what
    // stops that, and a future refactor dropping the attribute must fail here, loudly.
    Livewire::test(TypingEngine::class)->set('tabKey', 'attacker-chosen');
})->throws(Exception::class);

it('still refuses to save the same session twice (replay)', function () {
    // The property per-tab keys must not cost us: one issued text = one saved result.
    $user = User::factory()->create();

    $tab = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'words', '25');

    app(SoloSessionGuard::class)->backdate(15);

    $payload = [
        'durationMs' => 15000,
        'totalKeystrokes' => 142,
        'correctKeystrokes' => 140,
        'maxIdleMs' => 300,
        'wpmHistory' => [110, 115, 112, 118, 114, 116, 113, 117, 115, 114, 116, 112, 118, 115, 113],
    ];

    $tab->call('saveResult', $payload);
    $tab->call('saveResult', $payload);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(1);
});
