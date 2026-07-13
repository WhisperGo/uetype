<?php

namespace App\Services;

use App\Models\TypingResult;

/**
 * Scores a validated TypingResult for a Clan War. Pure function, no state/DB.
 *
 * points = ceiling[mode] × performanceRatio × accuracyMultiplier
 * - performanceRatio: Time/Words use net_wpm/WPM_SCALE; Survival uses
 *   duration_seconds/SURVIVAL_SECONDS_SCALE (how long you last). Capped at 1.0.
 * - accuracyMultiplier (0.5-1.0x) matches the User::addExp() formula.
 */
class ClanWarScorer
{
    /** Points earned by a result for a war mode/config (0 if not a valid mode). */
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
