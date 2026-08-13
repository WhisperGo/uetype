<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\AchievementService;
use App\Services\LongitudinalBaseline;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;
use Livewire\Volt\Volt;

function automaticHumanIntervals(int $count = 240): array
{
    $values = [];

    for ($i = 0; $i < $count; $i++) {
        $values[] = $i % 17 === 0 ? 620 : 145 + (($i * 53) % 230);
    }

    return $values;
}

function playAutomaticFastResult(User $user, int $correct = 425): void
{
    $component = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '30');
    app(SoloSessionGuard::class)->backdate(30);

    $component->call('saveResult', [
        'durationMs' => 30000,
        'totalKeystrokes' => $correct + 5,
        'correctKeystrokes' => $correct,
        'keyIntervals' => automaticHumanIntervals(),
        'keyStrokeCount' => $correct + 5,
        'wpmHistory' => [158, 166, 172, 169, 175, 163, 171, 168, 174, 170, 165, 173],
        'maxIdleMs' => 800,
    ]);
}

function playAutomaticFastWordsResult(User $user, int $correct = 496): void
{
    $component = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'words', '100');
    app(SoloSessionGuard::class)->backdate(35);

    $component->call('saveResult', [
        'durationMs' => 35000,
        'totalKeystrokes' => $correct + 8,
        'correctKeystrokes' => $correct,
        'keyIntervals' => automaticHumanIntervals(),
        'keyStrokeCount' => $correct + 8,
        'wpmHistory' => [158, 166, 172, 169, 175, 163, 171, 168, 174, 170, 165, 173],
        'maxIdleMs' => 800,
    ]);
}

it('automatically clears a corroborated elite cluster without admin approval', function () {
    $user = User::factory()->create();

    playAutomaticFastResult($user, 420);
    playAutomaticFastResult($user, 425);

    expect(TypingResult::where('user_id', $user->id)->pluck('review_status')->all())
        ->each->toBe(TypingResult::REVIEW_PENDING);

    playAutomaticFastResult($user, 430);

    $rows = TypingResult::where('user_id', $user->id)->orderBy('id')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('review_status')->all())->each->toBe(TypingResult::REVIEW_CLEAR)
        ->and($rows->pluck('review_reason')->filter())->toBeEmpty()
        ->and($rows->pluck('review_resolved_at')->filter())->toHaveCount(3)
        ->and((float) $user->fresh()->highest_wpm)->toBe(172.0)
        ->and(session('typing_result.resultStatus'))->toBe(TypingResult::REVIEW_CLEAR)
        ->and(session('typing_result.autoClearedCount'))->toBe(3);

    $this->actingAs($user)
        ->get(route('profile.me'))
        ->assertOk()
        ->assertSee(__('history.status.verified'));
});

it('automatically resolves words mode with the same evidence lifecycle', function () {
    $user = User::factory()->create();

    playAutomaticFastWordsResult($user, 490);
    playAutomaticFastWordsResult($user, 496);
    playAutomaticFastWordsResult($user, 502);

    expect(TypingResult::where('user_id', $user->id)->pluck('review_status')->all())
        ->each->toBe(TypingResult::REVIEW_CLEAR)
        ->and((float) $user->fresh()->highest_wpm)->toBeGreaterThan(170.0);
});

it('does not clear repeated high claims that have no human timing evidence', function () {
    $user = User::factory()->create();

    for ($i = 0; $i < 4; $i++) {
        $component = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '30');
        app(SoloSessionGuard::class)->backdate(30);
        $component->call('saveResult', [
            'durationMs' => 30000,
            'totalKeystrokes' => 425,
            'correctKeystrokes' => 425,
        ]);
    }

    expect(TypingResult::where('user_id', $user->id)->pluck('review_status')->all())
        ->each->toBe(TypingResult::REVIEW_PENDING)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('does not let one unusable pending row block a later corroborated cluster', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '30');
    app(SoloSessionGuard::class)->backdate(30);
    $component->call('saveResult', [
        'durationMs' => 30000,
        'totalKeystrokes' => 425,
        'correctKeystrokes' => 425,
    ]);

    playAutomaticFastResult($user, 420);
    playAutomaticFastResult($user, 425);
    playAutomaticFastResult($user, 430);

    expect(TypingResult::where('user_id', $user->id)->pluck('review_status')->all())
        ->each->toBe(TypingResult::REVIEW_CLEAR);
});

it('shows automatic verification instead of unranked after thirty minutes', function () {
    $user = User::factory()->create();

    TypingResult::create([
        'user_id' => $user->id,
        'mode' => 'time',
        'mode_config' => '30',
        'language' => 'en',
        'net_wpm' => 170,
        'raw_wpm' => 175,
        'accuracy' => 97,
        'correct_chars' => 425,
        'incorrect_chars' => 8,
        'duration_seconds' => TypingResult::LEADERBOARD_MIN_TYPING_SECONDS,
        'review_status' => TypingResult::REVIEW_PENDING,
        'review_reason' => 'no_history_high',
    ]);

    $this->actingAs($user);

    expect(Volt::test('leaderboard')->get('userRank'))
        ->toBe(__('leaderboard.verification_pending'));
});

it('shows the pending integrity state on the result screen', function () {
    $user = User::factory()->create();

    playAutomaticFastResult($user);

    Livewire::actingAs($user)
        ->test(App\Livewire\TypingResult::class)
        ->assertSet('resultStatus', TypingResult::REVIEW_PENDING)
        ->assertSee(__('result.integrity.pending_title'))
        ->assertSee(__('result.integrity.pending_body'));

    $this->actingAs($user)
        ->get(route('profile.me'))
        ->assertOk()
        ->assertSee(__('history.status.pending'));
});

it('does not let a pending result unlock public performance achievements', function () {
    $user = User::factory()->create();

    TypingResult::create([
        'user_id' => $user->id,
        'mode' => 'time',
        'mode_config' => '30',
        'language' => 'en',
        'net_wpm' => 220,
        'raw_wpm' => 224,
        'accuracy' => 100,
        'correct_chars' => 550,
        'incorrect_chars' => 0,
        'duration_seconds' => 30,
        'review_status' => TypingResult::REVIEW_PENDING,
        'review_reason' => 'no_history_high',
    ]);

    $stats = app(AchievementService::class)->computeStats($user);

    expect($stats['highest_wpm'])->toBe(0.0)
        ->and($stats['total_tests'])->toBe(0)
        ->and($stats['perfect_runs'])->toBe(0);
});

it('uses a robust center so one warmup does not create a false spike', function () {
    $user = User::factory()->create();

    foreach ([20, 100, 100, 100, 100] as $wpm) {
        TypingResult::create([
            'user_id' => $user->id,
            'mode' => 'time',
            'mode_config' => '30',
            'language' => 'en',
            'net_wpm' => $wpm,
            'raw_wpm' => $wpm,
            'accuracy' => 96,
            'correct_chars' => 250,
            'incorrect_chars' => 5,
            'duration_seconds' => 30,
            'review_status' => TypingResult::REVIEW_CLEAR,
        ]);
    }

    $decision = app(LongitudinalBaseline::class)
        ->decisionFor($user->id, 'time', '30', 'en', 135);

    expect($decision->reason)->toBeNull()
        ->and($decision->context['center'])->toBe(100.0)
        ->and($decision->context['effective_threshold'])->toBe(140.0);
});

it('uses related configurations as supporting evidence but keeps languages separate', function () {
    $user = User::factory()->create();

    foreach ([166, 170, 174] as $wpm) {
        TypingResult::create([
            'user_id' => $user->id,
            'mode' => 'time',
            'mode_config' => '60',
            'language' => 'en',
            'net_wpm' => $wpm,
            'raw_wpm' => $wpm + 3,
            'accuracy' => 97,
            'correct_chars' => 800,
            'incorrect_chars' => 8,
            'duration_seconds' => 60,
            'review_status' => TypingResult::REVIEW_CLEAR,
        ]);
    }

    $baseline = app(LongitudinalBaseline::class);

    expect($baseline->decisionFor($user->id, 'time', '30', 'en', 171)->reason)->toBeNull()
        ->and($baseline->decisionFor($user->id, 'time', '30', 'id', 171)->reason)
        ->toBe('no_history_high');
});
