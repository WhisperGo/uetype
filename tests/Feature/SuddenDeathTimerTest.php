<?php

use App\Events\RaceProgressUpdated;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([RaceProgressUpdated::class, RoomUpdated::class, SuddenDeathTriggered::class]);
});

/**
 * countdown_started_at adalah SATU-SATUNYA penanda bahwa sudden death berjalan:
 * checkSuddenDeath() langsung return kalau kolom ini null. Jadi kalau ada satu saja
 * jalur di mana pemain finish TANPA kolom ini terisi, race tak akan pernah ditutup
 * dan pemain yang tersisa menggantung selamanya.
 */
test('sudden death mulai saat pemain pertama finish', function () {
    $racer = User::factory()->create();
    $lain = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0001',
        'host_id' => $racer->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(10),
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $lain->id, 'is_ready' => true]);

    Livewire::actingAs($racer)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0001')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 80, 100);

    expect($room->fresh()->countdown_started_at)->not->toBeNull();
});

/**
 * REGRESI B3: guard lama berbunyi `$alreadyFinishedCount === 0 && ! countdown_started_at`.
 * Syarat "saya orang pertama" itu tak relevan DAN berbahaya: kalau sudah ada pemain
 * yang tercatat finish tapi countdown_started_at belum sempat terisi (jendela race
 * condition antara baca-hitung dan tulis-status), maka pemain BERIKUTNYA yang finish
 * akan gagal syarat `=== 0` -> timer tak pernah menyala -> race menggantung selamanya.
 */
test('sudden death tetap mulai walau sudah ada pemain lain yang tercatat finish', function () {
    $duluan = User::factory()->create();
    $berikutnya = User::factory()->create();
    $masihNgetik = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0002',
        'host_id' => $duluan->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(10),
        // Inti skenarionya: sudah ada yang finish, tapi timer BELUM menyala.
        'countdown_started_at' => null,
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $duluan->id,
        'is_ready' => true, 'progress_percent' => 100,
        'finished_time_seconds' => 8,   // sudah tercatat finish di DB
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $berikutnya->id, 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $masihNgetik->id, 'is_ready' => true]);

    Livewire::actingAs($berikutnya)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0002')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 75, 100);

    // Tanpa perbaikan: null -> checkSuddenDeath() return terus -> $masihNgetik menggantung.
    expect($room->fresh()->countdown_started_at)->not->toBeNull();
});

test('sudden death tetap mulai lewat giveUp walau sudah ada yang finish', function () {
    $duluan = User::factory()->create();
    $menyerah = User::factory()->create();
    $masihNgetik = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0003',
        'host_id' => $duluan->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(10),
        'countdown_started_at' => null,
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $duluan->id,
        'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 8,
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $menyerah->id, 'is_ready' => true, 'progress_percent' => 20]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $masihNgetik->id, 'is_ready' => true, 'progress_percent' => 30]);

    Livewire::actingAs($menyerah)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0003')
        ->set('step', 'racing')
        ->call('giveUp');

    expect($room->fresh()->countdown_started_at)->not->toBeNull();
});

/**
 * Timer TIDAK boleh di-reset oleh pemain kedua yang finish -- kalau di-reset, pemain
 * yang tersisa dapat perpanjangan waktu diam-diam setiap ada orang finish.
 */
test('pemain kedua yang finish tidak me-reset timer sudden death', function () {
    $pertama = User::factory()->create();
    $kedua = User::factory()->create();
    $ketiga = User::factory()->create();

    $mulaiTimer = now()->subSeconds(6);

    $room = Room::create([
        'code' => 'SD0004',
        'host_id' => $pertama->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(20),
        'countdown_started_at' => $mulaiTimer,   // timer sudah jalan 6 detik
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $pertama->id,
        'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 14,
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $kedua->id, 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ketiga->id, 'is_ready' => true]);

    Livewire::actingAs($kedua)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0004')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 70, 100);

    expect($room->fresh()->countdown_started_at->timestamp)->toBe($mulaiTimer->timestamp);
});

/** Setelah timer habis, race HARUS ditutup dan yang belum selesai ditandai DNF. */
test('checkSuddenDeath menutup race setelah 15 detik', function () {
    $pemenang = User::factory()->create();
    $tertinggal = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0005',
        'host_id' => $pemenang->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(40),
        'countdown_started_at' => now()->subSeconds(16),   // sudah lewat 15 detik
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $pemenang->id,
        'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 24,
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $tertinggal->id, 'is_ready' => true, 'progress_percent' => 40]);

    Livewire::actingAs($tertinggal)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0005')
        ->set('step', 'racing')
        ->call('checkSuddenDeath')
        ->assertSet('showResultModal', true);

    expect($room->fresh()->status)->toBe('finished');
});
