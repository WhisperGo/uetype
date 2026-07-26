<?php

namespace App\Enums;

/**
 * A user's role in a clan, ordered by authority: leader > co-leader > member.
 *
 * Co-leaders share the roster powers but never ownership -- only the leader may transfer,
 * promote/demote or disband. That split is what makes the role safe to hand out: a
 * co-leader can help run the clan without being able to destroy it.
 */
enum ClanRole: string
{
    case Leader = 'leader';
    case CoLeader = 'co-leader';
    case Member = 'member';

    /**
     * Sort weight for rosters, leader first. Needed because the role column is a string:
     * sorting it in SQL alphabetises 'co-leader' above 'leader'.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Leader => 0,
            self::CoLeader => 1,
            self::Member => 2,
        };
    }

    /** Roster powers: approve, reject and kick. Held by the leader and co-leaders. */
    public function canManageMembers(): bool
    {
        return $this !== self::Member;
    }

    /** Ownership powers: promote, demote, transfer and disband. Leader only. */
    public function canManageClan(): bool
    {
        return $this === self::Leader;
    }
}
