<?php

namespace App\Support;

/** Languages available for typing practice; distinct from the UI Locale. */
final class TypingLanguage
{
    public const DEFAULT = 'en';

    public const SUPPORTED = ['en', 'id'];

    private const WORDLIST_FILES = [
        'en' => 'english.json',
        'id' => 'indonesian.json',
    ];

    /** Whether a typing-language code is supported. */
    public static function isSupported(?string $lang): bool
    {
        return in_array($lang, self::SUPPORTED, true);
    }

    /** The language if supported, else the default. */
    public static function resolve(?string $lang): string
    {
        return self::isSupported($lang) ? $lang : self::DEFAULT;
    }

    /** Absolute path to the wordlist JSON for a language. */
    public static function wordlistPath(string $lang): string
    {
        $file = self::WORDLIST_FILES[self::resolve($lang)];

        return base_path('database/data/'.$file);
    }
}
