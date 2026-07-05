<?php

namespace App\Services;

/**
 * Formula Elo standar untuk power rating Clan War. Zero-sum: delta yang
 * didapat satu clan persis sama besarnya dengan yang hilang dari lawannya,
 * mencegah inflasi/deflasi power di seluruh sistem. Asimetris terhadap
 * selisih power: menang melawan clan yang lebih kuat menambah power lebih
 * banyak daripada menang melawan yang lebih lemah (begitu pula untuk seri).
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
