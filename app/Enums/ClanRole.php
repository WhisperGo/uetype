<?php

namespace App\Enums;

/** A user's role in a clan: leader or member. */
enum ClanRole: string
{
    case Leader = 'leader';
    case Member = 'member';
}
