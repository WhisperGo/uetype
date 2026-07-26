<?php

namespace App\Enums;

/**
 * A user's role in a clan, ordered by authority: leader > co-leader > member.
 *
 * Co-leaders share the leader's day-to-day roster powers (approve, reject, kick) but
 * never the clan's existence or ownership: only the single leader may transfer
 * leadership, promote/demote, or disband. That split is what makes it safe to hand the
 * role out -- a co-leader can help run the clan without being able to destroy it.
 */
enum ClanRole: string
{
    case Leader = 'leader';
    case CoLeader = 'co-leader';
    case Member = 'member';

    /**
     * Sort weight for rosters (leader first). The roster used to `orderBy('role')`, which
     * sorted the STRING -- with a third role that alphabetises to co-leader, leader,
     * member, putting the leader second.
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
