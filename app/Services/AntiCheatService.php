<?php

namespace App\Services;

/** Server-side sanity check: recomputes wpm/accuracy and rejects impossible sessions. */
class AntiCheatService
{
    /** Human WPM ceiling; above this is almost certainly fake (world record ~210-230). */
    private const MAX_HUMAN_WPM = 300;

    /** Minimum session duration (seconds) to be considered meaningful. */
    private const MIN_DURATION_SECONDS = 1.0;

    /**
     * Minimum throughput (chars/sec) for real typing vs. an idle/faked session. Crucial
     * for survival, where duration_seconds is itself the leaderboard metric. ~0.5 cps ≈ 6 WPM.
     */
    private const MIN_CHARS_PER_SECOND = 0.5;

    /**
     * Physically IMPOSSIBLE signals: no human can produce them, so they indicate
     * manipulation -- applies to all modes, solo and race alike.
     */
    private const IMPOSSIBLE_REASONS = [
        'wpm_too_high',
        'char_count_inconsistent',
        'accuracy_impossible',
    ];

    /** Empty session: not a real session, not worth saving (but not "cheating" either). */
    private const EMPTY_SESSION_REASONS = [
        'no_input',
        'duration_too_short',
    ];

    /**
     * Do these reasons indicate MANIPULATION (not just a weak session)?
     *
     * Used by the multiplayer path: players who quit or are slow are still recorded
     * to history; only those with impossible numbers are discarded.
     *
     * @param  array<string>  $reasons
     */
    public function isCheating(array $reasons): bool
    {
        return ! empty(array_intersect($reasons, self::IMPOSSIBLE_REASONS));
    }

    /**
     * Should a SOLO session's result be rejected (not saved, no EXP)?
     *
     * Deliberately does NOT use the raw `valid` flag. `valid` answers "did this
     * session pass every sanity-check", which conflates two different things:
     * cheating and merely-slow. Using it as the gate would make a GENUINELY SLOW
     * TYPIST (throughput below the threshold, e.g. a beginner at 5 WPM in time 60)
     * lose their result and EXP -- the exact opposite of the anti-cheat goal.
     *
     * Low throughput only means cheating in SURVIVAL, because only there is duration
     * the leaderboard metric: stay idle -> long duration -> top the board. In time,
     * duration is fixed by the mode; in words, a long duration only lowers WPM.
     * Stalling in those two modes hurts you, so there is nothing to guard against.
     *
     * @param  array<string>  $reasons
     * @param  string  $mode  'time' | 'words' | 'survival'
     */
    public function rejectsSoloResult(array $reasons, string $mode): bool
    {
        if ($this->isCheating($reasons)) {
            return true;
        }

        if (! empty(array_intersect($reasons, self::EMPTY_SESSION_REASONS))) {
            return true;
        }

        return $mode === 'survival'
            && in_array('throughput_too_low', $reasons, true);
    }

    /**
     * Check a session for plausibility and return its recomputed metrics.
     *
     * Note: `valid` = "passed ALL sanity-checks". That is not a synonym for "not
     * cheating" -- to decide accept/reject, use rejectsSoloResult()/isCheating().
     *
     * @param  int  $correctChars  Correct characters (for Net WPM).
     * @param  int  $totalChars  All characters typed (for Raw WPM & accuracy).
     * @param  float  $durationSeconds  Session duration in seconds.
     * @return array{valid: bool, net_wpm: float, raw_wpm: float, accuracy: float, reasons: array<string>}
     */
    public function check(int $correctChars, int $totalChars, float $durationSeconds): array
    {
        $reasons = [];

        // Recompute from characters & duration (standard: 1 word = 5 characters).
        $durationMinutes = $durationSeconds / 60;
        $netWpm = 0.0;
        $rawWpm = 0.0;

        if ($durationMinutes > 0) {
            $netWpm = round(($correctChars / 5) / $durationMinutes, 2);
            $rawWpm = round(($totalChars / 5) / $durationMinutes, 2);
        }

        $accuracy = $totalChars > 0
            ? round(($correctChars / $totalChars) * 100, 2)
            : 0.0;

        // Too short a duration = a meaningless session.
        if ($durationSeconds < self::MIN_DURATION_SECONDS) {
            $reasons[] = 'duration_too_short';
        }

        // WPM above the human ceiling.
        if ($netWpm > self::MAX_HUMAN_WPM || $rawWpm > self::MAX_HUMAN_WPM) {
            $reasons[] = 'wpm_too_high';
        }

        // Impossible accuracy.
        if ($accuracy > 100) {
            $reasons[] = 'accuracy_impossible';
        }

        // Character consistency: correct must not exceed total.
        if ($correctChars > $totalChars) {
            $reasons[] = 'char_count_inconsistent';
        }

        // No characters at all = not a real session.
        if ($totalChars <= 0) {
            $reasons[] = 'no_input';
        }

        // Throughput too low for the claimed duration (only checked above the minimum
        // duration, since short sessions naturally have a noisier ratio).
        if ($durationSeconds >= self::MIN_DURATION_SECONDS
            && ($totalChars / $durationSeconds) < self::MIN_CHARS_PER_SECOND) {
            $reasons[] = 'throughput_too_low';
        }

        return [
            'valid' => empty($reasons),
            'net_wpm' => $netWpm,
            'raw_wpm' => $rawWpm,
            'accuracy' => $accuracy,
            'reasons' => $reasons,
        ];
    }
}
