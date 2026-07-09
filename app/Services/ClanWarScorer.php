<?php

namespace App\Services;

use App\Models\TypingResult;

/**
 * Menghitung poin Clan War dari sebuah TypingResult yang sudah tervalidasi
 * (post-AntiCheatService). Fungsi murni, tanpa state/DB.
 *
 * Formula: poin = ceiling[mode] × performanceRatio × accuracyMultiplier
 * - performanceRatio: Time/Words pakai net_wpm/WPM_SCALE, Survival pakai
 *   duration_seconds/SURVIVAL_SECONDS_SCALE (metriknya lama bertahan). Di-cap ke 1.0.
 * - accuracyMultiplier (0.5-1.0x) identik dengan rumus User::addExp().
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
