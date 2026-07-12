<?php

namespace App\Enums;

/**
 * Lifecycle state of a friendship request between two users: from a pending
 * invite through accepted/rejected, or a hard block.
 */
enum FriendshipStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Blocked = 'blocked';
}
