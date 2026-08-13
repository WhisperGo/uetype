<?php

use App\Models\TypingResult;
use App\Models\TypingSpeedCapability;
use App\Models\TypingVerificationAttempt;
use App\Models\User;
use App\Services\LongitudinalBaseline;
use App\Services\TypingSpeedVerificationService;
use App\Services\TypingVerificationReplay;

function speedVerificationPendingResult(
    User $user,
    float $wpm,
    string $language = 'en',
    string $reason = 'longitudinal_spike',
): TypingResult {
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => 'time',
        'mode_config' => '30',
        'language' => $language,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm,
        'accuracy' => 100,
        'correct_chars' => (int) round($wpm * 2.5),
        'incorrect_chars' => 0,
        'duration_seconds' => 30,
        'review_status' => TypingResult::REVIEW_PENDING,
        'review_reason' => $reason,
        'session_fingerprint' => hash('sha256', "{$user->id}:{$language}:{$wpm}:".fake()->uuid()),
        'integrity_meta' => [
            'timing' => [
                'has_data' => true,
                'reasons' => [],
                'sample_count' => 240,
                'claimed_keystrokes' => (int) round($wpm * 2.5),
            ],
        ],
    ]);
}

/** @return list<array{key:string,at_ms:int,type:string}> */
function speedVerificationEvents(string $text, int $characters = 425, bool $uniform = false): array
{
    $characters = min($characters, mb_strlen($text));
    $chars = array_slice(mb_str_split($text), 0, $characters);
    $gaps = $uniform ? [70] : [45, 55, 65, 75, 85, 95, 40];
    $at = 0;
    $events = [];

    foreach ($chars as $index => $char) {
        $at += $gaps[$index % count($gaps)];
        $events[] = ['key' => $char, 'at_ms' => $at, 'type' => 'keydown'];
    }

    return $events;
}

it('verifies a 30-second pace and promotes supported pending results in the same language', function () {
    $user = User::factory()->create(['highest_wpm' => 0]);

    $tooFast = speedVerificationPendingResult($user, 205, 'en');
    $otherLanguage = speedVerificationPendingResult($user, 165, 'id');
    $supported = speedVerificationPendingResult($user, 175, 'en', 'no_history_high');
    $source = speedVerificationPendingResult($user, 190, 'en');

    $verification = app(TypingSpeedVerificationService::class);
    $issued = $verification->issue($user);
    $events = speedVerificationEvents($issued['attempt']->challenge_text);

    expect($verification->begin($user, $issued['attempt']->id, $issued['token']))->toBeTrue();
    $this->travel(30)->seconds();
    $result = $verification->complete($user, $issued['attempt']->id, $issued['token'], $events);

    expect($result['passed'])->toBeTrue()
        ->and($result['wpm'])->toBe(170.0)
        ->and($result['ceiling'])->toBe(195.5)
        ->and($result['promoted_count'])->toBe(2)
        ->and($supported->fresh()->review_status)->toBe(TypingResult::REVIEW_CLEAR)
        ->and($source->fresh()->review_status)->toBe(TypingResult::REVIEW_CLEAR)
        ->and($tooFast->fresh()->review_status)->toBe(TypingResult::REVIEW_PENDING)
        ->and($otherLanguage->fresh()->review_status)->toBe(TypingResult::REVIEW_PENDING)
        ->and((float) $user->fresh()->highest_wpm)->toBe(190.0)
        ->and(TypingSpeedCapability::whereBelongsTo($user)->value('language'))->toBe('en')
        ->and($issued['attempt']->fresh()->status)->toBe(TypingVerificationAttempt::STATUS_PASSED);

    $cleanTiming = ['has_data' => true, 'reasons' => []];
    expect(app(LongitudinalBaseline::class)
        ->decisionFor($user->id, 'words', '10', 'en', 190, $cleanTiming)->reason)->toBeNull()
        ->and(app(LongitudinalBaseline::class)
            ->decisionFor($user->id, 'words', '10', 'en', 196, $cleanTiming)->reason)
        ->toBe('no_history_high');
});

it('consumes a token once and never promotes a failed uniform event stream', function () {
    $user = User::factory()->create();
    $source = speedVerificationPendingResult($user, 170);
    $verification = app(TypingSpeedVerificationService::class);
    $issued = $verification->issue($user);

    $verification->begin($user, $issued['attempt']->id, $issued['token']);
    $this->travel(30)->seconds();
    $failed = $verification->complete(
        $user,
        $issued['attempt']->id,
        $issued['token'],
        speedVerificationEvents($issued['attempt']->challenge_text, uniform: true),
    );
    $replayed = $verification->complete(
        $user,
        $issued['attempt']->id,
        $issued['token'],
        speedVerificationEvents($issued['attempt']->challenge_text),
    );

    expect($failed['passed'])->toBeFalse()
        ->and($failed['reason'])->toBe('timing_invalid')
        ->and($replayed['passed'])->toBeFalse()
        ->and($replayed['reason'])->toBe('attempt_invalid')
        ->and($source->fresh()->review_status)->toBe(TypingResult::REVIEW_PENDING)
        ->and(TypingSpeedCapability::whereBelongsTo($user)->exists())->toBeFalse()
        ->and($issued['attempt']->fresh()->status)->toBe(TypingVerificationAttempt::STATUS_FAILED);
});

it('keeps only one active attempt and binds submission to its owner', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    speedVerificationPendingResult($user, 170);
    $verification = app(TypingSpeedVerificationService::class);

    $first = $verification->issue($user);
    $second = $verification->issue($user);

    expect($first['attempt']->fresh()->status)->toBe(TypingVerificationAttempt::STATUS_EXPIRED)
        ->and($second['attempt']->fresh()->status)->toBe(TypingVerificationAttempt::STATUS_ACTIVE)
        ->and(TypingVerificationAttempt::whereBelongsTo($user)
            ->where('status', TypingVerificationAttempt::STATUS_ACTIVE)->count())->toBe(1);

    expect($verification->begin($user, $second['attempt']->id, $second['token']))->toBeTrue();
    $firstInputAt = $second['attempt']->fresh()->input_started_at;
    $this->travel(5)->seconds();
    expect($verification->begin($user, $second['attempt']->id, $second['token']))->toBeTrue()
        ->and($second['attempt']->fresh()->input_started_at->equalTo($firstInputAt))->toBeTrue();

    $this->travel(25)->seconds();
    $foreign = $verification->complete(
        $other,
        $second['attempt']->id,
        $second['token'],
        speedVerificationEvents($second['attempt']->challenge_text),
    );

    expect($foreign['passed'])->toBeFalse()
        ->and($foreign['reason'])->toBe('attempt_invalid')
        ->and($second['attempt']->fresh()->status)->toBe(TypingVerificationAttempt::STATUS_ACTIVE);
});

it('replays corrections and rejects a backward timestamp or incomplete timing coverage', function () {
    $replay = app(TypingVerificationReplay::class);
    $events = [];
    $target = str_repeat('ab ', 40);
    $at = 0;

    foreach (mb_str_split(substr($target, 0, 80)) as $index => $character) {
        $at += [45, 65, 85, 105][$index % 4];
        $events[] = ['key' => $index === 10 ? 'x' : $character, 'at_ms' => $at];

        if ($index === 10) {
            $at += 95;
            $events[] = ['key' => 'Backspace', 'at_ms' => $at];
            $at += 55;
            $events[] = ['key' => $character, 'at_ms' => $at];
        }
    }

    $valid = $replay->replay($target, $events);
    $backward = $events;
    $backward[30]['at_ms'] = 1;
    $sparse = $events;
    foreach ($sparse as $index => &$event) {
        $event['at_ms'] = intdiv($index, 5) * 100;
    }
    unset($event);

    expect($valid['valid'])->toBeTrue()
        ->and($valid['correct_chars'])->toBe(80)
        ->and($valid['accuracy'])->toBe(98.77)
        ->and($replay->replay($target, $backward)['reason'])->toBe('event_timing_invalid')
        ->and($replay->replay($target, $sparse)['reason'])->toBe('event_coverage_invalid');
});

it('replays a platform word deletion as one physical event', function () {
    $replay = app(TypingVerificationReplay::class);
    $target = str_repeat('alpha beta ', 20);
    $prefixWithMistake = 'alpha zeta';
    $correctRemainder = mb_substr($target, 6, 74);
    $events = [];
    $at = 0;

    foreach (mb_str_split($prefixWithMistake) as $index => $character) {
        $at += [48, 71, 93][$index % 3];
        $events[] = ['key' => $character, 'at_ms' => $at];
    }

    $at += 110;
    $events[] = ['key' => 'BackspaceWord', 'at_ms' => $at];

    foreach (mb_str_split($correctRemainder) as $index => $character) {
        $at += [52, 83, 119][$index % 3];
        $events[] = ['key' => $character, 'at_ms' => $at];
    }

    $result = $replay->replay($target, $events);

    expect($result['valid'])->toBeTrue()
        ->and($result['correct_chars'])->toBe(80)
        ->and($result['accuracy'])->toBe(95.24);
});

it('protects the verification page and only offers it for an eligible pending result', function () {
    $user = User::factory()->create();

    $this->get(route('typing.verify'))->assertRedirect(route('login'));

    $this->actingAs($user)
        ->get(route('typing.verify'))
        ->assertRedirect(route('typing'));

    speedVerificationPendingResult($user, 170);

    $this->actingAs($user)
        ->get(route('typing.verify'))
        ->assertOk()
        ->assertSee(__('verification.title'))
        ->assertDontSee('admin approval');
});
