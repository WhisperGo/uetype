<?php

use App\Livewire\TypingEngine;
use App\Models\User;
use Livewire\Livewire;

/**
 * Transisi keluar/masuk /typing dibuat MUAT-HALAMAN-PENUH (tanpa navigate:true) supaya
 * tombol Back browser tidak me-restore snapshot SPA yang merusak mesin ketik (entangle
 * undefined + $wire basi). Test ini mengunci TARGET redirect-nya tetap benar; perilaku
 * Back/bfcache sendiri bersifat browser-level dan diverifikasi manual.
 */
it('redirects to the result page after a valid solo result', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 30000, 150, 140, [40, 42], [45, 47], [], 0, 0, '', 0)
        ->assertRedirect(route('typing.result'));
});

it('redirects back to typing when the result is rejected by anti-cheat', function () {
    $user = User::factory()->create();

    // 5000 karakter dalam 1 detik -> wpm mustahil -> ditolak.
    Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'time', '30')
        ->call('saveResult', 1000, 5000, 5000, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    expect(session('result_rejected'))->not->toBeNull();
});
