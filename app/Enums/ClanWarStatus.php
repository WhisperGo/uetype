<?php

namespace App\Enums;

/** State of a clan war: pending, declined, cancelled, ongoing, finished, or expired. */
enum ClanWarStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Ongoing = 'ongoing';
    case Finished = 'finished';
    case Declined = 'declined';

    /**
     * The challenger withdrew before the other clan answered.
     *
     * A terminal status rather than a deleted row, matching Declined and Expired: those are the
     * project's existing idiom for "a challenge that ended without being played", and every
     * query that matters already works off a whitelist -- Clan::activeWar() admits only
     * Pending/Ongoing, so this frees both clans, and finishedWars() admits only Finished, so it
     * never reaches the history list.
     */
    case Cancelled = 'cancelled';
}
