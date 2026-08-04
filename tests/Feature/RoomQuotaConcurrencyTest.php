<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * Room quotas are read-then-write, so they need the lock -- in BOTH places that check them.
 *
 * joinRoomByCode() already knew this and said so: "`count() >= MAX` read outside the
 * transaction can be passed by two players simultaneously, so a room ends up over quota;
 * lockForUpdate serializes it." toggleSpectator() performs the same two counts against the
 * same two limits and had neither the transaction nor the lock, so two spectators pressing
 * "race" together could both read four players and both become the fifth and sixth.
 *
 * A single-threaded test cannot observe a row lock -- proving it would need two connections,
 * which RefreshDatabase's transaction rules out. So this file pins the two halves that CAN be
 * checked: that the quota is actually refused (behaviour), and that the guard is written with
 * the same idiom as the path that already got it right (structure). The structural half reads
 * source deliberately, the same way RaceTrackDesignTest pins "race_starts_at is written
 * exactly once" -- when the invariant IS the implementation, that is where it has to be held.
 */

/** A waiting room with $players racers and $spectators watchers, plus the caller's own row. */
function roomWithQuota(int $players, int $spectators, string $role): array
{
    $me = User::factory()->create();

    $room = Room::create([
        'code' => 'QUOTA'.($players + $spectators),
        'host_id' => $me->id,
        'status' => 'waiting',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $me->id,
        'role' => $role, 'is_ready' => false,
    ]);

    foreach (range(1, $players) as $i) {
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => User::factory()->create()->id,
            'role' => RoomMember::ROLE_PLAYER, 'is_ready' => true,
        ]);
    }

    foreach (range(1, $spectators) as $i) {
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => User::factory()->create()->id,
            'role' => RoomMember::ROLE_SPECTATOR, 'is_ready' => false,
        ]);
    }

    return [$room, $me];
}

it('refuses a spectator becoming a racer once the racer quota is full', function () {
    [$room, $me] = roomWithQuota(players: 5, spectators: 0, role: RoomMember::ROLE_SPECTATOR);

    Livewire::actingAs($me)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'waiting')
        ->call('toggleSpectator');

    expect(RoomMember::where('room_id', $room->id)->where('user_id', $me->id)->first()->role)
        ->toBe(RoomMember::ROLE_SPECTATOR)
        ->and($room->players()->count())->toBe(5);
});

it('refuses a racer becoming a spectator once the spectator quota is full', function () {
    [$room, $me] = roomWithQuota(players: 0, spectators: 5, role: RoomMember::ROLE_PLAYER);

    Livewire::actingAs($me)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'waiting')
        ->call('toggleSpectator');

    expect(RoomMember::where('room_id', $room->id)->where('user_id', $me->id)->first()->role)
        ->toBe(RoomMember::ROLE_PLAYER)
        ->and($room->spectators()->count())->toBe(5);
});

it('guards both quota counts in toggleSpectator with the lock joinRoomByCode uses', function () {
    $source = file_get_contents(app_path('Livewire/MultiplayerLobby.php'));

    // The method body, cut at the next method, so a lockForUpdate() belonging to
    // joinRoomByCode() further up the file cannot make this pass by accident.
    $start = strpos($source, 'public function toggleSpectator(): void');
    expect($start)->not->toBeFalse();

    $end = strpos($source, 'public function leaveRoom(): void', $start);
    expect($end)->not->toBeFalse();

    $body = substr($source, $start, $end - $start);

    // Both counts serialized, and both inside the transaction that gives the lock its life:
    // outside one, SELECT ... FOR UPDATE releases the moment the statement ends.
    expect($body)->toContain('DB::transaction')
        ->and(substr_count($body, 'lockForUpdate()'))->toBe(2);
});
