<?php

namespace App\Enums;

/**
 * Lifecycle state of a typing match: waiting for players, actively racing,
 * or completed.
 */
enum MatchStatus: string
{
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Finished = 'finished';
}
