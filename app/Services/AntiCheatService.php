<?php

namespace App\Services;

/**
 * Validasi kewajaran (sanity check) server-side untuk hasil mengetik.
 *
 * Sesuai requirement Statistics & Scores bagian 5: untuk skala proyek ini
 * TIDAK perlu anti-cheat canggih. Cukup tolak hasil yang mustahil sebelum
 * disimpan. Validasi per-keystroke (analisis timing/pola) sengaja TIDAK
 * dilakukan karena "terlalu mahal untuk timeline; jangan ke sana".
 *
 * Prinsip: jangan percaya angka client mentah-mentah untuk hal yang masuk
 * leaderboard / memberi EXP. Server menghitung ulang dari karakter & durasi,
 * lalu menolak sesi yang tidak masuk akal (bukan menyimpannya).
 */
class AntiCheatService
{
    /** Batas WPM manusiawi; di atas ini hampir pasti palsu (rekor dunia ~210-230). */
    private const MAX_HUMAN_WPM = 300;

    /** Durasi minimum (detik) agar sebuah sesi dianggap bermakna. */
    private const MIN_DURATION_SECONDS = 1.0;

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

        // --- Server HITUNG ULANG dari karakter & durasi (tidak percaya WPM client) ---
        // Standar: 1 kata = 5 karakter.
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

        // --- Sanity check 1: durasi terlalu pendek = sesi tidak bermakna ---
        if ($durationSeconds < self::MIN_DURATION_SECONDS) {
            $reasons[] = 'duration_too_short';
        }

        // --- Sanity check 2: WPM di atas batas manusiawi ---
        if ($netWpm > self::MAX_HUMAN_WPM || $rawWpm > self::MAX_HUMAN_WPM) {
            $reasons[] = 'wpm_too_high';
        }

        // --- Sanity check 3: accuracy mustahil ---
        if ($accuracy > 100) {
            $reasons[] = 'accuracy_impossible';
        }

        // --- Sanity check 4: konsistensi karakter (benar tak boleh > total) ---
        if ($correctChars > $totalChars) {
            $reasons[] = 'char_count_inconsistent';
        }

        // --- Sanity check 5: tidak ada karakter sama sekali = bukan sesi nyata ---
        if ($totalChars <= 0) {
            $reasons[] = 'no_input';
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
