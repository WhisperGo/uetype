<?php

namespace App\Enums;

/** Clan membership state: a pending join request or an active member. */
enum ClanMemberStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
}
