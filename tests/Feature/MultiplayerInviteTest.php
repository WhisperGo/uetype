<?php

use App\Enums\FriendshipStatus;
use App\Events\RoomInvitationSent;
use App\Livewire\MultiplayerLobby;
use App\Models\Friendship;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * "Invite a friend" from an empty player slot: a room member picks an accepted friend, who
 * receives a real-time toast on their friends.{id} channel with a deep link
 * (/multiplayer?invite=CODE) that auto-joins them via mount(). Guards keep it from being a
 * spam vector: waiting-room only, accepted friends only, not already-in-room, rate-limited.
 */
function inviteRoom(string $code, User $host, string $status = 'waiting'): Room
{
    return Room::create([
        'code' => $code, 'host_id' => $host->id, 'status' => $status,
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => $status === 'racing' ? now()->subSeconds(5) : null,
    ]);
}

function befriend(User $a, User $b): void
{
    Friendship::create(['requester_id' => $a->id, 'addressee_id' => $b->id, 'status' => FriendshipStatus::Accepted]);
}

it('broadcasts an invitation to an accepted friend', function () {
    Event::fake([RoomInvitationSent::class]);

    $host = User::factory()->create(['avatar' => 'https://example.test/avatar.png']);
    $friend = User::factory()->create();
    befriend($host, $friend);

    $room = inviteRoom('INV001', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->call('invitePlayer', $friend->id)
        ->assertDispatched('invite-sent', friendId: $friend->id);

    // Payload carries name + avatar so the overlay renders the inviter's profile client-side.
    Event::assertDispatched(RoomInvitationSent::class, function (RoomInvitationSent $e) use ($friend, $host) {
        return $e->userId === $friend->id
            && $e->roomCode === 'INV001'
            && $e->inviterUsername === $host->username
            && $e->inviterAvatar === 'https://example.test/avatar.png';
    });
});

it('refuses to invite a user who is not an accepted friend', function () {
    Event::fake([RoomInvitationSent::class]);

    $host = User::factory()->create();
    $stranger = User::factory()->create();

    $room = inviteRoom('INV002', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->call('invitePlayer', $stranger->id);

    Event::assertNotDispatched(RoomInvitationSent::class);
});

it('refuses to invite on a merely pending friendship', function () {
    Event::fake([RoomInvitationSent::class]);

    $host = User::factory()->create();
    $pending = User::factory()->create();
    Friendship::create(['requester_id' => $host->id, 'addressee_id' => $pending->id, 'status' => FriendshipStatus::Pending]);

    $room = inviteRoom('INV003', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->call('invitePlayer', $pending->id);

    Event::assertNotDispatched(RoomInvitationSent::class);
});

it('does not invite a friend already in the room', function () {
    Event::fake([RoomInvitationSent::class]);

    $host = User::factory()->create();
    $friend = User::factory()->create();
    befriend($host, $friend);

    $room = inviteRoom('INV004', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $friend->id, 'role' => 'player', 'is_ready' => false]);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->call('invitePlayer', $friend->id);

    Event::assertNotDispatched(RoomInvitationSent::class);
});

it('never invites once the race has started (waiting-room only)', function () {
    Event::fake([RoomInvitationSent::class]);

    $host = User::factory()->create();
    $friend = User::factory()->create();
    befriend($host, $friend);

    $room = inviteRoom('INV005', $host, 'racing');
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'INV005')->set('step', 'racing')
        ->call('invitePlayer', $friend->id);

    Event::assertNotDispatched(RoomInvitationSent::class);
});

it('lets a non-host member invite too (inviting is collaborative)', function () {
    Event::fake([RoomInvitationSent::class]);

    $host = User::factory()->create();
    $member = User::factory()->create();
    $friend = User::factory()->create();
    befriend($member, $friend);

    $room = inviteRoom('INV006', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $member->id, 'role' => 'player', 'is_ready' => false]);

    Livewire::actingAs($member)->test(MultiplayerLobby::class)
        ->call('invitePlayer', $friend->id);

    Event::assertDispatched(RoomInvitationSent::class);
});

it('rate-limits invite spam per inviter', function () {
    Event::fake([RoomInvitationSent::class]);

    $host = User::factory()->create();
    RateLimiter::clear('room-invite:'.$host->id);

    $room = inviteRoom('INV007', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

    // 12 distinct friends; only the first 10 invites should get through.
    $component = Livewire::actingAs($host)->test(MultiplayerLobby::class);

    foreach (range(1, 12) as $i) {
        $friend = User::factory()->create();
        befriend($host, $friend);
        $component->call('invitePlayer', $friend->id);
    }

    Event::assertDispatchedTimes(RoomInvitationSent::class, 10);
});

it('categorizes friends as online, offline, or already in the room', function () {
    $host = User::factory()->create();
    $onlineFriend = User::factory()->create(); // factory default: online
    $offlineFriend = User::factory()->offline()->create();
    $inRoomFriend = User::factory()->create();

    befriend($host, $onlineFriend);
    befriend($host, $offlineFriend);
    befriend($host, $inRoomFriend);

    $room = inviteRoom('INV008', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $inRoomFriend->id, 'role' => 'player', 'is_ready' => false]);

    $rows = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->instance()->invitableFriends;

    $byId = $rows->keyBy(fn ($r) => $r['user']->id);

    expect($byId[$onlineFriend->id]['online'])->toBeTrue()
        ->and($byId[$onlineFriend->id]['in_room'])->toBeFalse()
        ->and($byId[$offlineFriend->id]['online'])->toBeFalse()
        ->and($byId[$inRoomFriend->id]['in_room'])->toBeTrue();
});

it('auto-joins a room from an invite deep link', function () {
    $host = User::factory()->create();
    $invited = User::factory()->create();
    befriend($host, $invited);

    $room = inviteRoom('INV009', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

    // Arriving at /multiplayer?invite=INV009 -> mount() joins the room.
    Livewire::actingAs($invited)->test(MultiplayerLobby::class, ['invite' => 'INV009'])
        ->assertSet('step', 'waiting')
        ->assertSet('roomCode', 'INV009');

    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $invited->id]);
});

it('auto-joins from a real ?invite= query string on the multiplayer route', function () {
    $host = User::factory()->create();
    $invited = User::factory()->create();
    befriend($host, $invited);

    $room = inviteRoom('INV010', $host);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

    // Hit the actual HTTP route with the query string (not a Livewire mount arg): proves
    // mount() reads request()->query('invite'), since Livewire injects route params only.
    $this->actingAs($invited)->get(route('multiplayer.lobby', ['invite' => 'INV010']))->assertOk();

    $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $invited->id]);
});

it('lands on the choose screen for an invite link to a room that no longer exists', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(MultiplayerLobby::class, ['invite' => 'GONE99'])
        ->assertSet('step', 'choose');

    expect(RoomMember::where('user_id', $user->id)->exists())->toBeFalse();
});
