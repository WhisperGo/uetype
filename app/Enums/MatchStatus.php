<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Finished = 'finished';
}
