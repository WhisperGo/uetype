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

    /**
     * Floor under the per-member claim cap.
     *
     * A war is meant to be a clan effort: without a cap one account can claim every slot and
     * decide the outcome alone, which also means a single cheating or compromised member is
     * enough to win (pentest finding F-03). Four keeps that true for any clan big enough for
     * it to mean something.
     */
    public const MIN_CLAIMS_PER_MEMBER = 4;

    /**
     * How many of the 9 slots one member may claim, given the clan's active roster.
     *
     * The cap used to be a flat 4, which quietly made 3 members a REQUIREMENT nobody enforced
     * and nothing told you about: a 2-member clan could claim 8 of 9 slots and then sat in
     * front of a grid it could never finish. Because early finish demands 9/9 from both sides,
     * it stranded the opposing clan for the full 3 days as well -- a penalty paid by the side
     * that did nothing wrong.
     *
     * Raising the cap only for clans that need it keeps F-03 closed where it actually matters.
     * "One account decides the war" is a real risk in a clan of five; in a clan of two it is
     * simply what a clan of two IS, and refusing to let them play is not protection.
     *
     * Callers must read the war's SNAPSHOT (ClanWar::maxClaimsFor), not call this live for an
     * ongoing war -- otherwise kicking members mid-war would raise your own cap.
     */
    public static function claimCapFor(int $activeMembers): int
    {
        return max(self::MIN_CLAIMS_PER_MEMBER, (int) ceil(count(self::MODES) / max(1, $activeMembers)));
    }

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
