<?php

namespace App\Services;

/**
 * Sanity check server-side untuk hasil mengetik. Tidak percaya angka WPM/accuracy
 * dari client: server menghitung ulang dari karakter & durasi, lalu menolak sesi
 * yang tidak masuk akal sebelum disimpan/masuk leaderboard.
 */
class AntiCheatService
{
    /** Batas WPM manusiawi; di atas ini hampir pasti palsu (rekor dunia ~210-230). */
    private const MAX_HUMAN_WPM = 300;

    /** Durasi minimum (detik) agar sebuah sesi dianggap bermakna. */
    private const MIN_DURATION_SECONDS = 1.0;

    /**
     * Throughput minimum (karakter/detik) agar dianggap aktivitas mengetik nyata, bukan
     * sesi idle/dipalsukan (durasi besar, input sepele). Krusial untuk survival, di mana
     * duration_seconds sendiri adalah metrik leaderboard. ~0.5 cps ≈ 6 WPM.
     */
    private const MIN_CHARS_PER_SECOND = 0.5;

    /**
     * Periksa kewajaran sebuah hasil sesi.
     *
     * @param  int    $correctChars   Jumlah karakter benar (untuk Net WPM).
     * @param  int    $totalChars     Jumlah seluruh karakter diketik (untuk Raw WPM & accuracy).
     * @param  float  $durationSeconds Durasi sesi dalam detik.
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
