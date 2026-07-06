<?php

use App\Livewire\TypingEngine;
use App\Models\Language;
use App\Models\Text;
use App\Models\User;
use Livewire\Livewire;

function wordlistWords(string $file): array
{
    $data = json_decode(file_get_contents(base_path('database/data/'.$file)), true);

    return $data['words'];
}

test('switching content language to id draws words from the indonesian wordlist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(TypingEngine::class)->call('setContentLang', 'id');

    $words = explode(' ', $component->get('textToType'));

    expect($component->get('contentLang'))->toBe('id');
    expect(wordlistWords('indonesian.json'))->toContain($words[0]);
});

test('switching content language to en draws words from the english wordlist', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(TypingEngine::class)
        ->call('setContentLang', 'id')
        ->call('setContentLang', 'en');

    $words = explode(' ', $component->get('textToType'));

    expect($component->get('contentLang'))->toBe('en');
    expect(wordlistWords('english.json'))->toContain($words[0]);
});

test('an unsupported content language falls back to english', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $component = Livewire::test(TypingEngine::class)->call('setContentLang', 'zz');

    expect($component->get('contentLang'))->toBe('en');
});

test('the content language persists in the session preferences', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(TypingEngine::class)->call('setContentLang', 'id');

    expect(session('typing_preferences')['contentLang'])->toBe('id');
});

test('quote mode only draws quotes for the selected content language', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $en = Language::create(['code' => 'en', 'name' => 'English']);
    $id = Language::create(['code' => 'id', 'name' => 'Indonesian']);

    Text::create(['language_id' => $en->id, 'content' => 'An english quote here.', 'mode' => 'quote', 'difficulty' => 'medium']);
    $idText = Text::create(['language_id' => $id->id, 'content' => 'Sebuah kutipan indonesia.', 'mode' => 'quote', 'difficulty' => 'medium']);

    $component = Livewire::test(TypingEngine::class)
        ->call('setContentLang', 'id')
        ->call('setMode', 'quote', null);

    expect($component->get('textId'))->toBe($idText->id);
    expect($component->get('textToType'))->toBe('Sebuah kutipan indonesia.');
});
