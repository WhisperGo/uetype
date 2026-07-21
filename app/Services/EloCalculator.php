<?php

namespace App\Services;

/**
 * Standard Elo formula for Clan War power rating. Zero-sum: the delta one clan
 * gains equals what the other loses. Asymmetric in the power gap: beating a
 * stronger clan gains more power.
 */
class EloCalculator
{
    public const K_FACTOR = 32;

    /**
     * @param  float  $scoreA  1.0 win, 0.5 draw, 0.0 loss (from A's perspective)
     * @return array{0: int, 1: int} [deltaA, deltaB] -- always opposite signs
     */
    public static function calculate(int $powerA, int $powerB, float $scoreA): array
    {
        $expectedA = 1 / (1 + 10 ** (($powerB - $powerA) / 400));
        $deltaA = (int) round(self::K_FACTOR * ($scoreA - $expectedA));

        return [$deltaA, -$deltaA];
    }
}
