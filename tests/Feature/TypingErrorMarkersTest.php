<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\TypingErrorInspector;
use Livewire\Livewire;

/**
 * Stream error adalah tier PRESENTASI: client-supplied, session-only, tak pernah menyentuh
 * skor/XP/PB/leaderboard. Yang dikunci di sini adalah batas kepercayaannya -- payload rusak
 * tak boleh menjatuhkan hasil yang sah, dan tak satu pun byte-nya boleh mendarat di DB.
 */
it('stores a sanitised error stream in the session', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], ['e' => 1], 0, 0, '', 0, [
            ['second' => 1, 'index' => 2, 'actual' => 'r'],
        ]);

    expect(session('typing_result')['errorEvents'])->toBe([
        ['second' => 1, 'index' => 2, 'actual' => 'r'],
    ]);
});

it('defaults to an empty stream when the client sends nothing', function () {
    // Membuktikan parameter ke-11 tak merusak kelima call site 10-argumen yang sudah ada
    // (BackNavigationRedirect x2, GhostMode x2, LeaderboardLanguage), dan bahwa sesi lama
    // dari request sebelum deploy tetap merender bersih.
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], [], 0, 0, '', 0);

    expect(session('typing_result')['errorEvents'])->toBe([]);
});

it('shrugs off a malformed error stream without breaking the save', function () {
    // Batas kepercayaan tak boleh bisa menjatuhkan hasil yang sah: payload sampah
    // diabaikan, redirect tetap ke halaman hasil.
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], [], 0, 0, '', 0, 'not-an-array')
        ->assertRedirect(route('typing.result'));

    expect(session('typing_result')['errorEvents'])->toBe([]);
});

it('caps a flooded error stream', function () {
    $user = User::factory()->create();

    $flood = array_fill(0, 900, ['second' => 1, 'index' => 0, 'actual' => 'a']);

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], [], 0, 0, '', 0, $flood);

    expect(session('typing_result')['errorEvents'])
        ->toHaveCount(TypingErrorInspector::MAX_EVENTS);
});

// ---- Guard render ----

it('renders the error panel and addressable heatmap keys in time mode', function () {
    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 85.0, 'rawWpm' => 90, 'accuracy' => 96.5, 'time' => 30,
        'mode' => 'time', 'subMode' => '30', 'textToType' => 'the quick brown fox',
        'wpmHistory' => [70, 80, 85], 'rawHistory' => [75, 85, 90],
        'missedChars' => ['e' => 3], 'errorEvents' => [['second' => 1, 'index' => 5, 'actual' => 'r']],
    ]]);

    // data-key adalah kontrak yang di-andalkan ring: refactor ceroboh akan
    // menghilangkannya diam-diam, dan tak ada test lain yang menangkapnya.
    $this->get('/result')
        ->assertOk()
        ->assertSee(__('result.error_hint'), false)
        ->assertSee('data-key="e"', false);
});

it('keeps the error panel out of the survival branch', function () {
    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 28.0, 'rawWpm' => 30, 'accuracy' => 48.0, 'time' => 6,
        'mode' => 'survival', 'subMode' => 'hard', 'score' => 3,
        'wpmHistory' => [10, 20, 15], 'rawHistory' => [12, 22, 17],
        'missedChars' => ['a' => 2], 'errorEvents' => [['second' => 1, 'index' => 0, 'actual' => 'r']],
    ]]);

    $this->get('/result')
        ->assertOk()
        ->assertDontSee(__('result.error_hint'), false);
});

it('renders the result page when the session has no text to reconstruct words from', function () {
    // textToType TIDAK ADA di ketiga test halaman hasil yang lama -- degradasi ini
    // jalur default, bukan edge case. Titik tetap muncul, panel cuma kehilangan katanya.
    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 42.0, 'rawWpm' => 45.0, 'accuracy' => 95.0, 'time' => 15,
        'mode' => 'time', 'subMode' => '15',
        'wpmHistory' => [40, 42], 'rawHistory' => [45, 47],
        'missedChars' => ['e' => 1], 'errorEvents' => [['second' => 1, 'index' => 5, 'actual' => 'r']],
    ]]);

    $this->get('/result')->assertOk();
});

it('keeps the error stream out of the database', function () {
    // Mengunci tier presentasi: data ini efemeral seperti wpmHistory/missedChars.
    // ghost_data adalah satu-satunya kolom JSON yang menganggur -- pastikan tak dipakai.
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], [], 0, 0, '', 0, [
            ['second' => 1, 'index' => 2, 'actual' => 'r'],
        ]);

    expect(TypingResult::first()->ghost_data)->toBeNull();
});
