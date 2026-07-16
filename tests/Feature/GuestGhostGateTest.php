<?php

use App\Livewire\GhostPicker;
use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

/**
 * Ghost dikunci untuk guest: leaderboard sengaja ditutup untuk tamu, jadi ghost
 * (yang menampilkan isi leaderboard) tak boleh bocor lewat pintu belakang. Tamu
 * melihat CTA "login untuk buka", bukan picker. Survival TIDAK dikunci.
 */
it('tidak memulihkan ghost untuk guest walau session dipalsukan', function () {
    session(['ghost_selection' => ['type' => 'leaderboard', 'ref_id' => 1]]);

    Livewire::test(TypingEngine::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->assertSet('ghostActive', false)
        ->assertNotDispatched('ghost-selected');
});

it('selectOpponent tidak melakukan apa-apa untuk guest', function () {
    $target = User::factory()->create();
    TypingResult::create([
        'user_id' => $target->id, 'mode' => 'time', 'mode_config' => '30',
        'net_wpm' => 120, 'raw_wpm' => 125, 'accuracy' => 96,
        'correct_chars' => 300, 'incorrect_chars' => 10, 'duration_seconds' => 30,
    ]);

    Livewire::test(GhostPicker::class, ['mainMode' => 'time', 'subMode' => '30'])
        ->call('selectOpponent', 'leaderboard', $target->id)
        ->assertNotDispatched('ghost-selected');

    expect(session('ghost_selection'))->toBeNull();
});

it('menampilkan CTA login (bukan tombol pick) di halaman typing untuk guest', function () {
    $this->get('/typing')
        ->assertOk()
        ->assertSee(__('typing.ghost_login'))
        ->assertDontSee(__('typing.ghost_pick'));
});

it('menampilkan tombol pick ghost (bukan CTA login) untuk user login', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/typing')
        ->assertOk()
        ->assertSee(__('typing.ghost_pick'))
        ->assertDontSee(__('typing.ghost_login'));
});
