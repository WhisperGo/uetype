<?php

use App\Livewire\MultiplayerLobby;
use App\Livewire\Stats;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * rooms/room_members are deleted the moment everyone leaves, so
 * multiplayer_match_history is the only durable record a finished race leaves
 * behind. These tests cover the write path (finalizeRace persisting one row per
 * player, exactly once) and the Stats aggregation/rendering built on top of it.
 */
function finishedRace(): array
{
    $winner = User::factory()->create();
    $loser = User::factory()->create();

    $room = Room::create([
        'code' => 'HIST01',
        'host_id' => $winner->id,
        'status' => 'finished',
        'text_to_type' => 'the quick brown fox jumps',
        'race_starts_at' => now()->subSeconds(10),
    ]);

    // Both actually finished (loser slower). A DNF is no longer recorded, so "one row per
    // player" needs two genuine finishers -- the DNF exclusion is covered separately below.
    RoomMember::create(['room_id' => $room->id, 'user_id' => $winner->id, 'is_ready' => true, 'wpm' => 90, 'accuracy' => 98, 'progress_percent' => 100, 'finished_time_seconds' => 8]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $loser->id, 'is_ready' => true, 'wpm' => 40, 'accuracy' => 85, 'progress_percent' => 100, 'finished_time_seconds' => 16]);

    return [$room, $winner, $loser];
}

it('writes one permanent history row per player when a race is finalized', function () {
    [$room, $winner, $loser] = finishedRace();

    Livewire::actingAs($winner)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    $winnerRow = MultiplayerMatchHistory::where('user_id', $winner->id)->first();
    $loserRow = MultiplayerMatchHistory::where('user_id', $loser->id)->first();

    expect($winnerRow)->not->toBeNull()
        ->and($winnerRow->place)->toBe(1)
        ->and($winnerRow->player_count)->toBe(2)
        ->and($winnerRow->wpm)->toBe(90)
        ->and((float) $winnerRow->accuracy)->toBe(98.0)
        ->and($winnerRow->room_code)->toBe('HIST01');

    expect($loserRow)->not->toBeNull()
        ->and($loserRow->place)->toBe(2)
        ->and($loserRow->wpm)->toBe(40);
});

it('does not duplicate history rows when finalizeRace runs twice', function () {
    // finalizeRace is called from both the "everyone finished" fast-path and
    // checkSuddenDeath(); the xp_earned null-guard must also gate the history insert.
    [$room, $winner, $loser] = finishedRace();

    Livewire::actingAs($winner)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('finalizeRace', $room->id)
        ->call('finalizeRace', $room->id);

    expect(MultiplayerMatchHistory::where('user_id', $winner->id)->count())->toBe(1)
        ->and(MultiplayerMatchHistory::where('user_id', $loser->id)->count())->toBe(1);
});

it('survives the room being deleted afterwards, unlike room_members', function () {
    [$room, $winner, $loser] = finishedRace();

    Livewire::actingAs($winner)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    RoomMember::where('room_id', $room->id)->delete();
    $room->delete();

    expect(MultiplayerMatchHistory::where('user_id', $winner->id)->exists())->toBeTrue();
});

it('does not record an invalid (anti-cheat) result to history, protecting the wpm average', function () {
    // 100% of a 100-char text finished in 1 second -> ~1200 WPM, impossible.
    $cheater = User::factory()->create();
    $honest = User::factory()->create();

    $room = Room::create([
        'code' => 'CHEAT1',
        'host_id' => $cheater->id,
        'status' => 'finished',
        'text_to_type' => str_repeat('ab cde ', 14).'ab', // 100 chars
        'race_starts_at' => now()->subSeconds(30),
    ]);

    RoomMember::create(['room_id' => $room->id, 'user_id' => $cheater->id, 'is_ready' => true, 'wpm' => 1200, 'accuracy' => 100, 'progress_percent' => 100, 'finished_time_seconds' => 1]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $honest->id, 'is_ready' => true, 'wpm' => 55, 'accuracy' => 96, 'progress_percent' => 100, 'finished_time_seconds' => 22]);

    Livewire::actingAs($cheater)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    // Cheater's impossible result is rejected: no history row, no XP, flagged.
    expect(MultiplayerMatchHistory::where('user_id', $cheater->id)->exists())->toBeFalse()
        ->and(RoomMember::where('user_id', $cheater->id)->first()->result_recorded)->toBeFalse()
        ->and((int) RoomMember::where('user_id', $cheater->id)->first()->xp_earned)->toBe(0);

    // Honest player's plausible result is still recorded.
    expect(MultiplayerMatchHistory::where('user_id', $honest->id)->exists())->toBeTrue()
        ->and(RoomMember::where('user_id', $honest->id)->first()->result_recorded)->toBeTrue();
});

it('does not record a DNF / gave-up result to history (protecting the wpm average)', function () {
    // A DNF (gave up, or timed out for going AFK) did not finish, so it is not a real
    // typing result and must stay out of permanent stats -- its low WPM would otherwise
    // drag the player's average down.
    $quitter = User::factory()->create();

    $room = Room::create([
        'code' => 'DNF001',
        'host_id' => $quitter->id,
        'status' => 'finished',
        'text_to_type' => str_repeat('ab cde ', 14).'ab',
        'race_starts_at' => now()->subSeconds(30),
    ]);

    RoomMember::create(['room_id' => $room->id, 'user_id' => $quitter->id, 'is_ready' => true, 'wpm' => 20, 'accuracy' => 90, 'progress_percent' => 15, 'finished_time_seconds' => 999]);

    Livewire::actingAs($quitter)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    expect(MultiplayerMatchHistory::where('user_id', $quitter->id)->exists())->toBeFalse()
        ->and(RoomMember::where('user_id', $quitter->id)->first()->result_recorded)->toBeFalse();
});

it('aggregates multiplayer stats from match history', function () {
    $user = User::factory()->create();

    MultiplayerMatchHistory::create(['user_id' => $user->id, 'room_code' => 'A', 'place' => 1, 'player_count' => 2, 'wpm' => 100, 'accuracy' => 95, 'finished_time_seconds' => 10, 'xp_earned' => 5]);
    MultiplayerMatchHistory::create(['user_id' => $user->id, 'room_code' => 'B', 'place' => 2, 'player_count' => 3, 'wpm' => 60, 'accuracy' => 85, 'finished_time_seconds' => 15, 'xp_earned' => 3]);

    $component = Livewire::actingAs($user)->test(Stats::class);
    $stats = $component->viewData('multiplayerStats');

    expect($stats['total_races'])->toBe(2)
        ->and($stats['wins'])->toBe(1)
        ->and($stats['win_rate'])->toBe(50)
        ->and($stats['avg_wpm'])->toBe(80)
        ->and($stats['best_wpm'])->toBe(100);

    $component->assertSee('Recent Matches');
});

it('shows the multiplayer empty state when the user has no race history', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(Stats::class)
        ->assertSee('No races yet.');
});

it('only aggregates the signed in users own multiplayer history', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    MultiplayerMatchHistory::create(['user_id' => $me->id, 'room_code' => 'A', 'place' => 1, 'player_count' => 2, 'wpm' => 77, 'accuracy' => 90, 'finished_time_seconds' => 10, 'xp_earned' => 5]);
    MultiplayerMatchHistory::create(['user_id' => $other->id, 'room_code' => 'B', 'place' => 1, 'player_count' => 2, 'wpm' => 199, 'accuracy' => 99, 'finished_time_seconds' => 5, 'xp_earned' => 9]);

    Livewire::actingAs($me)->test(Stats::class)
        ->assertSee('77')
        ->assertDontSee('199');
});
