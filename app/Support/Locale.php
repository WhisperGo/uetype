<?php

namespace App\Support;

/**
 * Central definition of the UI locales the app supports (interface language),
 * with the default and a validity check. Distinct from TypingLanguage, which is
 * about the language of the text being typed.
 */
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
