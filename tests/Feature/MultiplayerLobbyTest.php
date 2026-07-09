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

describe('multiplayer lobby', function () {
    it('renders the lobby page for authenticated users', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/multiplayer');

        $response->assertOk();
        $response->assertSee('Create Room');
        $response->assertSee('Join Room');
    });

    it('renders the waiting room after creating a room', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(MultiplayerLobby::class)
            ->call('createRoom')
            ->assertSee('Room Code - Share with friends')
            ->assertSee('Players');
    });

    it('joins a room from the join-code array the paste handler populates', function () {
        // Handler paste menulis seluruh array joinCodeInput via $wire.set; ini
        // menegaskan kontrak itu: array 6-elemen -> joinRoom() memasukkan user.
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();
        $joiner = User::factory()->create();

        $room = Room::create([
            'code' => 'XYZ789',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $host->id,
            'is_ready' => false,
        ]);

        Livewire::actingAs($joiner)->test(MultiplayerLobby::class)
            ->set('joinCodeInput', ['X', 'Y', 'Z', '7', '8', '9'])
            ->call('joinRoom')
            ->assertSet('step', 'waiting');

        $this->assertDatabaseHas('room_members', [
            'room_id' => $room->id,
            'user_id' => $joiner->id,
        ]);
    });

    it('records the real elapsed time from race start when a player finishes', function () {
        // Broadcast di-fake supaya updateRaceProgress() tak mencoba konek Reverb asli.
        Event::fake([RaceProgressUpdated::class, RoomUpdated::class, SuddenDeathTriggered::class]);

        $user = User::factory()->create();

        // Race dimulai 30 detik yang lalu (race_starts_at di masa lalu), bukan baru saja.
        $room = Room::create([
            'code' => 'ABC123',
            'host_id' => $user->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(30),
            // updated_at sengaja SANGAT baru (mensimulasikan baris yang baru saja
            // ter-update oleh broadcast lain) -- inilah yang dulu keliru dipakai
            // sebagai basis durasi, menghasilkan angka kecil (mis. 2 detik).
        ]);
        $room->forceFill(['updated_at' => now()->subSeconds(2)])->save();

        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_ready' => true,
            'progress_percent' => 50,
            'wpm' => 60,
            'accuracy' => 100,
        ]);

        Livewire::actingAs($user)->test(MultiplayerLobby::class)
            ->set('roomCode', 'ABC123')
            ->set('step', 'racing')
            ->call('updateRaceProgress', 100, 60, 100);

        $seconds = RoomMember::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->value('finished_time_seconds');

        // Harus ~30 detik (durasi asli sejak race_starts_at), BUKAN ~2 detik
        // (yang akan terjadi kalau masih memakai updated_at yang lama & keliru).
        expect($seconds)->toBeGreaterThanOrEqual(29);
        expect($seconds)->toBeLessThanOrEqual(31);
    });

    it('sends the remaining racer back to the choose screen when the host leaves mid-race', function () {
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();
        $racer = User::factory()->create();

        $room = Room::create([
            'code' => 'LEAVE1',
            'host_id' => $host->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(5),
        ]);
        foreach ([$host, $racer] as $u) {
            RoomMember::create(['room_id' => $room->id, 'user_id' => $u->id, 'is_ready' => true]);
        }

        // Host keluar di tengah balapan -> room dihapus.
        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'LEAVE1')
            ->set('step', 'racing')
            ->call('leaveRoom');

        $this->assertDatabaseMissing('rooms', ['code' => 'LEAVE1']);

        // Pemain yang tersisa menerima room-updated: harus balik ke 'choose',
        // bukan tertinggal di 'racing' tanpa roomData (halaman kosong).
        Livewire::actingAs($racer)->test(MultiplayerLobby::class)
            ->set('roomCode', 'LEAVE1')
            ->set('step', 'racing')
            ->call('roomUpdated')
            ->assertSet('step', 'choose')
            ->assertSet('roomCode', '')
            ->assertSee('Create Room')
            ->assertSee('Join Room');
    });

    it('recovers to the choose screen on render when the room vanished without an event', function () {
        // Reverb mati -> event room-updated tak pernah sampai. Penjaga di render()
        // tetap harus memulihkan, bukan merender halaman kosong.
        $racer = User::factory()->create();

        Livewire::actingAs($racer)->test(MultiplayerLobby::class)
            ->set('roomCode', 'GHOST1') // room tak pernah ada di DB
            ->set('step', 'racing')
            ->assertSet('step', 'choose')
            ->assertSee('Create Room');
    });
});
