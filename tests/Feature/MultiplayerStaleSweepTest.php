<?php

use App\Events\RoomUpdated;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\RoomMembershipService;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Reaping "stuck" memberships: a ready player or host who closed the tab / lost
 * connection / walked away WITHOUT pressing Leave. The leave-beacon only removes not-ready
 * non-host members on a clean unload, so those two are kept for mount() to restore -- but
 * if they never return, their row lingers, holding a slot and (as host) blocking the room.
 *
 * The sweep uses the site-wide presence heartbeat as its signal: a member whose user is
 * offline (last_seen_at stale past the 60s threshold, or null) is the one actually gone.
 * It runs lazily on lobby load, scoped to 'waiting' rooms only (a 'racing' room is settled
 * by the race itself).
 *
 * NOTE: User::factory() defaults to PRESENT (last_seen_at = now(), isOnline() true). A
 * user who has "gone stuck" is built with ->offline() (last_seen_at null) or by stamping a
 * past last_seen_at explicitly.
 */
function sweepRoom(string $code, string $status, User $host): Room
{
    return Room::create([
        'code' => $code, 'host_id' => $host->id, 'status' => $status,
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => $status === 'racing' ? now()->subSeconds(5) : null,
    ]);
}

function sweep(?int $exceptUserId = null): void
{
    app(RoomMembershipService::class)->sweepOfflineMembers($exceptUserId);
}

it('removes a member whose presence heartbeat has gone stale', function () {
    $host = User::factory()->create();
    $ghost = User::factory()->offline()->create(); // never pinged -> offline
    $room = sweepRoom('SWP001', 'waiting', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ghost->id, 'role' => 'player', 'is_ready' => true]);

    sweep();

    $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $ghost->id]);
    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
});

it('keeps a member who is still pinging, even a ready one sitting idle in the lobby', function () {
    $host = User::factory()->create();
    $present = User::factory()->create();
    $room = sweepRoom('SWP002', 'waiting', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $present->id, 'role' => 'player', 'is_ready' => true]);

    sweep();

    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $present->id]);
});

it('treats last_seen_at just past the 60s threshold as offline', function () {
    $host = User::factory()->create();
    $stale = User::factory()->create();
    $stale->forceFill(['last_seen_at' => now()->subSeconds(61)])->save();

    $room = sweepRoom('SWP003', 'waiting', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $stale->id, 'role' => 'player', 'is_ready' => true]);

    sweep();

    $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $stale->id]);
});

it('keeps a member seen within the threshold', function () {
    $host = User::factory()->create();
    $recent = User::factory()->create();
    $recent->forceFill(['last_seen_at' => now()->subSeconds(45)])->save();

    $room = sweepRoom('SWP004', 'waiting', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $recent->id, 'role' => 'player', 'is_ready' => true]);

    sweep();

    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $recent->id]);
});

it('hands the room to a remaining member when the stale one was host', function () {
    Event::fake([RoomUpdated::class]);
    $ghostHost = User::factory()->offline()->create(); // offline -> swept
    $present = User::factory()->create();
    $room = sweepRoom('SWP005', 'waiting', $ghostHost);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ghostHost->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $present->id, 'role' => 'player', 'is_ready' => false]);

    sweep();

    // Ghost host gone, room alive, host handed to the present member (auto-readied).
    $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $ghostHost->id]);
    $this->assertDatabaseHas('rooms', ['id' => $room->id, 'host_id' => $present->id]);
    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $present->id, 'is_ready' => true]);
    Event::assertDispatched(RoomUpdated::class);
});

it('deletes a room whose every member has gone stale', function () {
    $ghostA = User::factory()->offline()->create();
    $ghostB = User::factory()->offline()->create();
    $room = sweepRoom('SWP006', 'waiting', $ghostA);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ghostA->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ghostB->id, 'role' => 'player', 'is_ready' => true]);

    sweep();

    $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
});

it('never sweeps a racing room (settled by the race, not by presence)', function () {
    $host = User::factory()->create();
    $ghost = User::factory()->offline()->create(); // offline
    $room = sweepRoom('SWP007', 'racing', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ghost->id, 'role' => 'player', 'is_ready' => true]);

    sweep();

    // Even though the ghost is offline, mid-race removal would corrupt placement.
    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $ghost->id]);
});

it('never sweeps the excepted caller even if their heartbeat has not landed yet', function () {
    // The caller's own row: offline-looking (null last_seen_at) on a fresh load, but they
    // are provably present -- exceptUserId protects them.
    $caller = User::factory()->offline()->create();
    $room = sweepRoom('SWP008', 'waiting', $caller);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $caller->id, 'role' => 'player', 'is_ready' => true]);

    sweep(exceptUserId: $caller->id);

    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $caller->id]);
});

it('runs on lobby mount: a returning player finds the ghost host already reaped', function () {
    $ghostHost = User::factory()->offline()->create(); // offline
    $returning = User::factory()->create();
    $room = sweepRoom('SWP009', 'waiting', $ghostHost);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ghostHost->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $returning->id, 'role' => 'player', 'is_ready' => false]);

    Livewire::actingAs($returning)->test(MultiplayerLobby::class)
        ->assertSet('step', 'waiting')
        ->assertSet('roomCode', 'SWP009');

    // mount()'s sweep reaped the ghost host and handed the room to the returning player.
    $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $ghostHost->id]);
    $this->assertDatabaseHas('rooms', ['id' => $room->id, 'host_id' => $returning->id]);
});
