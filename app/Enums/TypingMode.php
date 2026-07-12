<?php

namespace App\Enums;

/**
 * Game mode a typing session is played in: fixed time, fixed word count, a
 * quote, endless survival, or racing against a recorded ghost.
 */
enum TypingMode: string
{
    case Time = 'time';
    case Words = 'words';
    case Quote = 'quote';
    case Survival = 'survival';
    case Ghost = 'ghost';
}
