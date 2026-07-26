<?php

namespace App\Services;

/**
 * Timing-based bot detection (anti-cheat report §7.1).
 *
 * The other guards validate MAGNITUDES (how many chars, how many seconds); this one is the
 * one place the server looks at the SHAPE of the typing -- the inter-keystroke intervals.
 * Human typing has a very characteristic distribution: uneven, right-skewed, with natural
 * outliers (a pause on a hard word, a burst on a familiar one). A bot emits intervals that
 * are too uniform, or physically impossible, or literally identical (a fixed time.sleep).
 *
 * FAIL-SAFE by design: an empty/too-small sample returns "no data" (not cheating). Older
 * cached client bundles send no intervals, and rejecting them would lock out honest players
 * on deploy. TypingEngine treats "no data" as log-only, never an automatic rejection.
 */
class KeystrokeAnalyzer
{
    /** Below this many usable intervals there isn't enough signal to judge -- treat as no data. */
    private const MIN_SAMPLE = 20;

    /**
     * Coefficient of variation (stddev/mean) below which the rhythm is impossibly even.
     * Real typing sits well above this; a metronome bot sits near 0. 0.15 leaves comfortable
     * room for fast, steady humans while still catching a fixed-interval script.
     */
    private const MIN_COEFF_VARIATION = 0.15;

    /** Intervals faster than this (ms) are past human finger speed; a few happen, a flood doesn't. */
    private const IMPOSSIBLE_INTERVAL_MS = 40;

    /** Reject if more than this FRACTION of intervals are sub-40ms (isolated fast digraphs are fine). */
    private const MAX_IMPOSSIBLE_FRACTION = 0.30;

    /** Reject if a single interval value makes up more than this fraction (a fixed sleep repeats). */
    private const MAX_IDENTICAL_FRACTION = 0.60;

    /**
     * Judge a keystroke-interval sample. `$claimedKeystrokes` is what the run reported typing,
     * so we can tell a partial sample from a suspiciously empty one.
     *
     * @param  array<int|float>  $intervals  reservoir sample of inter-key gaps (ms)
     * @return array{has_data: bool, reasons: array<string>}
     */
    public function analyze(array $intervals, int $claimedKeystrokes = 0): array
    {
        // Keep only sane positive numbers; drop the trailing/idle spikes and any garbage.
        $clean = array_values(array_filter(
            array_map(fn ($v) => is_numeric($v) ? (float) $v : null, $intervals),
            fn ($v) => $v !== null && $v > 0 && $v < 5000,
        ));

        $n = count($clean);

        // Not enough signal: no verdict. The caller decides what to do with "no data"
        // (log-only), so an honest short run or an old bundle is never punished here.
        if ($n < self::MIN_SAMPLE) {
            return ['has_data' => false, 'reasons' => []];
        }

        $reasons = [];

        $mean = array_sum($clean) / $n;

        // 1. Impossibly even rhythm (coefficient of variation near zero).
        if ($mean > 0) {
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $clean)) / $n;
            $cv = sqrt($variance) / $mean;

            if ($cv < self::MIN_COEFF_VARIATION) {
                $reasons[] = 'keystroke_timing_uniform';
            }
        }

        // 2. Too many physically impossible intervals (< 40ms).
        $impossible = count(array_filter($clean, fn ($v) => $v < self::IMPOSSIBLE_INTERVAL_MS));
        if ($impossible / $n > self::MAX_IMPOSSIBLE_FRACTION) {
            $reasons[] = 'keystroke_timing_impossible';
        }

        // 3. One interval value dominates -- the signature of a fixed time.sleep(k).
        $counts = array_count_values(array_map(fn ($v) => (string) (int) round($v), $clean));
        if ((max($counts) / $n) > self::MAX_IDENTICAL_FRACTION) {
            $reasons[] = 'keystroke_timing_identical';
        }

        return ['has_data' => true, 'reasons' => $reasons];
    }
}
