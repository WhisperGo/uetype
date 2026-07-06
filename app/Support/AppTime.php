<?php

namespace App\Support;

use Carbon\CarbonInterface;

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
