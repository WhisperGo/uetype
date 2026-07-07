<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
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

    it('records the real elapsed time from race start when a player finishes', function () {
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
});
