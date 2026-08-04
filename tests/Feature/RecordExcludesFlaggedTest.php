<?php

use App\Models\TypingResult;
use App\Models\User;
use App\Services\GhostResolver;

/**
 * A run the anti-cheat system withheld must not come back as a RECORD.
 *
 * The rule already existed and was already written down -- User::recordPersonalBest() refuses
 * anything that is not `clear`/`approved`, because "a run held for anti-cheat review must not
 * put a flagged number on the profile before a human has looked at it", and the leaderboard
 * applies the same whereIn. But there are TWO record figures, not one: the career best in
 * users.highest_wpm, and the per-mode best derived from typing_results. Only the first was
 * gated.
 *
 * So a 200 WPM run held `pending` stayed off the board and off the profile, and still became
 * the player's per-mode best -- which is the number the result screen compares against and the
 * pace GhostResolver hands to a ghost. An admin REJECTING it changed nothing either, since
 * ReviewQueue::reject() only moves the column and leaves the row in place.
 *
 * Survival is the sharper half: duration_seconds IS its leaderboard metric, and
 * SurvivalPlausibility holds implausible runs precisely because stamina is simulated on the
 * client. The held number was still shown back as the record.
 */
function flaggedRow(User $user, string $mode, string $config, array $attributes): TypingResult
{
    return TypingResult::create(array_merge([
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'net_wpm' => 60,
        'raw_wpm' => 65,
        'accuracy' => 96,
        'correct_chars' => 300,
        'incorrect_chars' => 10,
        'duration_seconds' => 30,
    ], $attributes));
}

it('does not let a run held for review become the per-mode record', function () {
    $user = User::factory()->create();

    flaggedRow($user, 'time', '30', ['net_wpm' => 80, 'review_status' => TypingResult::REVIEW_CLEAR]);
    flaggedRow($user, 'time', '30', ['net_wpm' => 200, 'review_status' => TypingResult::REVIEW_PENDING]);

    expect(TypingResult::bestNetWpmFor($user->id, 'time', '30'))->toBe(80.0);
});

it('does not let a run an admin rejected become the per-mode record', function () {
    $user = User::factory()->create();

    flaggedRow($user, 'time', '30', ['net_wpm' => 80, 'review_status' => TypingResult::REVIEW_CLEAR]);
    flaggedRow($user, 'time', '30', ['net_wpm' => 210, 'review_status' => TypingResult::REVIEW_REJECTED]);

    expect(TypingResult::bestNetWpmFor($user->id, 'time', '30'))->toBe(80.0);
});

it('still counts a run an admin approved', function () {
    $user = User::factory()->create();

    flaggedRow($user, 'time', '30', ['net_wpm' => 80, 'review_status' => TypingResult::REVIEW_CLEAR]);
    flaggedRow($user, 'time', '30', ['net_wpm' => 150, 'review_status' => TypingResult::REVIEW_APPROVED]);

    expect(TypingResult::bestNetWpmFor($user->id, 'time', '30'))->toBe(150.0);
});

it('reports no record at all when every run in the bucket is flagged', function () {
    $user = User::factory()->create();

    flaggedRow($user, 'words', '25', ['net_wpm' => 190, 'review_status' => TypingResult::REVIEW_PENDING]);

    // null, not 0.0: callers read null as "no record yet" and hide the ghost / skip the PB
    // comparison. Returning zero would advertise a record of zero.
    expect(TypingResult::bestNetWpmFor($user->id, 'words', '25'))->toBeNull();
});

it('never paces a ghost off a withheld run', function () {
    $player = User::factory()->create();
    $rival = User::factory()->create();

    // A 'leaderboard' ghost only reaches players the board lists (GhostLeaderboardScopeTest);
    // this test is about WHICH of their runs sets the pace, so give them the board spot first.
    accumulateTypingTime($rival);

    flaggedRow($rival, 'time', '60', ['net_wpm' => 70, 'review_status' => TypingResult::REVIEW_CLEAR]);
    flaggedRow($rival, 'time', '60', ['net_wpm' => 205, 'review_status' => TypingResult::REVIEW_PENDING]);

    $ghost = app(GhostResolver::class)->resolve('leaderboard', $rival->id, 'time', '60', $player->id);

    expect($ghost['wpm'])->toBe(70.0);
});

it('does not let a withheld survival run become the survival record', function () {
    $user = User::factory()->create();

    flaggedRow($user, 'survival', 'hard', [
        'duration_seconds' => 42, 'score' => 300, 'review_status' => TypingResult::REVIEW_CLEAR,
    ]);
    flaggedRow($user, 'survival', 'hard', [
        'duration_seconds' => 900, 'score' => 4000, 'review_status' => TypingResult::REVIEW_PENDING,
    ]);

    expect(TypingResult::bestSurvivalDurationFor($user->id, 'hard'))->toBe(42.0);
});
