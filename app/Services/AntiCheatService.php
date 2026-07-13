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
     * Check a session for plausibility and return its recomputed metrics.
     *
     * @param  int  $correctChars  Correct characters (for Net WPM).
     * @param  int  $totalChars  All characters typed (for Raw WPM & accuracy).
     * @param  float  $durationSeconds  Session duration in seconds.
     * @return array{valid: bool, net_wpm: float, raw_wpm: float, accuracy: float, reasons: array<string>}
     */
    public function check(int $correctChars, int $totalChars, float $durationSeconds): array
    {
        $reasons = [];

        // Hitung ulang dari karakter & durasi (standar: 1 kata = 5 karakter).
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

        // Durasi terlalu pendek = sesi tidak bermakna.
        if ($durationSeconds < self::MIN_DURATION_SECONDS) {
            $reasons[] = 'duration_too_short';
        }

        // WPM di atas batas manusiawi.
        if ($netWpm > self::MAX_HUMAN_WPM || $rawWpm > self::MAX_HUMAN_WPM) {
            $reasons[] = 'wpm_too_high';
        }

        // Accuracy mustahil.
        if ($accuracy > 100) {
            $reasons[] = 'accuracy_impossible';
        }

        // Konsistensi karakter: benar tak boleh > total.
        if ($correctChars > $totalChars) {
            $reasons[] = 'char_count_inconsistent';
        }

        // Tidak ada karakter sama sekali = bukan sesi nyata.
        if ($totalChars <= 0) {
            $reasons[] = 'no_input';
        }

        // Throughput terlalu rendah untuk durasi yang diklaim (hanya dicek di atas durasi
        // minimum, karena sesi pendek wajar punya rasio lebih bising).
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
