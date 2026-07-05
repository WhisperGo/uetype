<?php

namespace App\Services;

use App\Models\TypingResult;

/**
 * Menghitung poin Clan War dari sebuah TypingResult yang SUDAH tervalidasi
 * (post-AntiCheatService). Murni fungsi, tanpa state/DB -- mudah di-unit-test
 * seperti EloCalculator.
 *
 * Formula:
 *   poin = ceiling[mode] × performanceRatio × accuracyMultiplier
 *
 * - performanceRatio: Time/Words pakai net_wpm/WPM_SCALE, Survival pakai
 *   duration_seconds/SURVIVAL_SECONDS_SCALE (survival tak punya WPM tetap;
 *   metriknya lama bertahan). Keduanya di-cap ke 1.0.
 * - accuracyMultiplier (0.5–1.0×) identik dengan rumus User::addExp() supaya
 *   konsisten dengan sistem EXP.
 */
class ClanWarScorer
{
    public static function score(string $mode, string $config, TypingResult $result): float
    {
        $ceiling = ClanWarModeCatalog::ceilingFor($mode, $config);

        if ($ceiling === null) {
            return 0.0;
        }

        $accuracyMultiplier = 0.5 + 0.5 * (max(0, min(100, (float) $result->accuracy)) / 100);

        $performanceRatio = $mode === 'survival'
            ? min(1.0, (float) $result->duration_seconds / ClanWarModeCatalog::SURVIVAL_SECONDS_SCALE)
            : min(1.0, (float) $result->net_wpm / ClanWarModeCatalog::WPM_SCALE);

        return round($ceiling * $performanceRatio * $accuracyMultiplier, 2);
    }
}
