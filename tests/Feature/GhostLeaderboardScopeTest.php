<?php

use App\Livewire\GhostPicker;
use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\GhostResolver;
use Livewire\Livewire;

/**
 * The 'leaderboard' ghost must only ever reach players the leaderboard itself shows.
 *
 * `?ghost=<user_id>` is a deep link read straight off the query string, and it used to be
 * handed to GhostResolver with no check that the id belonged to anyone on a board. The
 * resolver answered with that user's username and best WPM, which the engine then displays --
 * so sweeping ?ghost=1,2,3… across the eight time/words configs harvested identity and records
 * for any account that had ever typed, including accounts no public board lists.
 *
 * That is the exact enumeration the routing layer is built to prevent: profiles are keyed by
 * username, and routes/web.php says why -- "so user IDs (and the total registered-user count)
 * can't be enumerated by changing a number in the URL". The ghost door reopened it.
 *
 * Scoping the resolver to the board's own eligibility gate closes it without inventing a new
 * rule: the oracle can now only confirm what the leaderboard already publishes.
 */

/** A result row big enough to move the accumulated-time gate by $seconds. */
function ghostRow(User $user, string $config, float $wpm, int $seconds): TypingResult
{
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => 'time',
        'mode_config' => $config,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm + 5,
        'accuracy' => 96,
        'correct_chars' => 300,
        'incorrect_chars' => 10,
        'duration_seconds' => $seconds,
    ]);
}

it('refuses to resolve a leaderboard ghost for a player the board does not list', function () {
    $viewer = User::factory()->create();
    $hidden = User::factory()->create();

    // Has a record, but nowhere near the accumulated typing time the board demands.
    ghostRow($hidden, '60', 150, 60);

    $ghost = app(GhostResolver::class)->resolve('leaderboard', $hidden->id, 'time', '60', $viewer->id);

    // null, so the engine hides the ghost -- and, the point here, never echoes the username.
    expect($ghost)->toBeNull();
});

it('still resolves a leaderboard ghost for a player who has earned a board spot', function () {
    $viewer = User::factory()->create();
    $eligible = User::factory()->create();

    ghostRow($eligible, '60', 90, TypingResult::LEADERBOARD_MIN_TYPING_SECONDS);

    $ghost = app(GhostResolver::class)->resolve('leaderboard', $eligible->id, 'time', '60', $viewer->id);

    expect($ghost)->not->toBeNull()
        ->and($ghost['wpm'])->toBe(90.0)
        ->and($ghost['label'])->toBe($eligible->username);
});

/**
 * The picker and the resolver have to agree, or the gate produces a worse bug than it fixes:
 * an opponent listed in the picker that silently resolves to nothing when chosen.
 */
it('does not offer an ineligible player in the ghost picker list', function () {
    $viewer = User::factory()->create();
    $hidden = User::factory()->create();
    $eligible = User::factory()->create();

    ghostRow($hidden, '60', 180, 60);
    ghostRow($eligible, '60', 90, TypingResult::LEADERBOARD_MIN_TYPING_SECONDS);

    $rows = Livewire::actingAs($viewer)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '60'])
        ->instance()->eligibleLeaderboard;

    expect($rows->pluck('user_id')->all())->toBe([$eligible->id]);
});

/**
 * The deep link is the door this all arrived through, so it gets its own lock: an ineligible
 * id must leave no ghost selection behind in the session.
 */
it('drops a ?ghost deep link that points at an ineligible player', function () {
    $viewer = User::factory()->create();
    $hidden = User::factory()->create();

    ghostRow($hidden, '60', 180, 60);

    Livewire::actingAs($viewer)
        ->withQueryParams(['ghost' => $hidden->id, 'mode' => 'time', 'config' => '60'])
        ->test(TypingEngine::class)
        ->assertSet('ghostActive', false);

    expect(session('ghost_selection'))->toBeNull();
});
