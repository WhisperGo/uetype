<?php

namespace App\Enums;

/**
 * Source style of a typing text. Currently only a random stream of individual
 * words is used.
 */
enum TextMode: string
{
    case Words = 'words';
}
