<?php

use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Livewire\MultiplayerLobby;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * "The race is over" must have ONE definition, and that definition must be a CLAIM.
 *
 * closeRaceNow() was extracted precisely so the three deadlines (sudden death, start grace,
 * hard ceiling) could not drift into three subtly different endings, and it claims the room
 * with a conditional `where('status', racing)` update so exactly one caller of any number
 * finalizes. Two paths did not go through it: the "everyone has finished" fast-path in
 * updateRaceProgress() and the same fast-path in giveUp(). Both wrote
 * `$room->update(['status' => Finished])` UNCONDITIONALLY and then finalized -- so they took
 * no part in the claim that protects the third path, and two callers arriving together could
 * both finalize.
 *
 * That matters because finalizeRace() awards XP through increment() and writes a permanent
 * multiplayer_match_history row, and neither can be taken back: total_xp is never recomputed
 * from history, and the history table has no unique constraint. It is the same shape as the
 * Elo double-apply fixed in ClanWarResolver::settleWar(), whose docblock says it plainly --
 * increment() is atomic per column, but that never protected against running the whole
 * settlement twice.
 *
 * WHAT THESE TESTS CAN AND CANNOT PROVE. A genuinely concurrent interleaving needs two
 * database connections, which RefreshDatabase's transaction rules out. So these pin the
 * CONTRACT instead, the same way ClanWarResolutionIdempotencyTest does for settleWar(): that
 * both fast-paths now end the race through closeRaceNow(), and that a second closing attempt
 * is refused. That is the half the code can be held to; the concurrent half follows from the
 * conditional update itself.
 */
beforeEach(function () {
    Event::fake([RoomUpdated::class, SuddenDeathTriggered::class]);
});

/**
 * A race one keystroke from over: $other has finished, $last has not, and sudden death is
 * running but nowhere near elapsed (so the deadline resolvers stay out of the way and the
 * fast-path is what closes the room).
 *
 * @return array{0: Room, 1: User, 2: User}
 */
function raceAwaitingLastFinisher(string $code): array
{
    $last = User::factory()->create();
    $other = User::factory()->create();

    $room = Room::create([
        'code' => $code,
        'host_id' => $last->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(30),
        // Sudden death is live but only 2s in, so resolveSuddenDeathIfElapsed() does not fire
        // and the close under test is unambiguously the fast-path's.
        'countdown_started_at' => now()->subSeconds(2),
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $last->id,
        'is_ready' => true, 'progress_percent' => 60, 'wpm' => 35, 'accuracy' => 95,
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $other->id,
        'is_ready' => true, 'progress_percent' => 100, 'wpm' => 40, 'accuracy' => 97,
        'finished_time_seconds' => 25, 'place' => 1,
    ]);

    return [$room, $last, $other];
}

it('closes the race through closeRaceNow when the last racer finishes', function () {
    [$room, $last] = raceAwaitingLastFinisher('CLAIM1');

    // Finishing as the last racer used to write the status inline and never open the result
    // panel for the finisher -- they only got it when the broadcast came back around. Going
    // through closeRaceNow() means the player who ended the race is told so on the same
    // round-trip, which is also what makes the close a claim.
    Livewire::actingAs($last)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 55, 96)
        ->assertSet('showResultModal', true)
        ->assertDispatched('force-finish');

    expect($room->fresh()->status->value)->toBe('finished');
});

it('closes the race through closeRaceNow when the last racer gives up', function () {
    [$room, $last] = raceAwaitingLastFinisher('CLAIM2');

    Livewire::actingAs($last)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('giveUp')
        ->assertSet('showResultModal', true);

    expect($room->fresh()->status->value)->toBe('finished');
});

it('refuses a second close after the fast-path already claimed the race', function () {
    [$room, $last, $other] = raceAwaitingLastFinisher('CLAIM3');

    $component = Livewire::actingAs($last)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 55, 96);

    // The sudden-death timer of a client that had not heard yet, arriving after the race is
    // already closed. It must find nothing left to do: the room is no longer 'racing', so the
    // conditional update matches no row and finalization does not run a second time.
    $component->call('checkSuddenDeath');

    expect(MultiplayerMatchHistory::where('user_id', $last->id)->count())->toBe(1)
        ->and(MultiplayerMatchHistory::where('user_id', $other->id)->count())->toBe(1);
});

/**
 * The reason the claim has to sit in front of finalization rather than inside it: XP is the
 * half that cannot be undone. A history row could at least be de-duplicated after the fact;
 * total_xp is only ever incremented and never recomputed from the rows that justified it.
 */
it('awards each racer their EXP exactly once when the race is closed', function () {
    [$room, $last, $other] = raceAwaitingLastFinisher('CLAIM4');

    $component = Livewire::actingAs($last)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 55, 96);

    $xpAfterClose = [$last->fresh()->total_xp, $other->fresh()->total_xp];

    $component->call('checkSuddenDeath');

    expect([$last->fresh()->total_xp, $other->fresh()->total_xp])->toBe($xpAfterClose)
        ->and($xpAfterClose[0])->toBeGreaterThan(0);
});
