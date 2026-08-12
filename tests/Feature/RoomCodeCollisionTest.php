<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Str;
use Livewire\Livewire;

/**
 * `rooms.code` is unique, and createRoom() generated one without ever asking whether it was
 * free. A collision therefore surfaced as an unhandled QueryException inside the create
 * transaction -- a 500 page for a player who did nothing wrong and has no way to act on it.
 *
 * Six random characters make it rare, which is exactly why it deserved handling rather than
 * hope: rare failures are the ones nobody can reproduce from a bug report. The project already
 * answers this elsewhere -- ClanWar::claimMode() catches the unique violation and reports the
 * slot as taken -- so the room path was the odd one out, not the one needing a new idea.
 */
it('retries past a taken room code instead of failing the create', function () {
    $existing = User::factory()->create();

    Room::create([
        'code' => 'TAKEN1',
        'host_id' => $existing->id,
        'status' => 'waiting',
        'text_to_type' => 'the quick brown fox',
    ]);

    // Hand the generator the taken code first, then a free one, so the collision is
    // deterministic rather than waited for.
    //
    // Scoped to six-character requests, NOT a plain sequence: Livewire asks Str::random for its
    // own component ids on the way in, and a sequence is drained by those before createRoom
    // ever runs -- which is precisely how this test first failed, on a code neither entry
    // named. Six is the room code's own length, so the filter isolates the caller under test.
    $queue = ['taken1', 'free01'];

    Str::createRandomStringsUsing(function (int $length) use (&$queue) {
        if ($length === 6 && $queue !== []) {
            return array_shift($queue);
        }

        return substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz0123456789', 4)), 0, $length);
    });

    $host = User::factory()->create();

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->call('createRoom')
        ->assertSet('step', 'waiting')
        ->assertSet('roomCode', 'FREE01');

    expect(Room::where('host_id', $host->id)->first()->code)->toBe('FREE01');

    Str::createRandomStringsNormally();
});

it('still creates a room normally when the first code is free', function () {
    $host = User::factory()->create();

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->call('createRoom')
        ->assertSet('step', 'waiting');

    expect(Room::where('host_id', $host->id)->exists())->toBeTrue();
});
