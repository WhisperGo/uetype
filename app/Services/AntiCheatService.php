<?php

namespace App\Services;

/**
 * Validasi anti-cheat server-side untuk hasil mengetik.
 *
 * Alur (blueprint 5.1):
 *  - client mengirim timing tiap keystroke dalam payload,
 *  - server MENGHITUNG ULANG WPM/akurasi dari timing (tidak percaya angka client),
 *  - server mendeteksi pola tak-manusiawi (interval terlalu seragam / kecepatan superhuman),
 *  - server hanya menyimpan verdict (is_suspicious + cheat_summary); timing mentah dibuang.
 */
class AntiCheatService
{
    /**
     * Ambang batas. Disetel konservatif agar pengetik cepat asli tidak salah-tuduh.
     */
    private const SUPERHUMAN_WPM = 250;          // rekor dunia ~ 210-230 WPM
    private const MIN_HUMAN_INTERVAL_MS = 30;     // <30ms antar tuts konsisten = tidak manusiawi
    private const MIN_CV_THRESHOLD = 0.12;        // koefisien variansi interval < 0.12 = terlalu robotik

    /**
     * @param  array<int|float>  $timings  Timestamp tiap keystroke karakter (ms, relatif ke start).
     * @param  int  $correctKeystrokes  Jumlah tuts benar (dari client, dipakai utk akurasi).
     * @param  int  $totalKeystrokes    Jumlah seluruh tuts (dari client).
     * @param  int|float  $clientWpm     WPM laporan client (untuk dibandingkan).
     * @return array{wpm: float, accuracy: float, is_suspicious: bool, cheat_summary: array|null}
     */
    public function analyze(array $timings, int $correctKeystrokes, int $totalKeystrokes, $clientWpm): array
    {
        $reasons = [];
        $count = count($timings);

        // Timing tak cukup untuk dianalisis -> jangan tuduh curang (absennya data bukan bukti).
        // Kembalikan fallback ke angka client tanpa verdict mencurigakan.
        if ($count < 5) {
            return [
                'wpm' => (float) $clientWpm,
                'accuracy' => $totalKeystrokes > 0
                    ? round(($correctKeystrokes / $totalKeystrokes) * 100, 2)
                    : 0.0,
                'is_suspicious' => false,
                'cheat_summary' => null,
            ];
        }

        // --- Rekalkulasi WPM dari timing (sumber kebenaran = server) ---
        $serverWpm = 0.0;

        if ($count >= 2) {
            $durationMs = (float) end($timings) - (float) reset($timings);
            $durationMin = $durationMs / 60000;

            if ($durationMin > 0) {
                // standar: 1 kata = 5 karakter; pakai jumlah tuts benar sebagai karakter ter-"commit"
                $serverWpm = round(($correctKeystrokes / 5) / $durationMin, 2);
            }
        }

        // --- Rekalkulasi akurasi ---
        $serverAccuracy = $totalKeystrokes > 0
            ? round(($correctKeystrokes / $totalKeystrokes) * 100, 2)
            : 0.0;

        // --- Deteksi 1: kecepatan superhuman ---
        if ($serverWpm > self::SUPERHUMAN_WPM) {
            $reasons[] = 'superhuman_wpm';
        }

        // --- Deteksi 2: selisih besar antara WPM client vs server (manipulasi angka kirim) ---
        if ($clientWpm > 0 && abs($clientWpm - $serverWpm) > max(20, $serverWpm * 0.4)) {
            $reasons[] = 'client_server_wpm_mismatch';
        }

        // --- Hitung interval antar keystroke untuk deteksi pola ---
        $intervals = [];
        for ($i = 1; $i < $count; $i++) {
            $intervals[] = (float) $timings[$i] - (float) $timings[$i - 1];
        }

        if (count($intervals) >= 5) {
            $mean = array_sum($intervals) / count($intervals);

            // Deteksi 3: interval rata-rata di bawah batas fisik manusia
            if ($mean > 0 && $mean < self::MIN_HUMAN_INTERVAL_MS) {
                $reasons[] = 'inhuman_interval';
            }

            // Deteksi 4: interval terlalu seragam (variansi rendah = autotyper/macro)
            if ($mean > 0) {
                $variance = 0.0;
                foreach ($intervals as $iv) {
                    $variance += ($iv - $mean) ** 2;
                }
                $variance /= count($intervals);
                $stdDev = sqrt($variance);
                $cv = $stdDev / $mean; // koefisien variansi

                if ($cv < self::MIN_CV_THRESHOLD) {
                    $reasons[] = 'uniform_intervals';
                }
            }
        }

        $isSuspicious = ! empty($reasons);

        return [
            'wpm' => $serverWpm,
            'accuracy' => $serverAccuracy,
            'is_suspicious' => $isSuspicious,
            'cheat_summary' => $isSuspicious ? [
                'reasons' => $reasons,
                'server_wpm' => $serverWpm,
                'client_wpm' => (float) $clientWpm,
                'keystroke_count' => $count,
            ] : null,
        ];
    }
}
