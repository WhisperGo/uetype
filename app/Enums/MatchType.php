<?php

namespace App\Enums;

/** Shape of a match: a 1v1 duel or a group race. */
enum MatchType: string
{
    case OneVsOne = '1v1';
    case Group = 'group';
}
