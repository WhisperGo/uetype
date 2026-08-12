<?php

use App\Models\User;

/**
 * Arena balapan dulunya 590 baris <script> inline di multiplayer-lobby.blade.php.
 * Test ini mengunci LOKASI kodenya, bukan perilakunya -- proyek ini tidak punya
 * test yang mengeksekusi JavaScript, jadi perilaku arena tetap harus diverifikasi
 * manual di browser (dua klien, countdown, sudden death, podium).
 */
it('menyimpan arena balapan di modul js, bukan di dalam blade', function () {
    $blade = file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'));

    expect($blade)->not->toContain('<script')
        ->and($blade)->not->toContain("Alpine.store('race'")
        ->and($blade)->not->toContain("Alpine.data('raceArena'");

    expect(file_exists(resource_path('js/race-arena.js')))->toBeTrue()
        ->and(file_exists(resource_path('js/race-echo.js')))->toBeTrue();
});

it('mendaftarkan kedua modul arena lewat bundle', function () {
    $appJs = file_get_contents(resource_path('js/app.js'));

    expect($appJs)->toContain("import './race-arena'")
        ->and($appJs)->toContain("import './race-echo'");
});

/**
 * race-echo memakai `Livewire` global. Modul dieksekusi sebelum Livewire ada, jadi
 * ia HARUS menunggu livewire:init -- di Blade dulu hal ini dijamin oleh @script.
 */
it('menunggu livewire:init sebelum memakai Livewire global', function () {
    $echo = file_get_contents(resource_path('js/race-echo.js'));

    expect($echo)->toContain("document.addEventListener('livewire:init'")
        ->and(strpos($echo, "addEventListener('livewire:init'"))
        ->toBeLessThan(strpos($echo, 'Livewire.on('));
});

/** Data dari server tetap masuk lewat markup, bukan di-hardcode di modul. */
it('mempertahankan config arena di markup', function () {
    $blade = file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'));

    expect($blade)->toContain('raceArena(')
        ->and($blade)->toContain('roomCode: @js($this->roomCode)')
        ->and($blade)->toContain('laneSeeds: @js($laneSeeds)');
});

it('tetap merender lobby multiplayer tanpa error', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('multiplayer.lobby'))
        ->assertOk();
});
