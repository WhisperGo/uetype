<?php

namespace App\Enums;

/** State of a clan war: pending, declined, ongoing, finished, or expired. */
enum ClanWarStatus: string
{
    case Pending = 'pending';
    case Expired = 'expired';
    case Ongoing = 'ongoing';
    case Finished = 'finished';
    case Declined = 'declined';
}
