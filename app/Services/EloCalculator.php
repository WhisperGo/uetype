<?php

namespace App\Services;

/**
 * Formula Elo standar untuk power rating Clan War. Zero-sum: delta yang didapat
 * satu clan sama besar dengan yang hilang dari lawannya. Asimetris terhadap
 * selisih power: menang melawan clan lebih kuat menambah power lebih banyak.
 */
class EloCalculator
{
    public const K_FACTOR = 32;

    /**
     * @param  float  $scoreA  1.0 menang, 0.5 seri, 0.0 kalah (sudut pandang A)
     * @return array{0: int, 1: int} [deltaA, deltaB] -- selalu berlawanan tanda
     */
    public static function calculate(int $powerA, int $powerB, float $scoreA): array
    {
        $expectedA = 1 / (1 + 10 ** (($powerB - $powerA) / 400));
        $deltaA = (int) round(self::K_FACTOR * ($scoreA - $expectedA));

        return [$deltaA, -$deltaA];
    }
}
