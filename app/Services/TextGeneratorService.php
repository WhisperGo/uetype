<?php

namespace App\Services;

use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Perakit teks latihan dari wordlist JSON. SATU sumber kebenaran untuk seluruh mode.
 *
 * Dulu logika ini ada dua kali: TypingEngine::generateText() (multi-bahasa, jumlah
 * kata per mode) dan MultiplayerLobby::generateRaceText() (hardcode indonesian.json,
 * 45 kata, fallback potongan lirik lagu). Duplikasi itu bukan cuma soal kerapian --
 * ia melahirkan bug fitur: multiplayer TIDAK mendukung bahasa Inggris sama sekali,
 * padahal mode solo mendukung.
 */
class TextGeneratorService
{
    /** Stok kata untuk mode time: cukup panjang supaya tak habis sebelum waktu usai. */
    public const TIME_WORD_COUNT = 350;

    /** Survival tak punya batas waktu/kata -> stoknya paling panjang. */
    public const SURVIVAL_WORD_COUNT = 500;

    /** Panjang teks satu balapan multiplayer. */
    public const RACE_WORD_COUNT = 45;

    /**
     * Teks untuk satu sesi solo sesuai mode: 'words' sebanyak sub-mode-nya,
     * 'survival' & 'time' memakai stok tetap.
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

    /** Teks untuk satu balapan multiplayer. Kini ikut menghormati bahasa konten. */
    public function forRace(string $lang = TypingLanguage::DEFAULT): string
    {
        return $this->randomWords(self::RACE_WORD_COUNT, $lang);
    }

    /**
     * Rangkai $limit kata acak dari wordlist bahasa tersebut, huruf kecil semua.
     * Wordlist lebih pendek dari $limit tetap aman: kata diambil berulang sampai cukup.
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
     * Wordlist sebuah bahasa, di-cache di memori proses.
     *
     * File-nya statis (tak pernah berubah saat runtime), tapi dulu dibaca dari disk
     * dan di-decode JSON ULANG setiap kali user ganti mode, ganti bahasa, atau
     * restart -- padahal hasilnya selalu sama.
     *
     * @return array<int, string>
     */
    public function wordlist(string $lang): array
    {
        $lang = TypingLanguage::resolve($lang);

        return Cache::rememberForever("wordlist.{$lang}", function () use ($lang) {
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
}
