<?php

namespace App\Enums;

/**
 * Source style of a typing text: a coherent quote/sentence, or a random
 * stream of individual words.
 */
enum TextMode: string
{
    case Quote = 'quote';
    case Words = 'words';
}
