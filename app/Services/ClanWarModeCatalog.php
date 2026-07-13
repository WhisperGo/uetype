<?php

namespace App\Services;

/** The 9 required Clan War modes and their point ceilings; the single source of truth. */
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

    /** Time/Words: WPM at which points hit the full ceiling (capped above this). */
    public const WPM_SCALE = 150;

    /** Survival: survived duration (seconds) at which points hit the full ceiling. */
    public const SURVIVAL_SECONDS_SCALE = 90;

    /** The point ceiling for a mode/config, or null if not a valid war mode. */
    public static function ceilingFor(string $mode, string $config): ?int
    {
        foreach (self::MODES as $m) {
            if ($m['mode'] === $mode && $m['config'] === $config) {
                return $m['ceiling'];
            }
        }

        return null;
    }

    /** Whether a mode/config is a claimable war mode. */
    public static function isValidMode(string $mode, string $config): bool
    {
        return self::ceilingFor($mode, $config) !== null;
    }
}
