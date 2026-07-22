<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Solo mode is open to guests: '/' redirects to '/typing', and '/typing' must be reachable
 * without logging in. Guests type & see results, they just don't earn XP/records.
 */
it('lets a guest open the solo typing page without being redirected to login', function () {
    // Sebelum perbaikan: '/typing' ada di grup middleware auth -> 302 ke /login.
    $this->get('/typing')->assertOk();
});

it('redirects the home page to the solo typing page', function () {
    $this->get('/')->assertRedirect('/typing');
});

it('still lets an authenticated user open the solo typing page', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/typing')->assertOk();
});

function guestResultSession(): array
{
    return ['typing_result' => [
        'wpm' => 42.0, 'rawWpm' => 45.0, 'accuracy' => 95.0, 'time' => 15,
        'mode' => 'time', 'subMode' => '15', 'score' => null,
        'totalKeystrokes' => 100, 'correctKeystrokes' => 95, 'incorrectKeystrokes' => 5,
        'wpmHistory' => [40, 42, 44], 'rawHistory' => [42, 45, 47],
        'missedChars' => ['a' => 1],
        // Guest: no XP & level.
        'xpEarned' => 0, 'levelData' => null,
        'isPersonalBest' => false, 'previousBest' => 0, 'consistency' => 90,
        'drainEventCount' => 0, 'survivalPreviousBest' => null, 'isSurvivalPersonalBest' => false,
        'ghostResult' => null,
    ]];
}

it('lets a guest see the result page after finishing a test', function () {
    session(guestResultSession());

    $this->get('/result')->assertOk();
});

it('hides the XP panel from a guest on the result page', function () {
    app()->setLocale('en');
    session(guestResultSession());

    $this->get('/result')
        ->assertOk()
        ->assertDontSee(__('result.xp_earned'), false);
});

it('shows the XP panel to an authenticated user on the result page', function () {
    app()->setLocale('en');

    $user = User::factory()->create();

    $data = guestResultSession();
    $data['typing_result']['xpEarned'] = 6;
    $data['typing_result']['levelData'] = [
        'level' => 3, 'progress' => 144, 'needed' => 300, 'next_level' => 4,
    ];
    session($data);

    actingAs($user)->get('/result')
        ->assertOk()
        ->assertSee(__('result.xp_earned'), false);
});
