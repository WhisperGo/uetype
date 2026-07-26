<?php

use App\Events\RoomUpdated;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Room lifecycle across navigation:
 *  #1 mount() restores a still-member straight into their room (no re-entering the code).
 *  #2 leave-beacon removes a not-ready non-host member who navigates away.
 *  #3 leave-confirm leaves (host handoff included) when a ready/host member confirms.
 */
function makeRoom(string $code, string $status, User $host): Room
{
    return Room::create([
        'code' => $code, 'host_id' => $host->id, 'status' => $status,
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => $status === 'racing' ? now()->subSeconds(5) : null,
    ]);
}

describe('#1 mount restore', function () {
    it('restores a waiting-room member straight into the lobby', function () {
        $host = User::factory()->create();
        $room = makeRoom('MNT001', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->assertSet('step', 'waiting')
            ->assertSet('roomCode', 'MNT001')
            ->assertDispatched('subscribe-room', room: 'MNT001');
    });

    it('restores a racing member into the arena', function () {
        $host = User::factory()->create();
        $room = makeRoom('MNT002', 'racing', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->assertSet('step', 'racing')
            ->assertSet('roomCode', 'MNT002');
    });

    it('restores a mid-race refresher into the arena still racing (not conceded)', function () {
        // A reload during a race is NOT a departure: the player stays a live racer and
        // resumes typing. Only an explicit Give Up marks them DNF.
        $host = User::factory()->create();
        $refresher = User::factory()->create();
        $room = makeRoom('MNT002B', 'racing', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $refresher->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 40]);

        // The page unloads mid-race (beacon fires) -- but a race unload must NOT remove them.
        $this->actingAs($refresher)->post(route('multiplayer.leave-beacon'))->assertOk();

        // Still a member, still an active racer (not DNF), reopening lands back in the arena.
        $stillMember = RoomMember::where('room_id', $room->id)->where('user_id', $refresher->id)->first();
        expect($stillMember)->not->toBeNull()
            ->and($stillMember->isDnf())->toBeFalse();

        Livewire::actingAs($refresher)->test(MultiplayerLobby::class)
            ->assertSet('step', 'racing')
            ->assertSet('hasGivenUp', false)
            ->assertSet('hasFinished', false);
    });

    it('exposes the racer saved progress so a reload resumes where they left off', function () {
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('MNT002C', 'racing', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 55]);

        // myResumeProgress feeds the arena's restoreProgress() so it rebuilds the word index.
        Livewire::actingAs($racer)->test(MultiplayerLobby::class)
            ->assertSet('myResumeProgress', 55);
    });

    it('resumes at 0 for a finished player (they are routed to results, not live typing)', function () {
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('MNT002D', 'racing', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        // Already finished with real progress; resume must be 0 so it never re-seeds typing.
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 20]);

        Livewire::actingAs($racer)->test(MultiplayerLobby::class)
            ->assertSet('myResumeProgress', 0);
    });

    it('restores a member who already finished with hasFinished set', function () {
        $host = User::factory()->create();
        $other = User::factory()->create();
        $room = makeRoom('MNT003', 'racing', $host);
        // The current user finished (real duration, not the DNF sentinel).
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 12]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $other->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 50]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->assertSet('step', 'racing')
            ->assertSet('hasFinished', true)
            ->assertSet('hasGivenUp', false);
    });

    it('restores a finished-room member into the result panel', function () {
        $host = User::factory()->create();
        $room = makeRoom('MNT004', 'finished', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 10, 'wpm' => 60, 'accuracy' => 95, 'place' => 1]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->assertSet('step', 'racing')
            ->assertSet('showResultModal', true);
    });

    it('restores a spectator into the room', function () {
        $host = User::factory()->create();
        $spectator = User::factory()->create();
        $room = makeRoom('MNT005', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $spectator->id, 'role' => 'spectator', 'is_ready' => false]);

        Livewire::actingAs($spectator)->test(MultiplayerLobby::class)
            ->assertSet('step', 'waiting')
            ->assertSet('roomCode', 'MNT005');
    });

    it('leaves a user with no membership on the choose screen', function () {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(MultiplayerLobby::class)
            ->assertSet('step', 'choose')
            ->assertSet('roomCode', '');
    });

    // Note: an "orphaned" room_members row (room deleted but member kept) cannot occur --
    // the room_id FK cascades on delete -- so mount()'s Room::find() null-guard is purely
    // defensive and has no reachable test scenario.
});

describe('#2 leave-beacon', function () {
    it('removes a not-ready non-host racer', function () {
        Event::fake([RoomUpdated::class]);
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('BCN001', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => false]);

        $this->actingAs($racer)->post(route('multiplayer.leave-beacon'))->assertOk();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $racer->id]);
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
    });

    it('keeps a ready racer', function () {
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('BCN002', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => true]);

        $this->actingAs($racer)->post(route('multiplayer.leave-beacon'))->assertOk();

        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $racer->id]);
    });

    it('keeps the host even when not marked ready', function () {
        $host = User::factory()->create();
        $room = makeRoom('BCN003', 'waiting', $host);
        // Host row with is_ready false: host must still be kept (host_id guard, not is_ready).
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => false]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => User::factory()->create()->id, 'role' => 'player', 'is_ready' => false]);

        $this->actingAs($host)->post(route('multiplayer.leave-beacon'))->assertOk();

        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
    });

    it('removes a spectator (they are never ready and hold a slot)', function () {
        $host = User::factory()->create();
        $spectator = User::factory()->create();
        $room = makeRoom('BCN004', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $spectator->id, 'role' => 'spectator', 'is_ready' => false]);

        $this->actingAs($spectator)->post(route('multiplayer.leave-beacon'))->assertOk();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $spectator->id]);
    });

    it('does nothing once the race has started (a race unload is a reload)', function () {
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('BCN005', 'racing', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => false]);

        $this->actingAs($racer)->post(route('multiplayer.leave-beacon'))->assertOk();

        // Kept untouched: the row must survive so mount() restores them at their progress.
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $racer->id]);
    });

    it('requires authentication', function () {
        $this->postJson(route('multiplayer.leave-beacon'))->assertUnauthorized();
    });
});

describe('#3 leave-confirm', function () {
    it('removes a ready racer who confirms leaving', function () {
        Event::fake([RoomUpdated::class]);
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('CNF001', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => true]);

        $this->actingAs($racer)->post(route('multiplayer.leave-confirm'))->assertOk();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $racer->id]);
        Event::assertDispatched(RoomUpdated::class);
    });

    it('hands the room to another player when the host confirms leaving', function () {
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('CNF002', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => false]);

        $this->actingAs($host)->post(route('multiplayer.leave-confirm'))->assertOk();

        // Host row gone, room intact, host reassigned to the remaining racer (auto-readied).
        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
        $this->assertDatabaseHas('rooms', ['id' => $room->id, 'host_id' => $racer->id]);
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $racer->id, 'is_ready' => true]);
    });

    it('deletes the room when the last member confirms leaving', function () {
        $host = User::factory()->create();
        $room = makeRoom('CNF003', 'waiting', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

        $this->actingAs($host)->post(route('multiplayer.leave-confirm'))->assertOk();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
    });

    it('removes a racer who confirms leaving mid-race (an explicit leave, not a reload)', function () {
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('CNF004', 'racing', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 30]);

        // Pressing "Leave" on the overlay is a deliberate choice -- they leave for good, even
        // mid-race. (Contrast the beacon test below: a reload keeps the row.)
        $this->actingAs($racer)->post(route('multiplayer.leave-confirm'))->assertOk();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $racer->id]);
        // The room and the still-racing host are untouched.
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
    });

    it('distinguishes a confirmed leave from a reload beacon mid-race', function () {
        $host = User::factory()->create();
        $racer = User::factory()->create();
        $room = makeRoom('CNF005', 'racing', $host);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 30]);

        // The beacon (reload / tab close) mid-race must NOT remove them -- restore relies on it.
        $this->actingAs($racer)->post(route('multiplayer.leave-beacon'))->assertOk();
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $racer->id]);
    });

    it('requires authentication', function () {
        $this->postJson(route('multiplayer.leave-confirm'))->assertUnauthorized();
    });
});
