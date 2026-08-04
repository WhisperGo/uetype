<?php

use App\Livewire\ReviewQueue;
use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * Longitudinal review (§7.5) + admin queue (§7.6). A run that clears the hard gates but
 * jumps far above the player's own history, or debuts high with no history, is HELD as
 * `pending` -- saved and shown on the profile, but kept off the leaderboard and not counted
 * as a PB until an admin approves. Never auto-rejected: honest players improve.
 */
function playReview(User $user, string $sub = '30')
{
    return Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', $sub);
}

/** Seed prior clear history so the baseline has something to compare against. */
function seedHistory(User $user, float $wpm, int $count = 8): void
{
    for ($i = 0; $i < $count; $i++) {
        TypingResult::create([
            'user_id' => $user->id, 'mode' => 'time', 'mode_config' => '30', 'language' => 'en',
            'net_wpm' => $wpm, 'raw_wpm' => $wpm, 'accuracy' => 96,
            'correct_chars' => 100, 'incorrect_chars' => 4, 'duration_seconds' => 30,
            'review_status' => TypingResult::REVIEW_CLEAR,
        ]);
    }
}

it('holds a sudden spike over the player history for review, without rejecting it', function () {
    $user = User::factory()->create();
    seedHistory($user, 70);

    // ~130 WPM after a 70-WPM history: well over the +40% spike threshold, but a plausible
    // number on its own, so it saves as pending (not discarded).
    $c = playReview($user, '30');
    app(SoloSessionGuard::class)->backdate(30);
    $c->call('saveResult', [
        'durationMs' => 30000, 'totalKeystrokes' => 325, 'correctKeystrokes' => 325, 'maxIdleMs' => 100,
    ]);

    $flagged = TypingResult::where('user_id', $user->id)->latest('id')->first();

    expect($flagged->review_status)->toBe(TypingResult::REVIEW_PENDING)
        ->and($flagged->review_reason)->toBe('longitudinal_spike')
        // PB is withheld while pending.
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('clears a result in line with the player history', function () {
    $user = User::factory()->create();
    seedHistory($user, 90);

    // ~100 WPM after 90 avg: within +40%, so it clears normally.
    $c = playReview($user, '30');
    app(SoloSessionGuard::class)->backdate(30);
    $c->call('saveResult', [
        'durationMs' => 30000, 'totalKeystrokes' => 250, 'correctKeystrokes' => 250, 'maxIdleMs' => 100,
    ]);

    $r = TypingResult::where('user_id', $user->id)->latest('id')->first();

    expect($r->review_status)->toBe(TypingResult::REVIEW_CLEAR)
        ->and((float) $user->fresh()->highest_wpm)->toBe(100.0);
});

it('holds a high debut from a player with no history', function () {
    $user = User::factory()->create();

    // No prior results, first run ~160 WPM (>= NO_HISTORY_WPM 150).
    $c = playReview($user, '30');
    app(SoloSessionGuard::class)->backdate(30);
    $c->call('saveResult', [
        'durationMs' => 30000, 'totalKeystrokes' => 400, 'correctKeystrokes' => 400, 'maxIdleMs' => 100,
    ]);

    $r = TypingResult::where('user_id', $user->id)->latest('id')->first();

    expect($r->review_status)->toBe(TypingResult::REVIEW_PENDING)
        ->and($r->review_reason)->toBe('no_history_high');
});

it('keeps a modest first run from a new player off the queue', function () {
    $user = User::factory()->create();

    // First run ~80 WPM: below the no-history threshold, clears.
    $c = playReview($user, '30');
    app(SoloSessionGuard::class)->backdate(30);
    $c->call('saveResult', [
        'durationMs' => 30000, 'totalKeystrokes' => 200, 'correctKeystrokes' => 200, 'maxIdleMs' => 100,
    ]);

    expect(TypingResult::where('user_id', $user->id)->value('review_status'))
        ->toBe(TypingResult::REVIEW_CLEAR);
});

it('excludes pending results from the leaderboard until approved', function () {
    $user = User::factory()->create(['username' => 'Flagged']);
    $pending = TypingResult::create([
        'user_id' => $user->id, 'mode' => 'time', 'mode_config' => '30', 'language' => 'en',
        'net_wpm' => 200, 'raw_wpm' => 200, 'accuracy' => 99,
        'correct_chars' => 500, 'incorrect_chars' => 2, 'duration_seconds' => 30,
        'review_status' => TypingResult::REVIEW_PENDING, 'review_reason' => 'no_history_high',
    ]);

    $board = Livewire::test('leaderboard');
    expect($board->html())->not->toContain('Flagged');

    // Admin approves -> it now counts.
    $admin = User::factory()->admin()->create();
    Livewire::actingAs($admin)->test(ReviewQueue::class)->call('approve', $pending->id);

    expect($pending->fresh()->review_status)->toBe(TypingResult::REVIEW_APPROVED)
        ->and((float) $user->fresh()->highest_wpm)->toBe(200.0);
});

it('is admin-only: a non-admin gets 404', function () {
    $user = User::factory()->create(); // not admin

    $this->actingAs($user)->get(route('review-queue'))->assertNotFound();
});

/**
 * A GUEST must get the same 404, and that is the whole reason EnsureUserIsAdmin exists in the
 * shape it does: its docblock says a redirect to login "would confirm the page exists, which is
 * exactly what an admin panel should not leak", and that 'auth' is therefore deliberately NOT
 * used alongside it. The monitoring dashboard obeys that (web + EnsureUserIsAdmin, no auth);
 * this route was declared inside the auth group, so guests were redirected instead -- and a
 * redirect where an unknown URL gives 404 is exactly the confirmation the rule forbids.
 *
 * Only the signed-in non-admin was covered before, which is why the gap survived: the case that
 * behaved differently was the one nobody asked about.
 */
it('is admin-only: a guest gets 404 too, not a redirect that proves the page exists', function () {
    $this->get(route('review-queue'))->assertNotFound();
});

it('lets an admin reject a pending result for good', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $pending = TypingResult::create([
        'user_id' => $user->id, 'mode' => 'time', 'mode_config' => '30', 'language' => 'en',
        'net_wpm' => 210, 'raw_wpm' => 210, 'accuracy' => 99,
        'correct_chars' => 525, 'incorrect_chars' => 1, 'duration_seconds' => 30,
        'review_status' => TypingResult::REVIEW_PENDING, 'review_reason' => 'longitudinal_spike',
    ]);

    Livewire::actingAs($admin)->test(ReviewQueue::class)->call('reject', $pending->id);

    expect($pending->fresh()->review_status)->toBe(TypingResult::REVIEW_REJECTED)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});
