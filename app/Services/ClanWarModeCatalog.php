<?php

namespace App\Services;

/**
 * Katalog 9 mode wajib Clan War beserta ceiling (poin maksimal) per mode.
 * SATU sumber kebenaran yang dipakai bersama oleh komponen ClanWar (grid &
 * klaim), ClanWarScorer (perhitungan poin), dan ClanWarResolver (penutupan
 * war) supaya angka ceiling & daftar mode tak terduplikasi di banyak tempat.
 *
 * Daftar mode SENGAJA cocok persis dengan TypingEngine::ALLOWED_SUBMODES
 * (time 15/30/60/120, words 10/25/50/100, survival hard) -- kombinasi di
 * luar ini tak akan pernah bisa diklaim sebagai war attempt.
 */
class ClanWarModeCatalog
{
    /**
     * Urutan di sini = urutan tampil di grid. Ceiling naik seiring
     * kesulitan/durasi mode; Survival Hard tertinggi karena risiko
     * kegagalan total (0 poin kalau cepat mati) paling besar.
     *
     * @var list<array{mode: string, config: string, ceiling: int}>
     */
    public const MODES = [
        ['mode' => 'words', 'config' => '10', 'ceiling' => 50],
        ['mode' => 'words', 'config' => '25', 'ceiling' => 70],
        ['mode' => 'words', 'config' => '50', 'ceiling' => 90],
        ['mode' => 'words', 'config' => '100', 'ceiling' => 110],
        ['mode' => 'time', 'config' => '15', 'ceiling' => 60],
        ['mode' => 'time', 'config' => '30', 'ceiling' => 80],
        ['mode' => 'time', 'config' => '60', 'ceiling' => 100],
        ['mode' => 'time', 'config' => '120', 'ceiling' => 120],
        ['mode' => 'survival', 'config' => 'hard', 'ceiling' => 150],
    ];

    /**
     * Time/Words: WPM di mana poin mode mencapai ceiling penuh (di atas ini
     * di-cap). 150 = tier "Supersonic" pada sistem achievement -- bisa
     * disesuaikan kalau ternyata terlalu mudah/sulit dicapai.
     */
    public const WPM_SCALE = 150;

    /**
     * Survival: durasi bertahan (detik) di mana poin mencapai ceiling penuh.
     * 90 = estimasi awal utk Survival Hard (drain rate agresif) -- bisa
     * disesuaikan setelah dipakai.
     */
    public const SURVIVAL_SECONDS_SCALE = 90;

    public static function ceilingFor(string $mode, string $config): ?int
    {
        foreach (self::MODES as $m) {
            if ($m['mode'] === $mode && $m['config'] === $config) {
                return $m['ceiling'];
            }
        }

        return null;
    }

    public static function isValidMode(string $mode, string $config): bool
    {
        return self::ceilingFor($mode, $config) !== null;
    }
}
