<?php

namespace App\Enums;

/** State of a friendship: pending, accepted, rejected, or blocked. */
enum FriendshipStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Blocked = 'blocked';
}
