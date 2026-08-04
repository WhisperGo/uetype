<?php

use App\Services\TextGeneratorService;
use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->generator = new TextGeneratorService;

    // The memo is static, so it outlives a single test. Cleared here so no case is answered
    // by a list another one left behind.
    TextGeneratorService::forgetMemo();
});

afterEach(function () {
    TextGeneratorService::forgetMemo();
});

/**
 * The docblock used to promise a cache "in process memory" while calling
 * Cache::rememberForever(), which goes to the configured store -- `database` in this project.
 * So every generateText() ran a query that returned the whole wordlist to unserialize, and the
 * comment said the opposite.
 *
 * Proven by making the STORE disagree with the memo: once a language has been read, a value
 * planted in the store must not be able to reach the caller, and must reach it again the moment
 * the memo is dropped. Anything weaker (calling twice and comparing) passes with no memo at
 * all, since re-reading the same file returns the same words.
 */
it('answers a repeat wordlist read from process memory, not the cache store', function () {
    $first = $this->generator->wordlist('en');

    expect($first)->not->toBeEmpty();

    Cache::put('wordlist.en', ['sentinel']);

    expect($this->generator->wordlist('en'))->toBe($first);

    TextGeneratorService::forgetMemo();

    expect($this->generator->wordlist('en'))->toBe(['sentinel']);
});

it('merakit tepat sejumlah kata yang diminta', function () {
    $text = $this->generator->randomWords(25, 'en');

    expect(explode(' ', $text))->toHaveCount(25);
});

it('tetap memenuhi jumlah walau melebihi panjang wordlist', function () {
    // survival minta 500 kata; wordlist boleh lebih pendek -> kata diambil berulang.
    $text = $this->generator->randomWords(500, 'en');

    expect(explode(' ', $text))->toHaveCount(500);
});

it('mengembalikan huruf kecil semua', function () {
    $text = $this->generator->randomWords(30, 'en');

    expect($text)->toBe(mb_strtolower($text));
});

it('mode words memakai sub-mode sebagai jumlah kata', function () {
    expect(explode(' ', $this->generator->forSoloMode('words', '10', 'en')))->toHaveCount(10)
        ->and(explode(' ', $this->generator->forSoloMode('words', '50', 'en')))->toHaveCount(50);
});

it('mode time memakai stok tetap, bukan sub-mode', function () {
    // subMode 'time' adalah DETIK, bukan jumlah kata -- 30 detik != 30 kata.
    $text = $this->generator->forSoloMode('time', '30', 'en');

    expect(explode(' ', $text))->toHaveCount(TextGeneratorService::TIME_WORD_COUNT);
});

it('mode survival memakai stok terpanjang', function () {
    $text = $this->generator->forSoloMode('survival', 'hard', 'en');

    expect(explode(' ', $text))->toHaveCount(TextGeneratorService::SURVIVAL_WORD_COUNT);
});

it('teks balapan memakai panjang balapan', function () {
    expect(explode(' ', $this->generator->forRace('en')))
        ->toHaveCount(TextGeneratorService::RACE_WORD_COUNT);
});

/**
 * R1: dulu multiplayer merakit teksnya sendiri dengan HARDCODE indonesian.json,
 * jadi balapan tak pernah bisa berbahasa Inggris walau solo bisa. Setelah kedua
 * jalur memakai service yang sama, bahasa benar-benar berpengaruh.
 */
it('menghasilkan teks berbeda untuk bahasa berbeda', function () {
    $wordsEn = $this->generator->wordlist('en');
    $wordsId = $this->generator->wordlist('id');

    expect($wordsEn)->not->toBeEmpty()
        ->and($wordsId)->not->toBeEmpty()
        ->and($wordsEn)->not->toBe($wordsId);
});

it('teks balapan menghormati bahasa yang diminta', function () {
    $race = $this->generator->forRace('id');
    $kamusId = $this->generator->wordlist('id');

    foreach (explode(' ', $race) as $word) {
        expect(mb_strtolower($word))->toBeIn(array_map('mb_strtolower', $kamusId));
    }
});

it('bahasa tak dikenal jatuh ke default, bukan error', function () {
    $text = $this->generator->randomWords(5, 'klingon');

    expect($text)->not->toBeEmpty()
        ->and(explode(' ', $text))->toHaveCount(5);

    $default = $this->generator->wordlist(TypingLanguage::DEFAULT);

    foreach (explode(' ', $text) as $word) {
        expect($word)->toBeIn(array_map('mb_strtolower', $default));
    }
});
