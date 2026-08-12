<?php

use App\Livewire\TypingEngine;
use App\Livewire\TypingResult;
use App\Models\User;
use Livewire\Livewire;

/**
 * "Retry" di result page harus mengulang teks Words yang SAMA PERSIS (beda dari
 * "Next Test" yang selalu acak). Mekanismenya: TypingResult::retry() menitipkan teks
 * ke session 'typing_retry' (sekali pakai), lalu TypingEngine::mount() memakainya
 * menggantikan perakitan acak.
 */
it('stores the same words text into session and redirects when retrying', function () {
    $user = User::factory()->create();

    session(['typing_result' => [
        'wpm' => 80, 'rawWpm' => 85, 'accuracy' => 96, 'time' => 15,
        'mode' => 'words', 'subMode' => '25',
        'textToType' => 'the quick brown fox jumps over',
    ]]);

    Livewire::actingAs($user)->test(TypingResult::class)
        ->call('retry')
        ->assertRedirect(route('typing'));

    expect(session('typing_retry'))->toMatchArray([
        'text' => 'the quick brown fox jumps over',
        'mode' => 'words',
        'subMode' => '25',
    ]);
});

it('reuses the retry text on the next typing session instead of generating a random one', function () {
    $user = User::factory()->create();

    $sameText = 'alpha beta gamma delta epsilon zeta';
    session(['typing_retry' => ['text' => $sameText, 'mode' => 'words', 'subMode' => '25']]);

    $component = Livewire::actingAs($user)->test(TypingEngine::class);

    expect($component->get('textToType'))->toBe($sameText);
    expect($component->get('mainMode'))->toBe('words');
    expect($component->get('subMode'))->toBe('25');

    // Sekali pakai: session sudah di-pull, tak lengket.
    expect(session()->has('typing_retry'))->toBeFalse();
});

it('does not apply retry for non-words modes', function () {
    $user = User::factory()->create();

    session(['typing_retry' => ['text' => 'should be ignored', 'mode' => 'time', 'subMode' => '30']]);

    $component = Livewire::actingAs($user)->test(TypingEngine::class);

    expect($component->get('textToType'))->not->toBe('should be ignored');
});

it('generates a fresh random text on a normal typing load (retry not sticky)', function () {
    $user = User::factory()->create();

    // Tanpa typing_retry di session: load normal harus menghasilkan teks (acak) apa pun,
    // bukan null/kosong.
    $component = Livewire::actingAs($user)->test(TypingEngine::class);

    expect($component->get('textToType'))->not->toBeEmpty();
});
