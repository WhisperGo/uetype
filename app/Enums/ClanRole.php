<?php

namespace App\Enums;

/**
 * A user's role within a clan: the leader (full control) or an ordinary member.
 */
enum ClanRole: string
{
    case Leader = 'leader';
    case Member = 'member';
}
