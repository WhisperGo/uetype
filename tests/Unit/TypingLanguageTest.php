<?php

use App\Support\TypingLanguage;
use Tests\TestCase;

// wordlistPath() uses base_path(), so this file needs a booted Laravel app (a plain Unit
// test here wouldn't boot the framework). Bind to TestCase WITHOUT RefreshDatabase -- every
// function here is pure and doesn't touch the DB.
uses(TestCase::class);

/*
|--------------------------------------------------------------------------
| Bahasa ketik (en/id) berbeda dari Locale UI. resolve() adalah gerbang
| fail-safe: input asing/null selalu jatuh ke DEFAULT, tak pernah melempar.
|--------------------------------------------------------------------------
*/

it('mengenali hanya bahasa yang didukung', function () {
    expect(TypingLanguage::isSupported('en'))->toBeTrue()
        ->and(TypingLanguage::isSupported('id'))->toBeTrue()
        ->and(TypingLanguage::isSupported('fr'))->toBeFalse()
        ->and(TypingLanguage::isSupported(null))->toBeFalse();
});

it('mempertahankan bahasa yang didukung apa adanya', function () {
    expect(TypingLanguage::resolve('en'))->toBe('en')
        ->and(TypingLanguage::resolve('id'))->toBe('id');
});

it('jatuh ke default untuk input asing atau null', function () {
    expect(TypingLanguage::resolve('jp'))->toBe(TypingLanguage::DEFAULT)
        ->and(TypingLanguage::resolve(null))->toBe(TypingLanguage::DEFAULT)
        ->and(TypingLanguage::resolve(''))->toBe(TypingLanguage::DEFAULT);
});

it('memetakan tiap bahasa didukung ke file wordlist yang benar', function () {
    expect(TypingLanguage::wordlistPath('en'))->toEndWith('english.json')
        ->and(TypingLanguage::wordlistPath('id'))->toEndWith('indonesian.json');
});

it('memakai wordlist default untuk bahasa asing (tak pernah path kosong)', function () {
    expect(TypingLanguage::wordlistPath('xx'))->toEndWith('english.json');
});

it('mengarahkan path wordlist ke database/data', function () {
    expect(TypingLanguage::wordlistPath('en'))->toContain('database');
});
