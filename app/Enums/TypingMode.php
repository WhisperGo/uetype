<?php

namespace App\Enums;

/** Mode a typing session runs in: time, words, survival, or ghost. */
enum TypingMode: string
{
    case Time = 'time';
    case Words = 'words';
    case Survival = 'survival';
    case Ghost = 'ghost';
}
