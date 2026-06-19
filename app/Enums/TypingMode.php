<?php

namespace App\Enums;

enum TypingMode: string
{
    case Time = 'time';
    case Words = 'words';
    case Quote = 'quote';
    case Survival = 'survival';
    case Ghost = 'ghost';
}
