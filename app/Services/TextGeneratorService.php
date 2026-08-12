<?php

namespace App\Services;

use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Assembles practice text from JSON wordlists. THE single source of truth for every mode.
 *
 * This logic used to exist twice: TypingEngine::generateText() (multi-language,
 * word count per mode) and MultiplayerLobby::generateRaceText() (hardcoded
 * indonesian.json, 45 words, song-lyric fallback). The duplication wasn't just
 * untidy -- it produced a feature bug: multiplayer did NOT support English at all,
 * even though solo mode did.
 */
class TextGeneratorService
{
    /** Word stock for time mode: long enough not to run out before the clock does. */
    public const TIME_WORD_COUNT = 350;

    /** Survival has no time/word limit -> its stock is the longest. */
    public const SURVIVAL_WORD_COUNT = 500;

    /** Text length of a single multiplayer race. */
    public const RACE_WORD_COUNT = 45;

    /**
     * Text for one solo session per mode: 'words' uses its sub-mode count,
     * 'survival' & 'time' use a fixed stock.
     */
    public function forSoloMode(string $mode, string $subMode, string $lang): string
    {
        $limit = match ($mode) {
            'words' => (int) $subMode,
            'survival' => self::SURVIVAL_WORD_COUNT,
            default => self::TIME_WORD_COUNT,
        };

        return $this->randomWords($limit, $lang);
    }

    /** Text for one multiplayer race. Now honors the content language too. */
    public function forRace(string $lang = TypingLanguage::DEFAULT): string
    {
        return $this->randomWords(self::RACE_WORD_COUNT, $lang);
    }

    /**
     * Assemble $limit random words from that language's wordlist, all lowercase.
     * A wordlist shorter than $limit is safe: words are drawn repeatedly until enough.
     */
    public function randomWords(int $limit, string $lang): string
    {
        $limit = max(1, $limit);
        $words = $this->wordlist($lang);

        if (empty($words)) {
            return '';
        }

        $selected = [];

        while (count($selected) < $limit) {
            shuffle($words);
            $selected = array_merge($selected, array_slice($words, 0, $limit - count($selected)));
        }

        return mb_strtolower(implode(' ', $selected));
    }

    /**
     * Wordlists already decoded during THIS request, keyed by language.
     *
     * @var array<string, array<int, string>>
     */
    private static array $memo = [];

    /**
     * A language's wordlist.
     *
     * The file is static (never changes at runtime), but it used to be read from
     * disk and JSON-decoded AGAIN every time the user switched mode, switched
     * language, or restarted -- even though the result is always the same.
     *
     * TWO layers, because they solve different halves and the docblock used to claim the
     * cheaper one while only having the dearer one:
     *
     *  - $memo is the actual in-process cache. Cache::rememberForever() goes to the configured
     *    store, and CACHE_STORE is `database` here -- so what read like a memory lookup was a
     *    query returning the whole list to unserialize, on every generateText().
     *  - the cache store still earns its place ACROSS requests, saving the disk read and
     *    json_decode for every request after the first.
     *
     * KNOWN CONSEQUENCE of rememberForever: editing database/data/*.json changes nothing until
     * `php artisan cache:clear`. That is the same class of trap as rebuilding assets after a
     * REVERB change (PROJECT_OVERVIEW.md §12) -- the edit looks applied and simply is not.
     *
     * @return array<int, string>
     */
    public function wordlist(string $lang): array
    {
        $lang = TypingLanguage::resolve($lang);

        if (isset(self::$memo[$lang])) {
            return self::$memo[$lang];
        }

        return self::$memo[$lang] = Cache::rememberForever("wordlist.{$lang}", function () use ($lang) {
            $path = TypingLanguage::wordlistPath($lang);

            if (! File::exists($path)) {
                return [];
            }

            $data = json_decode(File::get($path), true);

            if (! is_array($data) || ! isset($data['words']) || ! is_array($data['words'])) {
                return [];
            }

            return array_values(array_filter($data['words'], 'is_string'));
        });
    }

    /**
     * Drop the in-process memo.
     *
     * For tests: static state outlives a single test, so a case that swaps a wordlist or
     * asserts on cache behaviour would otherwise be answered by the previous test's copy.
     */
    public static function forgetMemo(): void
    {
        self::$memo = [];
    }
}
