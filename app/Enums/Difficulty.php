<?php

namespace App\Enums;

/** Difficulty tier of a typing text (word length, punctuation, rarity). */
enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';
}
