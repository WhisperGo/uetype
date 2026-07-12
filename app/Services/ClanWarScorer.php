<?php

namespace App\Services;

use App\Models\TypingResult;

/**
 * Computes Clan War points from an already-validated TypingResult
 * (post-AntiCheatService). Pure function, no state/DB.
 *
 * Formula: points = ceiling[mode] × performanceRatio × accuracyMultiplier
 * - performanceRatio: Time/Words use net_wpm/WPM_SCALE; Survival uses
 *   duration_seconds/SURVIVAL_SECONDS_SCALE (its metric is how long you last). Capped at 1.0.
 * - accuracyMultiplier (0.5-1.0x) is identical to the User::addExp() formula.
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
