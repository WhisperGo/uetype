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
 * B4: 999 adalah SENTINEL "tidak selesai", bukan durasi. Di room_members ia boleh
 * ada (transient, cuma untuk sorting), tapi TIDAK BOLEH mendarat di
 * multiplayer_match_history -- di sana kolomnya bermakna durasi tempuh, dan riwayat
 * itulah yang mem-backing statistik permanen pemain.
 */
test('pemain yang menyerah tidak mencatat 999 sebagai durasi di riwayat', function () {
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

    $riwayat = MultiplayerMatchHistory::where('user_id', $menyerah->id)->first();

    expect($riwayat)->not->toBeNull()
        ->and($riwayat->finished_time_seconds)->toBeNull()
        ->and($riwayat->dnf)->toBeTrue();
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
