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
     * Sinyal yang MUSTAHIL secara fisik: tak ada manusia yang bisa menghasilkannya,
     * jadi ini indikasi manipulasi -- berlaku di semua mode, solo maupun race.
     */
    private const IMPOSSIBLE_REASONS = [
        'wpm_too_high',
        'char_count_inconsistent',
        'accuracy_impossible',
    ];

    /** Sesi kosong: bukan sesi nyata, tak layak disimpan (tapi juga bukan "curang"). */
    private const EMPTY_SESSION_REASONS = [
        'no_input',
        'duration_too_short',
    ];

    /**
     * Apakah alasan-alasan ini menandakan MANIPULASI (bukan sekadar sesi lemah)?
     *
     * Dipakai jalur multiplayer: pemain yang menyerah / lambat tetap dicatat ke
     * riwayat, hanya yang angkanya mustahil yang dibuang.
     *
     * @param  array<string>  $reasons
     */
    public function isCheating(array $reasons): bool
    {
        return ! empty(array_intersect($reasons, self::IMPOSSIBLE_REASONS));
    }

    /**
     * Apakah hasil sesi SOLO harus ditolak (tak disimpan, tak dapat EXP)?
     *
     * Sengaja TIDAK memakai flag `valid` mentah. `valid` menjawab "apakah sesi ini
     * lolos semua sanity-check", yang mencampur dua hal berbeda: kecurangan dan
     * sekadar-lambat. Memakainya sebagai gerbang membuat PENGETIK LAMBAT SUNGGUHAN
     * (throughput di bawah ambang, mis. pemula 5 WPM di mode time 60) kehilangan
     * hasil dan EXP-nya -- persis kebalikan dari tujuan anti-cheat.
     *
     * Throughput rendah hanya bermakna curang di SURVIVAL, karena hanya di sanalah
     * durasi adalah metrik papan peringkat: diam saja -> durasi panjang -> juara.
     * Di time durasi sudah dikunci oleh mode; di words durasi panjang justru
     * menurunkan WPM. Mengulur waktu di dua mode itu merugikan diri sendiri, jadi
     * tak ada yang perlu dijaga.
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
     * Catatan: `valid` = "lolos SEMUA sanity-check". Itu bukan sinonim dari "tidak
     * curang" -- untuk memutuskan tolak/terima, pakai rejectsSoloResult()/isCheating().
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
