<?php

namespace App\Enums;

/**
 * Shape of a match: a head-to-head duel (1v1) or a multi-player group race.
 */
enum MatchType: string
{
    case OneVsOne = '1v1';
    case Group = 'group';
}
