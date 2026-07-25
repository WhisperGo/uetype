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

beforeEach(function () {
    Event::fake([RoomUpdated::class, SuddenDeathTriggered::class]);
});

/**
 * A DNF means the player did NOT finish -- whether they gave up or were timed out for
 * going AFK. It is not a real typing result, so it must not enter multiplayer_match_history
 * at all: a DNF's low WPM would drag down the player's permanent average. (Previously a DNF
 * was recorded with dnf=true; the rule is now "DNF is never recorded".)
 */
test('a player who gives up is not recorded to history at all', function () {
    $menyerah = User::factory()->create();

    $room = Room::create([
        'code' => 'DNF001',
        'host_id' => $menyerah->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(5),
    ]);
    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $menyerah->id,
        'is_ready' => true, 'progress_percent' => 30, 'wpm' => 40, 'accuracy' => 90,
    ]);

    Livewire::actingAs($menyerah)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DNF001')
        ->set('step', 'racing')
        ->call('giveUp');

    $member = RoomMember::where('user_id', $menyerah->id)->first();

    // No history row, no XP, and marked not-recorded -- but still a DNF on the result screen.
    expect(MultiplayerMatchHistory::where('user_id', $menyerah->id)->exists())->toBeFalse()
        ->and($member->result_recorded)->toBeFalse()
        ->and($member->isDnf())->toBeTrue();
});

/**
 * The AFK case: a player types a little then stops, gets timed out when sudden death
 * expires, and used to land in history at ~3 WPM. It must be excluded like any other DNF.
 */
test('an AFK player timed out at sudden death is not recorded to history', function () {
    $active = User::factory()->create();
    $afk = User::factory()->create();

    $room = Room::create([
        'code' => 'AFK900',
        'host_id' => $active->id,
        'status' => 'racing',
        'text_to_type' => str_repeat('ab cde ', 14).'ab',
        'race_starts_at' => now()->subSeconds(60),
        'countdown_started_at' => now()->subSeconds(16), // sudden death window already elapsed
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $active->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 100, 'wpm' => 40, 'accuracy' => 95, 'finished_time_seconds' => 50]);
    // Typed 8% then went AFK; never finished.
    RoomMember::create(['room_id' => $room->id, 'user_id' => $afk->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 8, 'wpm' => 3, 'accuracy' => 100]);

    Livewire::actingAs($active)->test(MultiplayerLobby::class)
        ->set('roomCode', 'AFK900')
        ->set('step', 'racing')
        ->call('checkSuddenDeath');

    $afkMember = RoomMember::where('user_id', $afk->id)->first();

    expect(MultiplayerMatchHistory::where('user_id', $afk->id)->exists())->toBeFalse()
        ->and($afkMember->result_recorded)->toBeFalse()
        ->and($afkMember->isDnf())->toBeTrue();

    // The player who actually finished is still recorded normally.
    expect(MultiplayerMatchHistory::where('user_id', $active->id)->exists())->toBeTrue();
});

test('pemain yang benar-benar finish mencatat durasi asli dan dnf false', function () {
    $penyelesai = User::factory()->create();

    $room = Room::create([
        'code' => 'FIN001',
        'host_id' => $penyelesai->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(12),
    ]);
    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $penyelesai->id,
        'is_ready' => true, 'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 95,
        'finished_time_seconds' => 12,
    ]);

    Livewire::actingAs($penyelesai)->test(MultiplayerLobby::class)
        ->set('roomCode', 'FIN001')
        ->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    $riwayat = MultiplayerMatchHistory::where('user_id', $penyelesai->id)->first();

    expect($riwayat->finished_time_seconds)->toBe(12)
        ->and($riwayat->dnf)->toBeFalse();
});

/** Rata-rata waktu finish tak boleh tercemar oleh sentinel (NULL diabaikan SQL). */
test('durasi DNF tidak mencemari rata-rata waktu finish', function () {
    $user = User::factory()->create();

    MultiplayerMatchHistory::create([
        'user_id' => $user->id, 'room_code' => 'AAA111', 'place' => 1, 'player_count' => 2,
        'wpm' => 80, 'accuracy' => 96, 'finished_time_seconds' => 20, 'dnf' => false, 'xp_earned' => 10,
    ]);
    MultiplayerMatchHistory::create([
        'user_id' => $user->id, 'room_code' => 'BBB222', 'place' => 2, 'player_count' => 2,
        'wpm' => 0, 'accuracy' => 90, 'finished_time_seconds' => null, 'dnf' => true, 'xp_earned' => 0,
    ]);

    $rata = MultiplayerMatchHistory::where('user_id', $user->id)->avg('finished_time_seconds');

    // 20 saja (DNF diabaikan). Kalau 999 tersimpan, hasilnya jadi ~509.
    expect((float) $rata)->toBe(20.0);
});
