<?php

namespace App\Enums;

/**
 * Membership state of a user in a clan: a pending join request, or an active
 * (approved) member.
 */
enum ClanMemberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
}
