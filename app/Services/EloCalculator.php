<?php

namespace App\Services;

/**
 * Standard Elo formula for Clan War power rating. Zero-sum: the delta one clan
 * gains equals what the other loses. Asymmetric in the power gap: beating a
 * stronger clan gains more power.
 */
class EloCalculator
{
    /**
     * How far one war may move a clan's rating. Deliberately FLAT, not a decay schedule.
     *
     * Mature Elo implementations lower K as a competitor accumulates games (32 -> 16 -> 10), so
     * an established rating stops swinging on a single result. That is the right shape for a
     * populated ladder and the wrong one here: with few clans and few wars each, a high K is
     * what lets a rating find its level at all -- decaying it early would freeze clans near
     * their starting 1000 based on two or three matches, which says less than the flat version.
     *
     * Recorded so it reads as a decision rather than an omission. Revisit when wars per clan
     * are into double figures, and take the number from THIS install's rating spread rather
     * than from the chess convention the values come from.
     */
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
