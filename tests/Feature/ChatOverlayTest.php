<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\FriendshipStatus;
use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Livewire\ChatOverlay;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function overlayAcceptedFriends(): array
{
    $me = User::factory()->create();
    $friend = User::factory()->create();

    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    return [$me, $friend];
}

function overlayClanWithMembers(int $memberCount = 2): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Overlay Clan '.uniqid(), 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $members = [$leader];
    for ($i = 1; $i < $memberCount; $i++) {
        $member = User::factory()->create();
        ClanMember::create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);
        $members[] = $member;
    }

    return [$clan, $members];
}

it('toggles open state', function () {
    $me = User::factory()->create();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->assertSet('open', false)
        ->call('toggleOverlay')
        ->assertSet('open', true)
        ->call('toggleOverlay')
        ->assertSet('open', false);
});

it('lets an accepted friend open a DM in the overlay and send a message', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->assertSet('activeMode', 'dm')
        ->assertSet('withUsername', $friend->username)
        ->set('body', 'Halo dari overlay!')
        ->call('sendMessage');

    $this->assertDatabaseHas('messages', [
        'sender_id' => $me->id,
        'recipient_id' => $friend->id,
        'clan_id' => null,
        'body' => 'Halo dari overlay!',
    ]);
});

it('does not let the overlay DM a non-friend (trust boundary)', function () {
    $me = User::factory()->create();
    $stranger = User::factory()->create();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $stranger->username)
        ->assertSet('activeMode', null);

    $this->assertDatabaseMissing('messages', ['sender_id' => $me->id, 'recipient_id' => $stranger->id]);
});

it('broadcasts DirectMessageSent when sent via the overlay', function () {
    Event::fake([DirectMessageSent::class]);
    [$me, $friend] = overlayAcceptedFriends();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->set('body', 'Halo!')
        ->call('sendMessage');

    Event::assertDispatched(DirectMessageSent::class, fn ($e) => $e->message->recipient_id === $friend->id);
});

it('lets an active clan member open clan chat in the overlay and send a message', function () {
    [$clan, $members] = overlayClanWithMembers();
    $leader = $members[0];

    Event::fake([ClanMessageSent::class]);

    Livewire::actingAs($leader)->test(ChatOverlay::class)
        ->call('openClanChat')
        ->assertSet('activeMode', 'clan')
        ->set('body', 'Halo clan!')
        ->call('sendMessage');

    $this->assertDatabaseHas('messages', [
        'sender_id' => $leader->id,
        'clan_id' => $clan->id,
        'body' => 'Halo clan!',
    ]);
    Event::assertDispatched(ClanMessageSent::class);
});

it('returns to the picker without closing the drawer', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('toggleOverlay')
        ->call('openDm', $friend->username)
        ->assertSet('activeMode', 'dm')
        ->call('backToPicker')
        ->assertSet('activeMode', null)
        ->assertSet('open', true);
});

it('limits recentContacts to the capped count', function () {
    $me = User::factory()->create();

    for ($i = 0; $i < 12; $i++) {
        $friend = User::factory()->create();
        Friendship::create([
            'requester_id' => $me->id,
            'addressee_id' => $friend->id,
            'status' => FriendshipStatus::Accepted,
        ]);
    }

    $component = Livewire::actingAs($me)->test(ChatOverlay::class);

    expect($component->get('recentContacts'))->toHaveCount(8);
});

it('computes unread count matching the same query as the full chat page', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'satu']);
    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'dua']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->assertSet('unreadCount', 2);
});

it('marks incoming DMs as read when opening the conversation in the overlay', function () {
    [$me, $friend] = overlayAcceptedFriends();

    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'halo']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->assertSet('unreadCount', 0);
});

it('lets the sender edit their own message from the overlay', function () {
    [$me, $friend] = overlayAcceptedFriends();
    $message = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'salah ketik']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->call('startEdit', $message->id)
        ->set('editBody', 'sudah benar')
        ->call('saveEdit');

    expect($message->fresh()->body)->toBe('sudah benar');
    expect($message->fresh()->isEdited())->toBeTrue();
});

it('lets the sender delete a message for everyone from the overlay', function () {
    [$me, $friend] = overlayAcceptedFriends();
    $message = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'oops']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->call('deleteForEveryone', $message->id);

    expect($message->fresh()->isDeletedForEveryone())->toBeTrue();
});

it('clears DM history from the overlay for the clearing user only', function () {
    [$me, $friend] = overlayAcceptedFriends();
    Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'halo']);

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->call('confirmClear');

    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->assertSet('messages', function ($messages) {
            return $messages->isEmpty();
        });
});
