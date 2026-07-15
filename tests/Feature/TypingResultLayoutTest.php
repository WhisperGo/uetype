<?php

use App\Models\User;

use function Pest\Laravel\actingAs;

it('keeps the original survival game-over card layout (separate from solo 2-column layout)', function () {
    $user = User::factory()->create();

    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 28.63, 'rawWpm' => 30, 'accuracy' => 48.39, 'time' => 6,
        'mode' => 'survival', 'subMode' => 'hard', 'score' => 3,
        'totalKeystrokes' => 10, 'correctKeystrokes' => 5, 'incorrectKeystrokes' => 5,
        'wpmHistory' => [10, 20, 15], 'rawHistory' => [12, 22, 17],
        'missedChars' => ['a' => 2], 'xpEarned' => 1,
        'isPersonalBest' => false, 'previousBest' => 0, 'consistency' => 65,
        'levelData' => ['level' => 1, 'progress' => 59, 'needed' => 100, 'next_level' => 2],
        'drainEventCount' => 3, 'survivalPreviousBest' => 19, 'isSurvivalPersonalBest' => false,
        'ghostResult' => null,
    ]]);

    $response = actingAs($user)->get('/result');

    $response->assertOk()
        ->assertSee('game over')
        ->assertSee(__('result.play_again_title'))
        ->assertSee('drain events')
        ->assertDontSee('error heatmap'); // heatmap hanya untuk cabang non-survival
});

it('keeps the normal time-mode layout unchanged (performance chart + heatmap + ghost field)', function () {
    $user = User::factory()->create();

    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 85.0, 'rawWpm' => 90, 'accuracy' => 96.5, 'time' => 30,
        'mode' => 'time', 'subMode' => '30', 'score' => null,
        'totalKeystrokes' => 400, 'correctKeystrokes' => 386, 'incorrectKeystrokes' => 14,
        'wpmHistory' => [70, 80, 85], 'rawHistory' => [75, 85, 90],
        'missedChars' => ['e' => 3], 'xpEarned' => 24,
        'isPersonalBest' => true, 'previousBest' => 80, 'consistency' => 88,
        'levelData' => ['level' => 5, 'progress' => 200, 'needed' => 500, 'next_level' => 6],
        'drainEventCount' => 0, 'survivalPreviousBest' => null, 'isSurvivalPersonalBest' => false,
        'ghostResult' => ['label' => 'alice', 'wpm' => 80.0, 'playerWon' => true, 'charDelta' => 12],
    ]]);

    $response = actingAs($user)->get('/result');

    $response->assertOk()
        ->assertSee('performance')
        ->assertSee('raw wpm')
        ->assertSee('duration')
        ->assertSee('new personal best')
        ->assertSee('error heatmap')
        ->assertSee('beat the ghost')
        ->assertDontSee('game over')
        ->assertDontSee('drain events');
});
