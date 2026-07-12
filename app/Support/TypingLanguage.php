<?php

namespace App\Support;

/**
 * The languages available for typing practice and the word-list file backing
 * each one. Distinct from Locale, which governs the UI language.
 */
final class TypingLanguage
{
    public const DEFAULT = 'en';

    public const SUPPORTED = ['en', 'id'];

    private const WORDLIST_FILES = [
        'en' => 'english.json',
        'id' => 'indonesian.json',
    ];

    public static function isSupported(?string $lang): bool
    {
        return in_array($lang, self::SUPPORTED, true);
    }

    public static function resolve(?string $lang): string
    {
        return self::isSupported($lang) ? $lang : self::DEFAULT;
    }

    public static function wordlistPath(string $lang): string
    {
        $file = self::WORDLIST_FILES[self::resolve($lang)];

        return base_path('database/data/'.$file);
    }
}
