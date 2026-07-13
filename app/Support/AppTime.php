<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Formats dates in the app's canonical timezone (Asia/Jakarta), "-" for null. */
final class AppTime
{
    public const ZONE = 'Asia/Jakarta';

    /** Format a date in the app timezone, or "-" when null. */
    public static function format(?CarbonInterface $date, string $format): string
    {
        if ($date === null) {
            return '-';
        }

        return $date->copy()->timezone(self::ZONE)->translatedFormat($format);
    }
}
