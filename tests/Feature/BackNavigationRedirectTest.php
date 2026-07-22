<?php

use App\Livewire\TypingEngine;
use App\Models\User;
use Livewire\Livewire;

/**
 * The transition into/out of /typing is made a FULL PAGE LOAD (no navigate:true) so the
 * browser Back button doesn't restore an SPA snapshot that breaks the typing engine
 * (entangle undefined + stale $wire). This test locks the redirect TARGET as correct; the
 * Back/bfcache behavior itself is browser-level and verified manually.
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
