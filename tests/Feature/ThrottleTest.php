<?php

use App\Models\User;

/**
 * Menghapus jalur Breeze ikut membuang dua-satunya `throttle` di aplikasi
 * (verification.verify & verification.send). Test ini mengunci bahwa endpoint
 * tulis panas punya batasnya sendiri, dan -- sama pentingnya -- bahwa batasnya
 * cukup longgar untuk pemakaian normal.
 */
it('membatasi banjir heartbeat', function () {
    $user = User::factory()->create();

    // Kuota 30/menit; ping normal cuma 2/menit (interval klien 30 detik).
    foreach (range(1, 30) as $ignored) {
        $this->actingAs($user)->post(route('presence.heartbeat'))->assertOk();
    }

    $this->actingAs($user)->post(route('presence.heartbeat'))->assertStatus(429);
});

it('membatasi banjir kirim pesan', function () {
    $me = User::factory()->create();

    // Endpoint chat sengaja didesain untuk burst paralel, jadi batasnya longgar.
    // Yang diuji: batas itu ADA, bukan angka persisnya.
    foreach (range(1, 60) as $ignored) {
        $this->actingAs($me)->postJson(route('chat.send'), [
            'mode' => 'dm',
            'body' => 'halo',
            'with' => 'bukan-teman',
        ]);
    }

    $this->actingAs($me)->postJson(route('chat.send'), [
        'mode' => 'dm',
        'body' => 'halo',
        'with' => 'bukan-teman',
    ])->assertStatus(429);
});

it('membatasi banjir ganti bahasa', function () {
    foreach (range(1, 20) as $ignored) {
        $this->from('/typing')->post(route('locale.update'), ['locale' => 'id']);
    }

    $this->from('/typing')->post(route('locale.update'), ['locale' => 'id'])
        ->assertStatus(429);
});

/**
 * Throttle tak boleh memutus fitur. Klien nge-ping tiap 30 detik DAN sekali lagi
 * tiap tab kembali visible -- burst pendek beruntun itu normal, bukan serangan.
 */
it('meloloskan burst heartbeat yang wajar saat user bolak-balik tab', function () {
    $user = User::factory()->create();

    foreach (range(1, 10) as $ignored) {
        $this->actingAs($user)->post(route('presence.heartbeat'))->assertOk();
    }
});
