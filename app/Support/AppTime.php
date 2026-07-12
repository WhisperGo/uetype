<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Formats dates in the app's canonical timezone (Asia/Jakarta) so timestamps
 * read consistently regardless of server/user locale, with a "-" fallback for nulls.
 */
final class AppTime
{
    public const ZONE = 'Asia/Jakarta';

    public static function format(?CarbonInterface $date, string $format): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->copy()->timezone(self::ZONE)->translatedFormat($format);
    }
}
