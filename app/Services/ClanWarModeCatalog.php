<?php

namespace App\Services;

/**
 * Katalog 9 mode wajib Clan War beserta ceiling (poin maksimal) per mode. Satu
 * sumber kebenaran dipakai bersama oleh komponen ClanWar, ClanWarScorer, dan
 * ClanWarResolver. Daftar mode cocok persis dengan TypingEngine::ALLOWED_SUBMODES;
 * kombinasi di luar ini tak bisa diklaim sebagai war attempt.
 */
class ClanWarModeCatalog
{
    /**
     * Urutan = urutan tampil di grid. Ceiling naik seiring kesulitan/durasi mode;
     * Survival Hard tertinggi karena risiko kegagalan total paling besar.
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

    /** Time/Words: WPM di mana poin mode mencapai ceiling penuh (di-cap di atas ini). */
    public const WPM_SCALE = 150;

    /** Survival: durasi bertahan (detik) di mana poin mencapai ceiling penuh. */
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
