<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;
use Livewire\Volt\Volt;

/**
 * Bahasa teks yang diketik (en/id) adalah dimensi leaderboard yang berdiri sendiri,
 * terpisah dari mode/config/timeframe. Test ini mengunci: bahasa TERSIMPAN saat hasil
 * dicatat, dan papan + rank memfilternya (satu sumber kebenaran lewat $scoped()).
 */

/** Helper: satu baris hasil ketik dengan bahasa eksplisit. */
function langResult(User $user, string $lang, string $mode = 'time', string $config = '30', float $wpm = 90): TypingResult
{
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'language' => $lang,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm + 5,
        'accuracy' => 96,
        'correct_chars' => 300,
        'incorrect_chars' => 8,
        'duration_seconds' => 30,
    ]);
}

test('saveResult menyimpan bahasa konten yang aktif ke typing_results', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'words', '25')
        ->call('setContentLang', 'id')
        ->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 150, 'correctKeystrokes' => 140, 'wpmHistory' => [40, 42], 'rawHistory' => [45, 47]]);

    expect(TypingResult::first()->language)->toBe('id');
});

test('leaderboard hanya menampilkan pemain dari bahasa yang dipilih', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    $english = User::factory()->create();
    $indo = User::factory()->create();

    langResult($english, 'en', 'time', '30', 100);
    langResult($indo, 'id', 'time', '30', 110);

    // Default 'en': hanya pemain english.
    $enRows = Volt::test('leaderboard')->get('leaderboard');
    expect($enRows)->toHaveCount(1)
        ->and((int) $enRows->first()->user_id)->toBe($english->id);

    // Pindah ke 'id': hanya pemain indo.
    $idRows = Volt::test('leaderboard')->call('setLanguage', 'id')->get('leaderboard');
    expect($idRows)->toHaveCount(1)
        ->and((int) $idRows->first()->user_id)->toBe($indo->id);
});

test('userRank dihitung per bahasa, bukan lintas bahasa', function () {
    $me = User::factory()->create();

    // Rekor SAYA: kuat di en (tanpa pesaing), lemah di id (dua pesaing lebih cepat).
    langResult($me, 'en', 'time', '30', 130);
    langResult($me, 'id', 'time', '30', 70);

    $rivalA = User::factory()->create();
    $rivalB = User::factory()->create();
    langResult($rivalA, 'id', 'time', '30', 120);
    langResult($rivalB, 'id', 'time', '30', 90);

    $this->actingAs($me);

    // Di en: tak ada pesaing -> rank 1.
    expect(Volt::test('leaderboard')->get('userRank'))->toBe(1);

    // Di id: dua pesaing lebih cepat -> rank 3.
    expect(Volt::test('leaderboard')->call('setLanguage', 'id')->get('userRank'))->toBe(3);
});

test('setLanguage menormalkan kode tak dikenal ke default en', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    $component = Volt::test('leaderboard')->call('setLanguage', 'xx');

    expect($component->get('currentLang'))->toBe('en');
});
