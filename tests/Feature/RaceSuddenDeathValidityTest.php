<?php

use App\Enums\RoomStatus;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * Sudden death is the clock that ends a race once "someone finished". It must start ONLY
 * off a finish the anti-cheat would accept. A fast-garbage finisher (100% progress with an
 * impossibly low accuracy) used to stamp a finish time, take place 1, AND start the clock --
 * cutting the race short for the honest players still typing, and putting a rejected result
 * on the podium. The race must keep waiting for a genuine finisher instead.
 *
 * text_to_type is 100 characters so progress% equals the correct-character count.
 */
function sdRoom(User $host, int $startedSecondsAgo = 40): Room
{
    return Room::create([
        'code' => 'SD'.random_int(1000, 9999),
        'host_id' => $host->id,
        'status' => 'racing',
        'text_to_type' => str_repeat('ab cde ', 14).'ab', // 100 characters
        'language' => 'en',
        'race_starts_at' => now()->subSeconds($startedSecondsAgo),
    ]);
}

function sdMember(Room $room, User $user, int $progress = 0): RoomMember
{
    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true,
        'progress_percent' => $progress,
        'wpm' => 0,
    ]);
}

it('does not start sudden death when an invalid result finishes first', function () {
    $host = User::factory()->create();
    $cheater = User::factory()->create();

    $room = sdRoom($host);
    sdMember($room, $host, progress: 50);
    $bad = sdMember($room, $cheater, progress: 0);

    // 100% progress but 3% accuracy = fast garbage: the finish is rejected.
    Livewire::actingAs($cheater)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('updateRaceProgress', 100, 0, 3);

    $bad->refresh();

    // The cheater is marked finished (they can't keep racing) but takes NO place, and the
    // sudden death clock never started.
    expect($bad->finished_time_seconds)->not->toBeNull()
        ->and($bad->place)->toBeNull()
        ->and($room->fresh()->countdown_started_at)->toBeNull()
        ->and($room->fresh()->status)->toBe(RoomStatus::Racing);
});

it('starts sudden death when a valid result finishes first', function () {
    $host = User::factory()->create();
    $winner = User::factory()->create();

    $room = sdRoom($host);
    sdMember($room, $host, progress: 40);
    $good = sdMember($room, $winner, progress: 90);

    // 100% progress with full accuracy over a realistic duration: a valid finish.
    Livewire::actingAs($winner)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('updateRaceProgress', 100, 100, 100);

    $good->refresh();

    expect($good->finished_time_seconds)->not->toBeNull()
        ->and($good->place)->toBe(1)
        ->and($room->fresh()->countdown_started_at)->not->toBeNull();
});

it('starts sudden death only when a valid finisher follows an invalid one', function () {
    $host = User::factory()->create();
    $cheater = User::factory()->create();
    $winner = User::factory()->create();

    $room = sdRoom($host);
    sdMember($room, $host, progress: 30);
    sdMember($room, $cheater, progress: 0);
    sdMember($room, $winner, progress: 90);

    // Invalid finish first: no sudden death.
    Livewire::actingAs($cheater)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('updateRaceProgress', 100, 0, 3);

    expect($room->fresh()->countdown_started_at)->toBeNull();

    // Then a valid finisher: now it starts, and they take place 1 (the invalid one didn't
    // consume the slot).
    Livewire::actingAs($winner)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('updateRaceProgress', 100, 100, 100);

    $winnerMember = RoomMember::where('room_id', $room->id)->where('user_id', $winner->id)->first();

    expect($room->fresh()->countdown_started_at)->not->toBeNull()
        ->and($winnerMember->place)->toBe(1);
});
