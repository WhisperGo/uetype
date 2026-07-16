<?php

use App\Livewire\TypingEngine;
use App\Models\User;
use Livewire\Livewire;

/**
 * Survival dikunci untuk guest (keputusan produk 2026-07-16, membalik keputusan
 * sebelumnya). Tamu melihat CTA login, dan server menolak setMode('survival').
 * Sejalan dengan penguncian Ghost -- UI + server-side.
 */
it('menolak setMode survival untuk guest (normalisasi ke Standard)', function () {
    Livewire::test(TypingEngine::class)
        ->call('setMode', 'survival', 'medium')
        ->assertSet('mainMode', 'time')
        ->assertSet('subMode', '30');
});

it('tidak memulihkan preferensi survival dari session untuk guest', function () {
    session(['typing_preferences' => ['mode' => 'survival', 'subMode' => 'hard', 'contentLang' => 'en']]);

    Livewire::test(TypingEngine::class)
        ->assertSet('mainMode', 'time');
});

it('tetap mengizinkan survival untuk user login', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'survival', 'medium')
        ->assertSet('mainMode', 'survival')
        ->assertSet('subMode', 'medium');
});

it('menampilkan CTA login survival untuk guest di halaman typing', function () {
    $this->get('/typing')
        ->assertOk()
        ->assertSee(__('typing.survival_login'));
});

it('tidak menampilkan CTA login survival untuk user login', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/typing')
        ->assertOk()
        ->assertDontSee(__('typing.survival_login'));
});
