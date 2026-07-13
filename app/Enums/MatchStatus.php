<?php

namespace App\Enums;

/** State of a match: waiting, in progress, or finished. */
enum MatchStatus: string
{
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Finished = 'finished';
}
