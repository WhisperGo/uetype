<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Mode solo terbuka untuk tamu: '/' dan '/typing' memakai komponen yang sama,
 * jadi keduanya harus bisa diakses tanpa login. Tamu mengetik & melihat hasil,
 * hanya tak mendapat XP/rekor.
 */
it('lets a guest open the solo typing page without being redirected to login', function () {
    // Sebelum perbaikan: '/typing' ada di grup middleware auth -> 302 ke /login.
    $this->get('/typing')->assertOk();
});

it('lets a guest open the home page (same component as /typing)', function () {
    $this->get('/')->assertOk();
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
        // Tamu: tak ada XP & level.
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
