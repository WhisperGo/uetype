<?php

namespace App\Enums;

/**
 * Lifecycle state of a clan war challenge: from a pending/declined invite,
 * through an ongoing battle, to finished — or expired if never accepted in time.
 */
enum ClanWarStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Ongoing = 'ongoing';
    case Finished = 'finished';
    case Declined = 'declined';
}
