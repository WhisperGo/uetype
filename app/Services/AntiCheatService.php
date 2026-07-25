<?php

namespace App\Services;

/** Server-side sanity check: recomputes wpm/accuracy and rejects impossible sessions. */
class AntiCheatService
{
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
        'accuracy_progress_inconsistent',
    ];

    /** Empty session: not a real session, not worth saving (but not "cheating" either). */
    private const EMPTY_SESSION_REASONS = [
        'no_input',
        'duration_too_short',
    ];

    /**
     * Race-only: below this accuracy, a HIGH-progress result is internally impossible.
     * Progress advances only on correct characters, so completing most of the text
     * demands mostly-correct typing -- an accuracy this low alongside high progress
     * means the client reported contradictory numbers (classic "fast garbage" cheat,
     * e.g. 200 WPM at 3% accuracy). Not applied at low progress, where a low accuracy
     * is a normal weak attempt.
     */
    private const RACE_MIN_ACCURACY_AT_PROGRESS = 50.0;

    /** Race-only: the progress% above which the accuracy floor above is enforced. */
    private const RACE_ACCURACY_CHECK_PROGRESS = 50;

    /**
     * Race-only WPM ceiling, tighter than MAX_HUMAN_WPM.
     *
     * In a race, progress% is client-reported, so "finishing" is a single number a
     * tampered client can simply assert. Against a ~240-character text that makes a
     * 10-second teleport read as 290 WPM -- under the 300 ceiling, therefore accepted as
     * a legitimate win. The world record is ~210-230 and sustaining it over a full race
     * is rarer still, so 250 rejects teleports while leaving genuine elite runs intact.
     */
    private const MAX_RACE_WPM = 250;

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
     * Should a RACE result be rejected (not written to history, no EXP)?
     *
     * Stricter than the old race gate (which rejected only isCheating()): it now also
     * drops EMPTY sessions -- a player who joined and never typed a single character
     * (no_input) would otherwise land in multiplayer_match_history as a real 0-WPM row
     * and drag down their average. Aligns the race path with solo mode.
     *
     * Deliberately does NOT reject `duration_too_short` or `throughput_too_low`: a
     * genuine DNF carries the 999s sentinel and a slow finisher has a real (low) WPM --
     * both are legitimate race outcomes that must stay recorded. Only the impossible
     * (isCheating) and the truly-empty (no_input) are dropped.
     *
     * @param  array<string>  $reasons
     */
    public function rejectsRaceResult(array $reasons): bool
    {
        return $this->isCheating($reasons)
            || in_array('no_input', $reasons, true);
    }

    /**
     * Is this race pace beyond what a human can physically reach?
     *
     * Used on the LIVE path, where progress% is client-reported: a tampered client can
     * assert "100%" at any moment. Rejecting the update outright stops it taking a finish
     * time and a place, which is what actually decides the winner -- scoring it as 0 WPM
     * would not, since placement is ranked by time, not speed.
     */
    public function exceedsRaceSpeed(int $correctChars, float $durationSeconds): bool
    {
        return $this->check($correctChars, $correctChars, $durationSeconds)['net_wpm'] > self::MAX_RACE_WPM;
    }

    /**
     * Full reason list for a RACE result: the standard check() signals PLUS the
     * race-only progress/accuracy consistency signal.
     *
     * Why race-only and why here: in a race, WPM is derived server-side from progress%
     * (correct chars = progress% x textLength), so the server never sees the raw
     * correct/total split -- it can't recompute accuracy from characters. Accuracy is
     * reported by the client. The one cross-check the server CAN make is against
     * progress: finishing (or nearly finishing) the text requires mostly-correct
     * typing, so a high progress alongside a very low accuracy is contradictory and
     * flags manipulation. At low progress a low accuracy is a normal weak attempt, so
     * the floor is only enforced above RACE_ACCURACY_CHECK_PROGRESS.
     *
     * @return array<string>
     */
    public function raceResultReasons(int $correctChars, float $durationSeconds, int $progressPercent, float $accuracy): array
    {
        // totalChars == correctChars: race progress only advances on correct characters.
        $check = $this->check($correctChars, $correctChars, $durationSeconds);
        $reasons = $check['reasons'];

        if ($progressPercent >= self::RACE_ACCURACY_CHECK_PROGRESS
            && $accuracy < self::RACE_MIN_ACCURACY_AT_PROGRESS) {
            $reasons[] = 'accuracy_progress_inconsistent';
        }

        // Tighter race ceiling (see MAX_RACE_WPM): catches the "teleport to 100%" payload,
        // which lands just under the general 300 limit once the race has run ~10 seconds.
        if ($check['net_wpm'] > self::MAX_RACE_WPM && ! in_array('wpm_too_high', $reasons, true)) {
            $reasons[] = 'wpm_too_high';
        }

        return $reasons;
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
