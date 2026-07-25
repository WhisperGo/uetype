<?php

use App\Livewire\GhostPicker;
use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('renders the typing page with ghost picker mounted', function () {
    $user = User::factory()->create();
    actingAs($user)->get('/typing')->assertOk()->assertSeeLivewire(GhostPicker::class);
});

/** Satu baris rekor untuk mode+config tertentu. */
function pickerResult(User $user, string $mode, string $config, float $wpm): void
{
    TypingResult::create([
        'user_id' => $user->id, 'mode' => $mode, 'mode_config' => $config,
        'net_wpm' => $wpm, 'raw_wpm' => $wpm + 5, 'accuracy' => 96,
        'correct_chars' => 300, 'incorrect_chars' => 10, 'duration_seconds' => 30,
    ]);
}

it('hides My Best when there is no record for this mode', function () {
    // Punya rekor karier, tapi belum pernah main config ini -> tak ada pace yang jujur.
    $user = User::factory()->create(['highest_wpm' => 90]);
    Livewire::actingAs($user)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->assertSee('Set a record in this mode first');
});

it('exposes My Best from the record for this mode', function () {
    $user = User::factory()->create(['highest_wpm' => 120]);
    pickerResult($user, 'time', '30', 85.5);
    pickerResult($user, 'time', '15', 120); // sprint: tak boleh muncul untuk time/30

    Livewire::actingAs($user)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->assertSee('Your Best')
        ->assertSee('85.5')
        ->assertDontSee('120');
});

it('selectOpponent own dispatches ghost-selected with server-derived wpm', function () {
    $user = User::factory()->create();
    pickerResult($user, 'time', '30', 77.3);

    Livewire::actingAs($user)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('selectOpponent', 'own')
        ->assertDispatched('ghost-selected', wpm: 77.3, label: 'Your Best');
});

it('selectOpponent leaderboard re-derives wpm from DB, ignoring any client-implied number', function () {
    $ghostSource = User::factory()->create(['username' => 'speedy']);
    $viewer = User::factory()->create();

    TypingResult::create([
        'user_id' => $ghostSource->id, 'mode' => 'time', 'mode_config' => '30',
        'net_wpm' => 120.0, 'raw_wpm' => 125, 'accuracy' => 98, 'correct_chars' => 500,
        'incorrect_chars' => 10, 'duration_seconds' => 30, 'xp_earned' => 10,
    ]);

    Livewire::actingAs($viewer)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('selectOpponent', 'leaderboard', $ghostSource->id)
        ->assertDispatched('ghost-selected', function ($name, $params) {
            return (float) $params['wpm'] === 120.0 && $params['label'] === 'speedy';
        });
});

it('selectOpponent friend requires an accepted friendship', function () {
    $me = User::factory()->create();
    $stranger = User::factory()->create(['highest_wpm' => 999]);

    // No friendship exists -> refId doesn't correspond to any accepted Friendship of $me
    Livewire::actingAs($me)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('selectOpponent', 'friend', 99999)
        ->assertNotDispatched('ghost-selected');
});

it('does not offer ghost mode for survival', function () {
    $user = User::factory()->create(['highest_wpm' => 100]);
    Livewire::actingAs($user)->test(GhostPicker::class, ['mainMode' => 'survival', 'subMode' => 'medium'])
        ->assertSee('only available for Time and Words');
});

/**
 * Ghost hanya sah di time/words. Klien tak dipercaya: walau mengirim ghostWpm,
 * server harus mengabaikannya di survival.
 */
it('ignores ghost data sent by the client while in survival mode', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'survival', 'medium')
        // Klien "nakal" tetap mengirim data ghost.
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], [], 0, 95.0, 'speedy', 120);

    expect(session('typing_result.ghostResult'))->toBeNull();
});

it('keeps ghost data in time mode', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], [], 0, 95.0, 'speedy', 120);

    $ghost = session('typing_result.ghostResult');

    expect($ghost)->not->toBeNull();
    expect($ghost['label'])->toBe('speedy');
});

it('tells the client to clear the ghost when switching to a non-eligible mode', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->assertNotDispatched('ghost-cleared')
        ->call('setMode', 'survival', 'medium')
        ->assertDispatched('ghost-cleared');
});

it('normalizes an unknown mode (e.g. the removed quote mode) to the safe time default', function () {
    $user = User::factory()->create();

    // 'quote' mode was removed; the client sending it must fall back safely.
    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'quote', null)
        ->assertSet('mainMode', 'time')
        ->assertSet('subMode', '30');
});

it('forces the ghost off on the server when switching to survival', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->dispatch('ghost-selected', type: 'own', wpm: 90.0, label: 'me')
        ->assertSet('ghostActive', true)
        ->call('setMode', 'survival', 'medium')
        ->assertSet('ghostActive', false);
});
