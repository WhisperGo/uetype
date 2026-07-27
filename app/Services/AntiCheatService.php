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
        'consistency_impossible',
        'keystroke_timing_uniform',
        'keystroke_timing_impossible',
        'keystroke_timing_identical',
    ];

    /**
     * Consistency (0-100, from TypingEngine::computeConsistency: 100 = perfectly even
     * per-second WPM) at or above which a HIGH-WPM run is not humanly plausible. Even
     * world-champion typists fluctuate between words, so a near-flat curve at speed is the
     * signature of a scripted/replayed run (e.g. a bot posting an identical WPM each second).
     */
    private const IMPOSSIBLE_CONSISTENCY = 97;

    /**
     * The consistency floor only bites ABOVE this net WPM. High consistency at low speed is
     * normal -- a careful beginner typing slowly and evenly -- so it must never be flagged.
     */
    private const CONSISTENCY_CHECK_WPM = 120.0;

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
     * Seconds of slack added to a FINISHED race duration before judging its speed.
     *
     * `finished_time_seconds` is an integer column written as round($elapsed), so the stored
     * value can sit up to half a second BELOW the real duration. That half second is noise on
     * a 20-second race and decisive on a 2-second one: a 10-word text finished in 2.2s stores
     * as 2, and the same run the server would score at 267 WPM it scores at 294 -- past the
     * ceiling, rejected, with the player told their speed was inhuman.
     *
     * It also made the outcome non-monotonic, which is how it was noticed: a 2.6s finish
     * (stores 3) passed while a faster 2.2s finish (stores 2) was rejected, so the same
     * player got different verdicts for the same pace depending on which side of a half
     * second they landed. That reads as random.
     *
     * Adding the maximum possible rounding loss makes the check use the SLOWEST duration the
     * run could have had, so the doubt created by our own rounding goes to the player rather
     * than against them. It costs nothing in cheat detection: a forged pace fast enough to
     * matter clears the ceiling by far more than this.
     *
     * This is a COMPENSATION for lost precision, not a raised ceiling. The real fix is to
     * store the duration with sub-second precision; see docs/features/multiplayer-race.md.
     * If that ever lands, this constant goes away with it -- do not treat it as a tuning knob.
     */
    private const FINISH_DURATION_ROUNDING_SLACK = 0.5;

    /**
     * Race-only WPM ceiling, tighter than MAX_HUMAN_WPM.
     *
     * In a race, progress% is client-reported, so "finishing" is a single number a
     * tampered client can simply assert -- and because placement is ranked by finish
     * TIME, a forged pace paced just under this ceiling would beat every honest player.
     * The world record is ~210-230 and sustaining it over a full race is rarer still, so
     * 240 sits just above genuine elite runs: it rejects a forged pace while leaving real
     * elite runs intact. (A looser 250 still admitted a ~245-WPM forgery indistinguishable
     * from a win; 240 narrows that window without touching honest play.)
     */
    private const MAX_RACE_WPM = 240;

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
     * Is this run impossibly steady for its speed? A near-flat per-second WPM curve (>=
     * IMPOSSIBLE_CONSISTENCY) at a high net WPM (> CONSISTENCY_CHECK_WPM) is something no
     * human produces -- it is the fingerprint of a bot posting a fixed WPM each tick, the
     * "perfect 185 for 120s" case in the anti-cheat report. Low WPM is exempt: a slow,
     * careful beginner can legitimately be very consistent.
     *
     * $consistency is nullable because computeConsistency() returns null for runs too short
     * to score (< 2 samples); a null is never flagged.
     */
    public function isImpossiblyConsistent(?int $consistency, float $netWpm): bool
    {
        return $consistency !== null
            && $consistency >= self::IMPOSSIBLE_CONSISTENCY
            && $netWpm > self::CONSISTENCY_CHECK_WPM;
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
     *
     * Deliberately does NOT apply FINISH_DURATION_ROUNDING_SLACK. That slack compensates for
     * an integer COLUMN; this path is handed a live float (race_starts_at -> now), so there
     * is no rounding to undo and adding slack would only widen the gap a forged payload can
     * hide in. If this ever starts reading a stored duration, it needs the slack too.
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
        // Speed is judged against the duration PLUS the rounding slack (see the constant):
        // the caller's duration comes from an integer column, so it can understate the real
        // elapsed time by up to half a second and inflate the derived WPM.
        //
        // Only the SPEED judgement gets the slack. The rest of check() -- empty input,
        // char-count consistency, impossible accuracy -- is unaffected by the denominator,
        // and `duration_too_short` must keep seeing the value as stored or a zero-second
        // session would start looking like a half-second one.
        $speedDuration = $durationSeconds > 0
            ? $durationSeconds + self::FINISH_DURATION_ROUNDING_SLACK
            : $durationSeconds;

        // totalChars == correctChars: race progress only advances on correct characters.
        $check = $this->check($correctChars, $correctChars, $durationSeconds);
        $reasons = $check['reasons'];

        // Recomputed on the slack-adjusted duration, then the un-adjusted verdict is dropped:
        // check() already flagged wpm_too_high off the raw value, and that is the flag this
        // whole change exists to stop being wrong.
        $speedCheck = $this->check($correctChars, $correctChars, $speedDuration);

        if (! in_array('wpm_too_high', $speedCheck['reasons'], true)) {
            $reasons = array_values(array_diff($reasons, ['wpm_too_high']));
        }

        if ($progressPercent >= self::RACE_ACCURACY_CHECK_PROGRESS
            && $accuracy < self::RACE_MIN_ACCURACY_AT_PROGRESS) {
            $reasons[] = 'accuracy_progress_inconsistent';
        }

        // Tighter race ceiling (see MAX_RACE_WPM): catches the "teleport to 100%" payload,
        // which lands just under the general 300 limit once the race has run ~10 seconds.
        //
        // Reads the slack-adjusted speed, not the raw one -- this is the gate that actually
        // bites (240 is far below the 300 in check()), so leaving it on the raw value would
        // make the whole rounding compensation above pointless.
        if ($speedCheck['net_wpm'] > self::MAX_RACE_WPM && ! in_array('wpm_too_high', $reasons, true)) {
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
