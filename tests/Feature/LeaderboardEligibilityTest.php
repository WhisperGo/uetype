<?php

use App\Models\TypingResult;
use App\Models\User;
use Livewire\Volt\Volt;

/**
 * Leaderboard eligibility gate (Monkeytype's minTimeTyping): a result only reaches the public
 * board once its owner has accumulated LEADERBOARD_MIN_TYPING_SECONDS of typing across all
 * modes. This closes "new account -> run a script -> take rank 1" before any result is scored,
 * and cannot be paced (there is no number to land just under). These tests pin: below the
 * threshold is invisible, at/above it appears, the held record is NOT deleted, and the viewer
 * gets a "keep typing" signal rather than a bare "unranked".
 */

/** One result row with a chosen typing duration, so a test controls the accumulated time. */
function eligResult(User $user, float $wpm, int $duration, string $mode = 'time', string $config = '30'): TypingResult
{
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm + 5,
        'accuracy' => 96,
        'correct_chars' => 300,
        'incorrect_chars' => 8,
        'duration_seconds' => $duration,
    ]);
}

test('a player under the typing-time threshold does not appear on the board', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    $fresh = User::factory()->create();
    // A single short run -- far below the 30-minute gate -- even with a huge WPM.
    eligResult($fresh, 200, 30);

    $rows = Volt::test('leaderboard')->get('leaderboard');

    expect($rows)->toHaveCount(0);
});

test('a player at or above the threshold appears on the board', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    $veteran = User::factory()->create();
    // Accumulated typing time meets the gate exactly (one long run standing in for history).
    eligResult($veteran, 95, TypingResult::LEADERBOARD_MIN_TYPING_SECONDS);

    $rows = Volt::test('leaderboard')->get('leaderboard');

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->user_id)->toBe($veteran->id);
});

test('accumulated time is summed across modes, not per board', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    $player = User::factory()->create();
    // Time built up in OTHER modes still counts toward eligibility on the time board.
    eligResult($player, 60, 1000, 'words', '25');
    eligResult($player, 60, 1000, 'survival', 'medium');
    // A short time-30 run that, on its own, is nowhere near the gate.
    eligResult($player, 110, 30, 'time', '30');

    $rows = Volt::test('leaderboard')->get('leaderboard');

    // 1000 + 1000 + 30 = 2030s >= 1800s gate -> the time-30 record is now eligible.
    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->score)->toBe(110.0);
});

test('a held-back record is not deleted -- it appears once the player crosses the gate', function () {
    $viewer = User::factory()->create();
    $this->actingAs($viewer);

    $player = User::factory()->create();
    eligResult($player, 120, 30); // stored, but below the gate

    expect(Volt::test('leaderboard')->get('leaderboard'))->toHaveCount(0);

    // The player keeps typing; now they clear the threshold.
    eligResult($player, 70, TypingResult::LEADERBOARD_MIN_TYPING_SECONDS);

    $rows = Volt::test('leaderboard')->get('leaderboard');

    // The ORIGINAL 120 WPM record is still there and now ranks -- nothing was lost.
    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->score)->toBe(120.0);
});

test('userRank tells a not-yet-eligible player to keep typing, not a bare unranked', function () {
    $player = User::factory()->create();
    eligResult($player, 100, 30); // has typed, but under the gate
    $this->actingAs($player);

    $rank = Volt::test('leaderboard')->get('userRank');

    // Distinct from both a numeric rank and the plain "Unranked" empty state.
    expect($rank)->not->toBe('Unranked')
        ->and($rank)->toContain('min');
});

test('userRank stays a plain unranked for a player who never typed', function () {
    $player = User::factory()->create();
    $this->actingAs($player);

    expect(Volt::test('leaderboard')->get('userRank'))->toBe('Unranked');
});

test('an eligible player gets a real numeric rank', function () {
    $player = User::factory()->create();
    eligResult($player, 130, TypingResult::LEADERBOARD_MIN_TYPING_SECONDS);
    $this->actingAs($player);

    expect(Volt::test('leaderboard')->get('userRank'))->toBe(1);
});
