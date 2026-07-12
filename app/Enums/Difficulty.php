<?php

namespace App\Enums;

/**
 * Difficulty tier for typing texts, controlling how challenging the source
 * material is (word length, punctuation, rarity).
 */
enum Difficulty: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';
}
