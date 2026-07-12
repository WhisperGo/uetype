<?php

namespace App\Services;

/**
 * Catalog of the 9 required Clan War modes and each mode's ceiling (max points).
 * The single source of truth shared by the ClanWar component, ClanWarScorer, and
 * ClanWarResolver. The mode list matches TypingEngine::ALLOWED_SUBMODES exactly;
 * any combination outside it cannot be claimed as a war attempt.
 */
class ClanWarModeCatalog
{
    /**
     * Order = display order in the grid. Ceiling rises with mode difficulty/duration;
     * Survival Hard is highest because it carries the greatest risk of total failure.
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
