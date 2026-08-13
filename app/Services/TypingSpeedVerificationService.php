<?php

namespace App\Services;

use App\Models\TypingResult;
use App\Models\TypingVerificationAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TypingSpeedVerificationService
{
    public const DURATION_SECONDS = 30;

    public const ATTEMPT_TTL_MINUTES = 10;

    private const MIN_ACCURACY = 75.0;

    private const MIN_SERVER_ELAPSED_SECONDS = 28.0;

    private const MAX_SERVER_ELAPSED_SECONDS = 45.0;

    public function eligibleResultFor(User $user): ?TypingResult
    {
        return TypingResult::query()
            ->where('user_id', $user->id)
            ->whereIn('mode', ['time', 'words'])
            ->pendingVerification()
            ->whereIn('review_reason', ['no_history_high', 'longitudinal_spike'])
            ->latest('id')
            ->first();
    }

    /** @return array{attempt:TypingVerificationAttempt,token:string} */
    public function issue(User $user): array
    {
        $source = $this->eligibleResultFor($user);

        abort_unless($source, 404);

        return DB::transaction(function () use ($user) {
            // Serialise starts for this account even when it has no previous attempt row to
            // lock. Otherwise two simultaneous tabs could both observe an empty active set.
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $source = $this->eligibleResultFor($user);
            abort_unless($source, 404);

            TypingVerificationAttempt::query()
                ->where('user_id', $user->id)
                ->where('status', TypingVerificationAttempt::STATUS_ACTIVE)
                ->lockForUpdate()
                ->update([
                    'status' => TypingVerificationAttempt::STATUS_EXPIRED,
                    'consumed_at' => now(),
                ]);

            $token = Str::random(64);
            $text = app(TextGeneratorService::class)->forSoloMode('time', '30', $source->language);

            $attempt = TypingVerificationAttempt::create([
                'user_id' => $user->id,
                'source_result_id' => $source->id,
                'language' => $source->language,
                'token_hash' => hash('sha256', $token),
                'challenge_text' => $text,
                'text_hash' => hash('sha256', $text),
                'status' => TypingVerificationAttempt::STATUS_ACTIVE,
                'started_at' => now(),
                'expires_at' => now()->addMinutes(self::ATTEMPT_TTL_MINUTES),
            ]);

            Log::info('speed_verification_started', [
                'user_id' => $user->id,
                'attempt_id' => $attempt->id,
                'source_result_id' => $source->id,
                'language' => $source->language,
                'source_mode' => $source->mode instanceof \BackedEnum ? $source->mode->value : $source->mode,
                'source_config' => $source->mode_config,
                'source_wpm' => (float) $source->net_wpm,
            ]);

            return ['attempt' => $attempt, 'token' => $token];
        }, 3);
    }

    /**
     * @param  array<int, mixed>  $events
     * @return array{passed:bool,reason:string,promoted_count:int,wpm:float,ceiling:float}
     */
    public function complete(User $user, int $attemptId, string $token, array $events): array
    {
        return DB::transaction(function () use ($user, $attemptId, $token, $events) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $attempt = TypingVerificationAttempt::query()
                ->whereKey($attemptId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $attempt
                || $attempt->status !== TypingVerificationAttempt::STATUS_ACTIVE
                || $attempt->consumed_at !== null
                || ! hash_equals($attempt->token_hash, hash('sha256', $token))) {
                return $this->failure($attempt, 'attempt_invalid');
            }

            if ($attempt->expires_at->isPast()) {
                return $this->failure($attempt, 'attempt_expired', TypingVerificationAttempt::STATUS_EXPIRED);
            }

            if ($attempt->input_started_at === null) {
                return $this->failure($attempt, 'input_not_started');
            }

            $elapsed = $attempt->input_started_at->diffInMilliseconds(now()) / 1000;

            if ($elapsed < self::MIN_SERVER_ELAPSED_SECONDS || $elapsed > self::MAX_SERVER_ELAPSED_SECONDS) {
                return $this->failure($attempt, 'elapsed_invalid', meta: ['elapsed_seconds' => $elapsed]);
            }

            if (! hash_equals($attempt->text_hash, hash('sha256', $attempt->challenge_text))) {
                return $this->failure($attempt, 'text_invalid');
            }

            $replay = app(TypingVerificationReplay::class)->replay($attempt->challenge_text, $events);

            if (! $replay['valid']) {
                return $this->failure($attempt, $replay['reason'] ?? 'replay_invalid');
            }

            $timing = app(KeystrokeAnalyzer::class)->analyze(
                $replay['intervals'],
                $replay['total_keystrokes'],
            );

            if (! $timing['has_data'] || $timing['reasons'] !== []) {
                return $this->failure($attempt, 'timing_invalid', meta: [
                    'timing' => $timing,
                    'sample_count' => count($replay['intervals']),
                ]);
            }

            $hardGate = app(AntiCheatService::class)->check(
                $replay['correct_chars'],
                $replay['total_keystrokes'],
                TypingVerificationReplay::DURATION_SECONDS,
            );

            if (app(AntiCheatService::class)->rejectsSoloResult($hardGate['reasons'], 'time')
                || $replay['accuracy'] < self::MIN_ACCURACY) {
                return $this->failure($attempt, 'result_invalid', meta: [
                    'reasons' => $hardGate['reasons'],
                    'accuracy' => $replay['accuracy'],
                ]);
            }

            $source = TypingResult::query()
                ->whereKey($attempt->source_result_id)
                ->where('user_id', $user->id)
                ->pendingVerification()
                ->lockForUpdate()
                ->first();

            if (! $source) {
                return $this->failure($attempt, 'source_unavailable');
            }

            $capabilities = app(TypingSpeedCapabilityService::class);
            $challengeCeiling = $capabilities->ceiling($replay['net_wpm']);

            if ((float) $source->net_wpm > $challengeCeiling) {
                return $this->failure($attempt, 'pace_insufficient', meta: [
                    'verification_wpm' => $replay['net_wpm'],
                    'source_wpm' => (float) $source->net_wpm,
                    'ceiling' => $challengeCeiling,
                ]);
            }

            $result = $capabilities->recordAndPromote(
                $user,
                $attempt->language,
                $replay['net_wpm'],
                $replay['accuracy'],
                [
                    'attempt_id' => $attempt->id,
                    'timing' => $timing,
                    'sample_count' => count($replay['intervals']),
                    'event_count' => count($events),
                    'server_elapsed_seconds' => round($elapsed, 3),
                ],
            );

            $attempt->update([
                'status' => TypingVerificationAttempt::STATUS_PASSED,
                'consumed_at' => now(),
                'challenge_text' => '',
                'result_meta' => [
                    'verification_wpm' => $replay['net_wpm'],
                    'accuracy' => $replay['accuracy'],
                    'ceiling' => $result['ceiling'],
                    'promoted_count' => count($result['promoted_ids']),
                ],
            ]);

            Log::info('speed_verification_passed', [
                'user_id' => $user->id,
                'attempt_id' => $attempt->id,
                'verification_wpm' => $replay['net_wpm'],
                'ceiling' => $result['ceiling'],
                'allowance' => round($result['ceiling'] - $replay['net_wpm'], 2),
                'rule_version' => TypingSpeedCapabilityService::RULE_VERSION,
                'promoted_count' => count($result['promoted_ids']),
            ]);

            return [
                'passed' => true,
                'reason' => 'passed',
                'promoted_count' => count($result['promoted_ids']),
                'wpm' => $replay['net_wpm'],
                'ceiling' => $result['ceiling'],
            ];
        }, 3);
    }

    /** Start the authoritative 30-second window on the first real input, exactly once. */
    public function begin(User $user, int $attemptId, string $token): bool
    {
        return DB::transaction(function () use ($user, $attemptId, $token) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $attempt = TypingVerificationAttempt::query()
                ->whereKey($attemptId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $attempt
                || $attempt->status !== TypingVerificationAttempt::STATUS_ACTIVE
                || $attempt->consumed_at !== null
                || $attempt->expires_at->isPast()
                || ! hash_equals($attempt->token_hash, hash('sha256', $token))) {
                return false;
            }

            if ($attempt->input_started_at === null) {
                $attempt->update(['input_started_at' => now()]);
            }

            return true;
        }, 3);
    }

    private function failure(
        ?TypingVerificationAttempt $attempt,
        string $reason,
        string $status = TypingVerificationAttempt::STATUS_FAILED,
        array $meta = [],
    ): array {
        if ($attempt && $attempt->status === TypingVerificationAttempt::STATUS_ACTIVE) {
            $attempt->update([
                'status' => $status,
                'consumed_at' => now(),
                'challenge_text' => '',
                'result_meta' => ['reason' => $reason] + $meta,
            ]);
        }

        Log::warning($status === TypingVerificationAttempt::STATUS_EXPIRED
            ? 'speed_verification_expired'
            : 'speed_verification_failed', [
                'user_id' => $attempt?->user_id,
                'attempt_id' => $attempt?->id,
                'reason' => $reason,
            ] + $meta);

        return [
            'passed' => false,
            'reason' => $reason,
            'promoted_count' => 0,
            'wpm' => 0.0,
            'ceiling' => 0.0,
        ];
    }
}
