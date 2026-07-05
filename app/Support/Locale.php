<?php

namespace App\Support;

final class Locale
{
    public const DEFAULT = 'en';

    public const SUPPORTED = ['en', 'id'];

    public static function isSupported(?string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    public static function resolve(?string $locale): string
    {
        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            'en' => 'English',
            'id' => 'Bahasa Indonesia',
        ];
    }
}
