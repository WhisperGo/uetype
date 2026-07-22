<?php

use App\Livewire\MultiplayerLobby;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\AntiCheatService;
use Livewire\Livewire;

/**
 * Anti-cheat at race finalization, aligned with solo mode: an implausible or empty
 * result is rejected -- not written to multiplayer_match_history and no EXP -- while a
 * slow-but-real finish (and a DNF that actually typed) stays recorded. The gate lives
 * in AntiCheatService::rejectsRaceResult() so race and solo share one definition.
 */
function finalizedRoom(callable $seedMembers): Room
{
    $host = User::factory()->create();

    $room = Room::create([
        'code' => 'VAL123',
        'host_id' => $host->id,
        'status' => 'racing',
        // 100 chars, so progress% maps cleanly to a correct-char count.
        'text_to_type' => str_repeat('ab cde fgh ', 9).'a',
        'race_starts_at' => now()->subSeconds(60),
    ]);

    $seedMembers($room, $host);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'VAL123')->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    return $room;
}

it('rejects an empty session: joined but never typed a character', function () {
    $empty = User::factory()->create();

    $room = finalizedRoom(function (Room $room, User $host) use ($empty) {
        // A real finisher.
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true,
            'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 98, 'finished_time_seconds' => 30,
        ]);
        // Never typed: 0% progress -> 0 correct chars -> no_input.
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $empty->id, 'is_ready' => true,
            'progress_percent' => 0, 'wpm' => 0, 'accuracy' => 0,
            'finished_time_seconds' => RoomMember::DNF_SENTINEL_SECONDS,
        ]);
    });

    $emptyMember = RoomMember::where('room_id', $room->id)->where('user_id', $empty->id)->first();

    // Empty session: marked rejected, no EXP, and no permanent history row.
    expect($emptyMember->result_recorded)->toBeFalse()
        ->and($emptyMember->xp_earned)->toBe(0)
        ->and($empty->fresh()->total_xp)->toBe(0)
        ->and(MultiplayerMatchHistory::where('user_id', $empty->id)->exists())->toBeFalse();

    // The genuine finisher is unaffected -- still recorded.
    expect(MultiplayerMatchHistory::where('user_id', $room->host_id)->exists())->toBeTrue();
});

it('rejects an impossible result: WPM beyond the human ceiling', function () {
    $cheater = User::factory()->create();

    // A crafted RoomMember whose (progress, duration) recompute to a superhuman WPM:
    // 100 correct chars in 1s ~= 1200 WPM, far above MAX_HUMAN_WPM (300).
    $room = finalizedRoom(function (Room $room, User $host) use ($cheater) {
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true,
            'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 98, 'finished_time_seconds' => 30,
        ]);
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $cheater->id, 'is_ready' => true,
            'progress_percent' => 100, 'wpm' => 1200, 'accuracy' => 100, 'finished_time_seconds' => 1,
        ]);
    });

    $cheaterMember = RoomMember::where('room_id', $room->id)->where('user_id', $cheater->id)->first();

    expect($cheaterMember->result_recorded)->toBeFalse()
        ->and($cheaterMember->xp_earned)->toBe(0)
        ->and(MultiplayerMatchHistory::where('user_id', $cheater->id)->exists())->toBeFalse();
});

it('rejects fast garbage: high progress with impossibly low accuracy', function () {
    $garbage = User::factory()->create();

    // Finished the text (100% progress -> high server-derived WPM) but reports 3%
    // accuracy. Impossible: progress only advances on correct chars, so completing the
    // text cannot coexist with 3% accuracy. This is the 200-WPM/3% screenshot case.
    $room = finalizedRoom(function (Room $room, User $host) use ($garbage) {
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true,
            'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 98, 'finished_time_seconds' => 30,
        ]);
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $garbage->id, 'is_ready' => true,
            'progress_percent' => 100, 'wpm' => 200, 'accuracy' => 3, 'finished_time_seconds' => 14,
        ]);
    });

    $garbageMember = RoomMember::where('room_id', $room->id)->where('user_id', $garbage->id)->first();

    expect($garbageMember->result_recorded)->toBeFalse()
        ->and($garbageMember->xp_earned)->toBe(0)
        ->and(MultiplayerMatchHistory::where('user_id', $garbage->id)->exists())->toBeFalse();
});

it('keeps a low accuracy at LOW progress (a weak attempt, not a cheat)', function () {
    $weak = User::factory()->create();

    // 20% progress at 25% accuracy: below the progress threshold, so the accuracy floor
    // does not apply -- this is a genuine struggling player, kept in history.
    $room = finalizedRoom(function (Room $room, User $host) use ($weak) {
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true,
            'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 98, 'finished_time_seconds' => 30,
        ]);
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $weak->id, 'is_ready' => true,
            'progress_percent' => 20, 'wpm' => 12, 'accuracy' => 25, 'finished_time_seconds' => 55,
        ]);
    });

    $weakMember = RoomMember::where('room_id', $room->id)->where('user_id', $weak->id)->first();

    expect($weakMember->result_recorded)->toBeTrue()
        ->and(MultiplayerMatchHistory::where('user_id', $weak->id)->exists())->toBeTrue();
});

it('keeps a slow-but-real finish recorded', function () {
    $slow = User::factory()->create();

    // ~30 correct chars over 60s ~= 6 WPM: low, but a real human session -- not rejected.
    $room = finalizedRoom(function (Room $room, User $host) use ($slow) {
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true,
            'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 98, 'finished_time_seconds' => 30,
        ]);
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => $slow->id, 'is_ready' => true,
            'progress_percent' => 30, 'wpm' => 6, 'accuracy' => 90, 'finished_time_seconds' => 55,
        ]);
    });

    $slowMember = RoomMember::where('room_id', $room->id)->where('user_id', $slow->id)->first();

    expect($slowMember->result_recorded)->toBeTrue()
        ->and(MultiplayerMatchHistory::where('user_id', $slow->id)->exists())->toBeTrue();
});

it('shares one rejection rule between the empty and the impossible', function () {
    $anti = app(AntiCheatService::class);

    // no_input (empty) -> rejected
    expect($anti->rejectsRaceResult($anti->check(0, 0, 60)['reasons']))->toBeTrue();
    // wpm_too_high (impossible) -> rejected
    expect($anti->rejectsRaceResult($anti->check(100, 100, 1)['reasons']))->toBeTrue();
    // slow but real -> kept
    expect($anti->rejectsRaceResult($anti->check(30, 30, 55)['reasons']))->toBeFalse();

    // high progress + very low accuracy (fast garbage) -> rejected via raceResultReasons
    expect($anti->rejectsRaceResult($anti->raceResultReasons(233, 14, 100, 3.0)))->toBeTrue();
    // high progress + high accuracy (legit) -> kept
    expect($anti->rejectsRaceResult($anti->raceResultReasons(233, 14, 100, 97.0)))->toBeFalse();
    // low progress + low accuracy (weak attempt, below the progress threshold) -> kept
    expect($anti->rejectsRaceResult($anti->raceResultReasons(20, 55, 20, 25.0)))->toBeFalse();
});
