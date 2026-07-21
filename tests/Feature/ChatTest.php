<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\FriendshipStatus;
use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Livewire\Chat;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageClear;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function makeAcceptedFriends(): array
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

function makeClanWithMembers(int $memberCount = 2): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Test Clan '.uniqid(), 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $members = [$leader];
    for ($i = 1; $i < $memberCount; $i++) {
        $member = User::factory()->create();
        ClanMember::create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);
        $members[] = $member;
    }

    return [$clan, $members];
}

it('requires authentication to view the chat page', function () {
    $this->get(route('chat.index'))->assertRedirect(route('login'));
});

// ---- DM ----

it('lets an accepted friend open a DM and send a message', function () {
    [$me, $friend] = makeAcceptedFriends();

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->assertSet('activeMode', 'dm')
        ->assertSet('withUsername', $friend->username)
        ->set('body', 'Halo!')
        ->call('sendMessage');

    $this->assertDatabaseHas('messages', [
        'sender_id' => $me->id,
        'recipient_id' => $friend->id,
        'clan_id' => null,
        'body' => 'Halo!',
    ]);
});

it('broadcasts DirectMessageSent to the recipient when a DM is sent', function () {
    Event::fake([DirectMessageSent::class]);
    [$me, $friend] = makeAcceptedFriends();

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('body', 'Halo!')
        ->call('sendMessage');

    Event::assertDispatched(DirectMessageSent::class, fn ($e) => $e->message->recipient_id === $friend->id);
});

it('does not let a user DM someone who is not an accepted friend (trust boundary)', function () {
    $me = User::factory()->create();
    $stranger = User::factory()->create();

    $component = Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $stranger->username);

    $component->assertSet('activeMode', null);

    $component->set('activeMode', 'dm')->set('withUsername', $stranger->username)
        ->set('body', 'halo')->call('sendMessage');

    $this->assertDatabaseMissing('messages', ['sender_id' => $me->id, 'recipient_id' => $stranger->id]);
});

it('rejects an empty message and a message over the length limit', function () {
    [$me, $friend] = makeAcceptedFriends();

    $component = Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username);

    $component->set('body', '   ')->call('sendMessage');
    $this->assertDatabaseMissing('messages', ['sender_id' => $me->id]);

    $component->set('body', str_repeat('a', 2001))->call('sendMessage');
    $this->assertDatabaseMissing('messages', ['sender_id' => $me->id]);
});

it('marks incoming DMs as read when opening the conversation', function () {
    [$me, $friend] = makeAcceptedFriends();

    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'hai']);
    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'apa kabar']);

    Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username);

    expect(Message::where('recipient_id', $me->id)->whereNull('read_at')->count())->toBe(0);
});

it('lists DM conversations ordered by the latest message, with unread counts', function () {
    $me = User::factory()->create();
    $oldFriend = User::factory()->create();
    $recentFriend = User::factory()->create();

    Friendship::create(['requester_id' => $me->id, 'addressee_id' => $oldFriend->id, 'status' => FriendshipStatus::Accepted]);
    Friendship::create(['requester_id' => $me->id, 'addressee_id' => $recentFriend->id, 'status' => FriendshipStatus::Accepted]);

    $old = Message::create(['sender_id' => $me->id, 'recipient_id' => $oldFriend->id, 'body' => 'lama']);
    $old->forceFill(['created_at' => now()->subDay()])->save();
    Message::create(['sender_id' => $recentFriend->id, 'recipient_id' => $me->id, 'body' => 'baru']);

    $conversations = Livewire::actingAs($me)->test(Chat::class)->get('conversations');

    expect($conversations->first()['user']->id)->toBe($recentFriend->id);
    expect($conversations->first()['unreadCount'])->toBe(1);
    expect($conversations->last()['unreadCount'])->toBe(0);
});

it('does not show a DM conversation from a friendship that was removed, but keeps the message history', function () {
    [$me, $friend] = makeAcceptedFriends();

    Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'halo']);

    Friendship::where('requester_id', $me->id)->where('addressee_id', $friend->id)->delete();

    $conversations = Livewire::actingAs($me)->test(Chat::class)->get('conversations');

    expect($conversations->pluck('user.id'))->not->toContain($friend->id);
    $this->assertDatabaseHas('messages', ['sender_id' => $me->id, 'recipient_id' => $friend->id]);
});

it('paginates older DMs via loadOlder', function () {
    [$me, $friend] = makeAcceptedFriends();

    for ($i = 0; $i < 35; $i++) {
        Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => "pesan {$i}"]);
    }

    $component = Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username);

    expect($component->get('messages'))->toHaveCount(Chat::PAGE_SIZE);
    expect($component->get('hasMoreOlder'))->toBeTrue();

    $component->call('loadOlder');

    expect($component->get('messages'))->toHaveCount(35);
    expect($component->get('hasMoreOlder'))->toBeFalse();
});

// ---- CLAN CHAT ----

it('lets an active clan member open clan chat and send a message', function () {
    [$clan, [$leader]] = makeClanWithMembers(2);

    Livewire::actingAs($leader)->test(Chat::class)
        ->call('openClanChat')
        ->assertSet('activeMode', 'clan')
        ->set('body', 'Halo clan!')
        ->call('sendMessage');

    $this->assertDatabaseHas('messages', [
        'sender_id' => $leader->id,
        'clan_id' => $clan->id,
        'recipient_id' => null,
        'body' => 'Halo clan!',
    ]);
});

it('broadcasts ClanMessageSent when a clan message is sent', function () {
    Event::fake([ClanMessageSent::class]);
    [$clan, [$leader]] = makeClanWithMembers(2);

    Livewire::actingAs($leader)->test(Chat::class)
        ->call('openClanChat')
        ->set('body', 'Halo clan!')
        ->call('sendMessage');

    Event::assertDispatched(ClanMessageSent::class, fn ($e) => $e->message->clan_id === $clan->id);
});

it('does not let a user without a clan open or send clan chat (trust boundary)', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(Chat::class)->call('openClanChat');
    $component->assertSet('activeMode', null);

    $component->set('activeMode', 'clan')->set('body', 'halo')->call('sendMessage');
    $this->assertDatabaseMissing('messages', ['sender_id' => $user->id]);
});

it('shares clan chat history among all active members of the same clan', function () {
    [$clan, [$leader, $member]] = makeClanWithMembers(2);

    Livewire::actingAs($leader)->test(Chat::class)->call('openClanChat')->set('body', 'dari leader')->call('sendMessage');

    $messages = Livewire::actingAs($member)->test(Chat::class)->call('openClanChat')->get('messages');

    expect($messages)->toHaveCount(1);
    expect($messages->first()->body)->toBe('dari leader');
});

it('does not let a member of a different clan see or send to this clan chat', function () {
    [$clanA, [$leaderA]] = makeClanWithMembers(1);
    [$clanB, [$leaderB]] = makeClanWithMembers(1);

    Livewire::actingAs($leaderA)->test(Chat::class)->call('openClanChat')->set('body', 'rahasia clan A')->call('sendMessage');

    $messagesForB = Livewire::actingAs($leaderB)->test(Chat::class)->call('openClanChat')->get('messages');

    expect($messagesForB)->toHaveCount(0);
});

// ---- CLEAR CHAT (soft-hide, per-user) ----

it('clears DM history for the clearing user only, leaving the other participant untouched', function () {
    [$me, $friend] = makeAcceptedFriends();
    Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'pesan lama']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('clearScope', 'all')
        ->call('confirmClear');

    $myMessages = Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username)->get('messages');
    $friendMessages = Livewire::actingAs($friend)->test(Chat::class)->call('openDm', $me->username)->get('messages');

    expect($myMessages)->toHaveCount(0);
    expect($friendMessages)->toHaveCount(1);

    // Baris pesan aslinya TETAP ada di database, tak terhapus sungguhan.
    $this->assertDatabaseHas('messages', ['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'pesan lama']);
});

it('clears only messages older than N days when scope is "days"', function () {
    [$me, $friend] = makeAcceptedFriends();

    $old = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'lama']);
    $old->forceFill(['created_at' => now()->subDays(10)])->save();

    Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'baru']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('clearScope', 'days')
        ->set('clearDays', 7)
        ->call('confirmClear');

    $messages = Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username)->get('messages');

    expect($messages)->toHaveCount(1);
    expect($messages->first()->body)->toBe('baru');
});

it('clamps an out-of-range clearDays instead of trusting client input', function () {
    [$me, $friend] = makeAcceptedFriends();

    // Pesan berumur 20 tahun. `max="3650"` (10 tahun) hanya di HTML; request buatan
    // bisa mengirim angka apa pun. Server harus menjepit ke 3650 hari, jadi pesan
    // yang lebih tua dari itu TETAP terhapus, tapi tak lebih.
    $ancient = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'purba']);
    $ancient->forceFill(['created_at' => now()->subYears(20)])->save();

    $withinCap = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'dalam-cap']);
    $withinCap->forceFill(['created_at' => now()->subDays(3000)])->save();

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('clearScope', 'days')
        ->set('clearDays', 999999)   // jauh di atas batas
        ->call('confirmClear');

    // cleared_before dijepit ke 3650 hari lalu: pesan 3000 hari (dalam cap) tetap
    // ada, pesan 20 tahun (di luar cap) terhapus. Kalau 999999 dipakai apa adanya,
    // cleared_before jatuh ~2700 tahun lalu -> tak ada yang terhapus.
    $messages = Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)->get('messages');

    expect($messages->pluck('body')->all())->toBe(['dalam-cap']);
});

it('clears clan chat history for the clearing member only', function () {
    [$clan, [$leader, $member]] = makeClanWithMembers(2);

    Message::create(['sender_id' => $leader->id, 'clan_id' => $clan->id, 'body' => 'pesan clan lama']);

    Livewire::actingAs($leader)->test(Chat::class)
        ->call('openClanChat')
        ->set('clearScope', 'all')
        ->call('confirmClear');

    $leaderMessages = Livewire::actingAs($leader)->test(Chat::class)->call('openClanChat')->get('messages');
    $memberMessages = Livewire::actingAs($member)->test(Chat::class)->call('openClanChat')->get('messages');

    expect($leaderMessages)->toHaveCount(0);
    expect($memberMessages)->toHaveCount(1);
});

it('lets a user clear chat again to push the cleared_before threshold forward', function () {
    [$me, $friend] = makeAcceptedFriends();

    Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'pesan 1']);

    Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username)->call('confirmClear');

    Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'pesan 2']);

    Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username)->call('confirmClear');

    expect(MessageClear::where('user_id', $me->id)->where('other_user_id', $friend->id)->count())->toBe(1);

    $messages = Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username)->get('messages');
    expect($messages)->toHaveCount(0);
});

// ---- EDIT ----

it('lets the sender edit their own message and marks it edited + broadcasts', function () {
    Event::fake([MessageEdited::class]);
    [$me, $friend] = makeAcceptedFriends();

    $msg = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'Halo']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->call('startEdit', $msg->id)
        ->assertSet('editingId', $msg->id)
        ->set('editBody', 'Halo (revisi)')
        ->call('saveEdit')
        ->assertSet('editingId', null);

    $msg->refresh();
    expect($msg->body)->toBe('Halo (revisi)');
    expect($msg->isEdited())->toBeTrue();

    Event::assertDispatched(MessageEdited::class);
});

it('does not let a non-sender edit a message', function () {
    [$me, $friend] = makeAcceptedFriends();

    $msg = Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'punya teman']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->call('startEdit', $msg->id)
        ->assertSet('editingId', null); // ditolak, form tak terbuka

    expect($msg->fresh()->isEdited())->toBeFalse();
});

it('does not let the sender edit a message past the 30-minute window', function () {
    [$me, $friend] = makeAcceptedFriends();

    $msg = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'pesan lama']);
    $msg->forceFill(['created_at' => now()->subMinutes(31)])->save();

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->call('startEdit', $msg->id)
        ->assertSet('editingId', null);
});

// ---- DELETE FOR EVERYONE ----

it('lets the sender delete a message for everyone, replacing it with a placeholder for all', function () {
    Event::fake([MessageDeleted::class]);
    [$me, $friend] = makeAcceptedFriends();

    $msg = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'Hi']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->call('deleteForEveryone', $msg->id);

    $msg->refresh();
    expect($msg->isDeletedForEveryone())->toBeTrue();

    // Baris tetap ada (jadi placeholder), tak dihapus dari DB.
    $this->assertDatabaseHas('messages', ['id' => $msg->id]);

    // Terlihat sebagai "deleted" untuk KEDUA sisi (masih ada di daftar, tapi ditandai).
    $friendMessages = Livewire::actingAs($friend)->test(Chat::class)->call('openDm', $me->username)->get('messages');
    expect($friendMessages)->toHaveCount(1);
    expect($friendMessages->first()->isDeletedForEveryone())->toBeTrue();

    Event::assertDispatched(MessageDeleted::class);
});

it('does not let a non-sender delete a message for everyone', function () {
    [$me, $friend] = makeAcceptedFriends();

    $msg = Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'punya teman']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->call('deleteForEveryone', $msg->id);

    expect($msg->fresh()->isDeletedForEveryone())->toBeFalse();
});

// ---- DELETE FOR ME ----

it('hides a message for the deleting user only, keeping it for the other participant', function () {
    [$me, $friend] = makeAcceptedFriends();

    $msg = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'Hi']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->call('deleteForMe', $msg->id);

    $this->assertDatabaseHas('message_deletes', ['user_id' => $me->id, 'message_id' => $msg->id]);
    // Baris pesan asli TIDAK dihapus.
    $this->assertDatabaseHas('messages', ['id' => $msg->id]);

    $myMessages = Livewire::actingAs($me)->test(Chat::class)->call('openDm', $friend->username)->get('messages');
    $friendMessages = Livewire::actingAs($friend)->test(Chat::class)->call('openDm', $me->username)->get('messages');

    expect($myMessages)->toHaveCount(0);   // hilang untukku
    expect($friendMessages)->toHaveCount(1); // tetap untuk teman
});

it('lets a clan member delete a clan message for themselves only', function () {
    [$clan, [$leader, $member]] = makeClanWithMembers(2);

    $msg = Message::create(['sender_id' => $leader->id, 'clan_id' => $clan->id, 'body' => 'halo clan']);

    Livewire::actingAs($member)->test(Chat::class)
        ->call('openClanChat')
        ->call('deleteForMe', $msg->id);

    $memberMessages = Livewire::actingAs($member)->test(Chat::class)->call('openClanChat')->get('messages');
    $leaderMessages = Livewire::actingAs($leader)->test(Chat::class)->call('openClanChat')->get('messages');

    expect($memberMessages)->toHaveCount(0);
    expect($leaderMessages)->toHaveCount(1);
});

it('does not let a non-participant delete-for-me a message they cannot see', function () {
    [$me, $friend] = makeAcceptedFriends();
    $stranger = User::factory()->create();

    $msg = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'rahasia']);

    Livewire::actingAs($stranger)->test(Chat::class)
        ->call('deleteForMe', $msg->id);

    $this->assertDatabaseMissing('message_deletes', ['user_id' => $stranger->id, 'message_id' => $msg->id]);
});

// ---- REPLY ----

it('sends a DM as a reply, storing reply_to_id', function () {
    [$me, $friend] = makeAcceptedFriends();

    $original = Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'pertanyaan?']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->call('startReply', $original->id)
        ->assertSet('replyingToId', $original->id)
        ->set('body', 'jawaban!')
        ->call('sendMessage')
        ->assertSet('replyingToId', null); // reset setelah kirim

    $this->assertDatabaseHas('messages', [
        'sender_id' => $me->id,
        'recipient_id' => $friend->id,
        'body' => 'jawaban!',
        'reply_to_id' => $original->id,
    ]);
});

it('sends a clan message as a reply', function () {
    [$clan, [$leader, $member]] = makeClanWithMembers(2);

    $original = Message::create(['sender_id' => $leader->id, 'clan_id' => $clan->id, 'body' => 'ayo ngobrol']);

    Livewire::actingAs($member)->test(Chat::class)
        ->call('openClanChat')
        ->call('startReply', $original->id)
        ->set('body', 'siap!')
        ->call('sendMessage');

    $this->assertDatabaseHas('messages', [
        'sender_id' => $member->id,
        'clan_id' => $clan->id,
        'body' => 'siap!',
        'reply_to_id' => $original->id,
    ]);
});

it('ignores a reply target from a different conversation (drops reply_to_id)', function () {
    [$me, $friend] = makeAcceptedFriends();
    $other = User::factory()->create();
    Friendship::create(['requester_id' => $me->id, 'addressee_id' => $other->id, 'status' => FriendshipStatus::Accepted]);

    // Pesan dari percakapan DM dengan $other, tak boleh jadi target reply di DM dengan $friend.
    $foreign = Message::create(['sender_id' => $other->id, 'recipient_id' => $me->id, 'body' => 'dari orang lain']);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('replyingToId', $foreign->id)
        ->set('body', 'halo')
        ->call('sendMessage');

    // Terkirim, tapi TANPA reply_to_id (target lintas-percakapan ditolak).
    $this->assertDatabaseHas('messages', [
        'sender_id' => $me->id,
        'recipient_id' => $friend->id,
        'body' => 'halo',
        'reply_to_id' => null,
    ]);
});

it('does not let a stranger start a reply to a message they cannot see', function () {
    [$me, $friend] = makeAcceptedFriends();
    $stranger = User::factory()->create();

    $msg = Message::create(['sender_id' => $me->id, 'recipient_id' => $friend->id, 'body' => 'rahasia']);

    Livewire::actingAs($stranger)->test(Chat::class)
        ->call('startReply', $msg->id)
        ->assertSet('replyingToId', null);
});

// ---- ENDPOINT KIRIM (paralel, di luar Livewire) ----

it('sends a DM via the /chat/send endpoint and broadcasts', function () {
    Event::fake([DirectMessageSent::class]);
    [$me, $friend] = makeAcceptedFriends();

    $this->actingAs($me)
        ->postJson(route('chat.send'), ['mode' => 'dm', 'with' => $friend->username, 'body' => 'via endpoint'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    $this->assertDatabaseHas('messages', [
        'sender_id' => $me->id,
        'recipient_id' => $friend->id,
        'body' => 'via endpoint',
    ]);
    Event::assertDispatched(DirectMessageSent::class);
});

it('rejects a DM via endpoint to a non-friend (trust boundary)', function () {
    $me = User::factory()->create();
    $stranger = User::factory()->create();

    $this->actingAs($me)
        ->postJson(route('chat.send'), ['mode' => 'dm', 'with' => $stranger->username, 'body' => 'halo'])
        ->assertForbidden();

    $this->assertDatabaseMissing('messages', ['sender_id' => $me->id, 'recipient_id' => $stranger->id]);
});

it('sends a clan message via the endpoint for an active member', function () {
    Event::fake([ClanMessageSent::class]);
    [$clan, [$leader]] = makeClanWithMembers(1);

    $this->actingAs($leader)
        ->postJson(route('chat.send'), ['mode' => 'clan', 'body' => 'halo clan'])
        ->assertOk();

    $this->assertDatabaseHas('messages', ['sender_id' => $leader->id, 'clan_id' => $clan->id, 'body' => 'halo clan']);
    Event::assertDispatched(ClanMessageSent::class);
});

it('rejects a clan message via endpoint from a user without a clan', function () {
    $me = User::factory()->create();

    $this->actingAs($me)
        ->postJson(route('chat.send'), ['mode' => 'clan', 'body' => 'halo'])
        ->assertForbidden();
});

it('requires authentication to use the send endpoint', function () {
    $this->postJson(route('chat.send'), ['mode' => 'dm', 'body' => 'x'])
        ->assertUnauthorized();
});

it('validates the send endpoint payload', function () {
    $me = User::factory()->create();

    $this->actingAs($me)
        ->postJson(route('chat.send'), ['mode' => 'invalid', 'body' => ''])
        ->assertStatus(422);
});
