<?php

use App\Enums\FriendshipStatus;
use App\Livewire\GhostPicker;
use App\Livewire\TypingEngine;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

/** Satu baris rekor. */
function lbResult(User $user, string $mode, string $config, float $wpm): void
{
    TypingResult::create([
        'user_id' => $user->id, 'mode' => $mode, 'mode_config' => $config,
        'net_wpm' => $wpm, 'raw_wpm' => $wpm + 5, 'accuracy' => 96,
        'correct_chars' => 300, 'incorrect_chars' => 10, 'duration_seconds' => 30,
    ]);
}

it('menyimpan identitas ghost ke session saat memilih (own)', function () {
    $user = User::factory()->create(['highest_wpm' => 88]);

    Livewire::actingAs($user)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('selectOpponent', 'own');

    expect(session('ghost_selection'))->toBe(['type' => 'own', 'ref_id' => null]);
});

it('menyimpan user_id untuk pilihan leaderboard', function () {
    $target = User::factory()->create();
    $viewer = User::factory()->create();
    lbResult($target, 'time', '30', 120);

    Livewire::actingAs($viewer)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('selectOpponent', 'leaderboard', $target->id);

    expect(session('ghost_selection'))->toBe(['type' => 'leaderboard', 'ref_id' => $target->id]);
});

/**
 * INTI TEMUAN: sesudah menyelesaikan tes lalu "Next Test" (mount ulang TypingEngine),
 * the ghost that was set must APPEAR AGAIN, not vanish.
 */
it('memulihkan ghost dari session saat mount berikutnya (skenario Next Test)', function () {
    $user = User::factory()->create(['highest_wpm' => 77]);
    session(['ghost_selection' => ['type' => 'own', 'ref_id' => null]]);

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->assertSet('ghostActive', true)
        ->assertDispatched('ghost-selected', fn ($name, $params) => (float) $params['wpm'] === 77.0 && $params['label'] === 'Your Best');
});

it('tidak memulihkan ghost kalau session kosong', function () {
    $user = User::factory()->create(['highest_wpm' => 77]);

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->assertSet('ghostActive', false)
        ->assertNotDispatched('ghost-selected');
});

it('menurunkan ULANG wpm dari DB saat restore, bukan angka beku', function () {
    $target = User::factory()->create();
    $viewer = User::factory()->create();
    lbResult($target, 'time', '30', 100);
    session(['ghost_selection' => ['type' => 'leaderboard', 'ref_id' => $target->id]]);

    // The opponent improves their record -> the restore must follow the NEW number.
    lbResult($target, 'time', '30', 140);

    Livewire::actingAs($viewer)->test(TypingEngine::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->assertDispatched('ghost-selected', fn ($name, $params) => (float) $params['wpm'] === 140.0);
});

it('suspend saat pindah ke survival TANPA menghapus pilihan session', function () {
    $user = User::factory()->create(['highest_wpm' => 90]);
    session(['ghost_selection' => ['type' => 'own', 'ref_id' => null]]);

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->assertSet('ghostActive', true)
        ->call('setMode', 'survival', 'medium')
        ->assertSet('ghostActive', false);

    // The selection is STILL in the session (just hidden).
    expect(session('ghost_selection'))->toBe(['type' => 'own', 'ref_id' => null]);
});

it('memulihkan ghost lagi saat balik dari survival ke time/words', function () {
    $user = User::factory()->create(['highest_wpm' => 90]);
    session(['ghost_selection' => ['type' => 'own', 'ref_id' => null]]);

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'survival', 'medium')
        ->assertSet('ghostActive', false)
        ->call('setMode', 'time', '30')
        ->assertSet('ghostActive', true)
        ->assertDispatched('ghost-selected', label: 'Your Best');
});

it('clearGhost menghapus pilihan dari session dan menyembunyikan', function () {
    $user = User::factory()->create(['highest_wpm' => 90]);
    session(['ghost_selection' => ['type' => 'own', 'ref_id' => null]]);

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->assertSet('ghostActive', true)
        ->call('clearGhost')
        ->assertSet('ghostActive', false)
        ->assertDispatched('ghost-cleared');

    expect(session('ghost_selection'))->toBeNull();
});

it('setelah clearGhost, ghost tidak muncul lagi di mount berikutnya', function () {
    $user = User::factory()->create(['highest_wpm' => 90]);
    session(['ghost_selection' => ['type' => 'own', 'ref_id' => null]]);

    Livewire::actingAs($user)->test(TypingEngine::class)->call('clearGhost');

    // A fresh mount (simulating Next Test) -> still no ghost.
    Livewire::actingAs($user)->test(TypingEngine::class)
        ->assertSet('ghostActive', false)
        ->assertNotDispatched('ghost-selected');
});

it('clearOpponent di picker juga menghapus pilihan session', function () {
    $user = User::factory()->create(['highest_wpm' => 90]);
    session(['ghost_selection' => ['type' => 'own', 'ref_id' => null]]);

    Livewire::actingAs($user)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('clearOpponent')
        ->assertDispatched('ghost-cleared');

    expect(session('ghost_selection'))->toBeNull();
});

it('deep-link ?ghost= yang valid menulis pilihan ke session (jadi sticky)', function () {
    $target = User::factory()->create(['username' => 'rival']);
    $viewer = User::factory()->create();
    lbResult($target, 'time', '60', 110);

    Livewire::actingAs($viewer)->withQueryParams([
        'ghost' => $target->id, 'mode' => 'time', 'config' => '60',
    ])->test(TypingEngine::class)
        ->assertSet('mainMode', 'time')
        ->assertSet('subMode', '60')
        ->assertSet('ghostActive', true)
        ->assertDispatched('ghost-selected', fn ($name, $params) => (float) $params['wpm'] === 110.0 && $params['label'] === 'rival');

    expect(session('ghost_selection'))->toBe(['type' => 'leaderboard', 'ref_id' => $target->id]);
});

it('deep-link tak valid (lawan tanpa rekor) tidak menulis session', function () {
    $target = User::factory()->create();
    $viewer = User::factory()->create();
    // No record for the target.

    Livewire::actingAs($viewer)->withQueryParams([
        'ghost' => $target->id, 'mode' => 'time', 'config' => '30',
    ])->test(TypingEngine::class)
        ->assertSet('ghostActive', false);

    expect(session('ghost_selection'))->toBeNull();
});

it('ghost teman ikut sticky lintas mount', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create(['username' => 'kawan', 'highest_wpm' => 82]);
    $f = Friendship::create([
        'requester_id' => $me->id, 'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    // Pilih lewat picker...
    Livewire::actingAs($me)->test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('selectOpponent', 'friend', $f->id);

    // ...then a fresh TypingEngine mount -> the friend ghost is restored.
    Livewire::actingAs($me)->test(TypingEngine::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->assertSet('ghostActive', true)
        ->assertDispatched('ghost-selected', fn ($name, $params) => (float) $params['wpm'] === 82.0 && $params['label'] === 'kawan');
});
