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
        return static::breakdown($mode, $config, $result)['points'] ?? 0.0;
    }

    /**
     * The numbers score() multiplies together, so a screen can explain WHY an attempt was worth
     * what it was worth without restating the formula.
     *
     * score() is defined in terms of THIS rather than the other way round: a result screen that
     * re-derived the ratio itself would be a second source of truth, free to drift from the
     * number actually written to clan_war_mode_claims.points -- the one the scoreboard sums.
     *
     * `basis` names WHICH quantity drove the ratio, because survival is scored on how long you
     * lasted and time/words on net WPM. Without it a caller has to re-implement that branch to
     * label the number, and a screen reading "pace ... wpm" for survival hard would be stating
     * something false about the highest-ceiling mode in the game.
     *
     * The two displayed factors are rounded, but `points` multiplies the UNROUNDED ratio -- the
     * stored score must not change because a screen wanted a shorter number.
     *
     * @return array{ceiling: int, basis: 'wpm'|'duration', basis_value: float, basis_scale: int,
     *               performance_ratio: float, accuracy_multiplier: float, points: float}|null
     *               null when the mode/config is not one of the nine war modes.
     */
    public static function breakdown(string $mode, string $config, TypingResult $result): ?array
    {
        $ceiling = ClanWarModeCatalog::ceilingFor($mode, $config);

        if ($ceiling === null) {
            return null;
        }

        $accuracyMultiplier = 0.5 + 0.5 * (max(0, min(100, (float) $result->accuracy)) / 100);

        $isSurvival = $mode === 'survival';
        $basisValue = $isSurvival ? (float) $result->duration_seconds : (float) $result->net_wpm;
        $basisScale = $isSurvival
            ? ClanWarModeCatalog::SURVIVAL_SECONDS_SCALE
            : ClanWarModeCatalog::WPM_SCALE;

        $performanceRatio = min(1.0, $basisValue / $basisScale);

        return [
            'ceiling' => $ceiling,
            'basis' => $isSurvival ? 'duration' : 'wpm',
            'basis_value' => $basisValue,
            'basis_scale' => $basisScale,
            'performance_ratio' => round($performanceRatio, 4),
            'accuracy_multiplier' => round($accuracyMultiplier, 4),
            'points' => round($ceiling * $performanceRatio * $accuracyMultiplier, 2),
        ];
    }
}
