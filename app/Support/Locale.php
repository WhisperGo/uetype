<?php

namespace App\Support;

/** Supported UI locales (interface language); distinct from TypingLanguage. */
final class Locale
{
    public const DEFAULT = 'en';

    public const SUPPORTED = ['en', 'id'];

    /** Whether a locale code is supported. */
    public static function isSupported(?string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }

    /** The locale if supported, else the default. */
    public static function resolve(?string $locale): string
    {
        return self::isSupported($locale) ? $locale : self::DEFAULT;
    }

    /**
     * Human-readable label for each supported locale.
     *
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
