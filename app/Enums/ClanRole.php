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

    /**
     * War powers: issue, cancel, accept and decline a clan war challenge.
     *
     * Today this is the same set as canManageMembers(), and it is deliberately a SEPARATE
     * method rather than a call to it. The two answer different questions -- "may they run the
     * roster" and "may they commit the clan to a war" -- and the day one of them moves, sharing
     * an implementation would move the other silently. A war stake is closer to roster work
     * than to ownership: the loss is Elo power, which is played back, not the clan itself.
     *
     * It sat with the single leader until 2026-08-06. What ended that was not a rule change but
     * an absence: an offline leader blocked the whole clan, since nobody else could answer a
     * challenge before its one-hour deadline burned it.
     */
    public function canManageWar(): bool
    {
        return $this !== self::Member;
    }
}
