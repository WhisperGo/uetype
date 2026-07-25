<?php

namespace App\Services;

use App\Models\TypingResult;

/**
 * Per-player longitudinal anomaly check (anti-cheat report §7.5).
 *
 * UEType's edge over a pure typing site: it already stores every player's history per
 * mode/config. A run that clears the hard gates but is wildly out of line with that history
 * -- a sudden 70 -> 200 WPM leap, or a brand-new account debuting far above a sane speed --
 * is flagged for HUMAN review, never auto-rejected. Real players improve; punishing genuine
 * progress erodes trust far more than letting one suspicious run wait for a glance. So this
 * returns a review REASON (or null), and the caller marks the row `pending`, it does not
 * discard anything.
 */
class LongitudinalBaseline
{
    /** Compare against at most this many of the player's most recent runs in the same mode/config. */
    private const HISTORY_WINDOW = 20;

    /** Need at least this much history before a "spike over your own average" claim is meaningful. */
    private const MIN_HISTORY = 5;

    /** Flag if the new net WPM exceeds the recent average by more than this fraction. */
    private const SPIKE_FRACTION = 0.40;

    /** A player with (almost) no history debuting at/above this net WPM is flagged. */
    private const NO_HISTORY_WPM = 150.0;

    /**
     * Decide whether $netWpm is anomalous for this player in this mode/config.
     * Returns a short review reason, or null if the run looks in-line with their history.
     */
    public function reviewReasonFor(int $userId, string $mode, string $modeConfig, float $netWpm): ?string
    {
        $recent = TypingResult::query()
            ->where('user_id', $userId)
            ->where('mode', $mode)
            ->where('mode_config', $modeConfig)
            // Only compare against results that are themselves trustworthy, so a cheated run
            // can't quietly raise the baseline for the next one.
            ->whereIn('review_status', [TypingResult::REVIEW_CLEAR, TypingResult::REVIEW_APPROVED])
            ->orderByDesc('id')
            ->limit(self::HISTORY_WINDOW)
            ->pluck('net_wpm')
            ->map(fn ($w) => (float) $w)
            ->all();

        // No track record: a debut far above a plausible first-run speed is worth a look.
        if (count($recent) < self::MIN_HISTORY) {
            return $netWpm >= self::NO_HISTORY_WPM ? 'no_history_high' : null;
        }

        $avg = array_sum($recent) / count($recent);

        // Guard against a zero/near-zero average producing a meaningless ratio.
        if ($avg <= 0) {
            return $netWpm >= self::NO_HISTORY_WPM ? 'no_history_high' : null;
        }

        return $netWpm > $avg * (1 + self::SPIKE_FRACTION)
            ? 'longitudinal_spike'
            : null;
    }
}
