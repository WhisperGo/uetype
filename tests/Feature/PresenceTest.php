<?php

use App\Enums\FriendshipStatus;
use App\Events\PresenceUpdated;
use App\Livewire\Friends;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\actingAs;

it('marks a user online after a heartbeat', function () {
    $user = User::factory()->create(['last_seen_at' => null]);

    expect($user->isOnline())->toBeFalse();

    actingAs($user)->postJson(route('presence.heartbeat'))
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect($user->fresh()->isOnline())->toBeTrue();
});

it('considers a stale last_seen_at as offline', function () {
    $online = User::factory()->create(['last_seen_at' => now()->subSeconds(10)]);
    $stale = User::factory()->create(['last_seen_at' => now()->subMinutes(5)]);

    expect($online->isOnline())->toBeTrue();
    expect($stale->isOnline())->toBeFalse();
});

it('requires authentication for the heartbeat endpoint', function () {
    $this->postJson(route('presence.heartbeat'))->assertUnauthorized();
});

it('broadcasts presence to friends only on the offline to online transition', function () {
    Event::fake([PresenceUpdated::class]);

    $me = User::factory()->create(['last_seen_at' => null]);
    $friend = User::factory()->create();
    $stranger = User::factory()->create();

    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    // Transisi pertama offline->online: broadcast ke teman.
    $me->touchPresence();
    Event::assertDispatched(PresenceUpdated::class, fn ($e) => $e->friendId === $friend->id);
    Event::assertNotDispatched(PresenceUpdated::class, fn ($e) => $e->friendId === $stranger->id);

    // A follow-up heartbeat (already online): no broadcast again.
    Event::fake([PresenceUpdated::class]);
    $me->fresh()->touchPresence();
    Event::assertNotDispatched(PresenceUpdated::class);
});

it('broadcasts offline to friends on logout when the user was online', function () {
    Event::fake([PresenceUpdated::class]);

    $me = User::factory()->create(['last_seen_at' => now()]);
    $friend = User::factory()->create();

    Friendship::create([
        'requester_id' => $friend->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    expect($me->isOnline())->toBeTrue();

    $me->markOffline();

    expect($me->fresh()->last_seen_at)->toBeNull();
    Event::assertDispatched(PresenceUpdated::class, fn ($e) => $e->friendId === $friend->id);
});

it('does not broadcast pending (non-accepted) friendships', function () {
    Event::fake([PresenceUpdated::class]);

    $me = User::factory()->create(['last_seen_at' => null]);
    $pending = User::factory()->create();

    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $pending->id,
        'status' => FriendshipStatus::Pending,
    ]);

    $me->touchPresence();

    Event::assertNotDispatched(PresenceUpdated::class);
});

it('shows friend online status in the friends list', function () {
    $me = User::factory()->create();
    $onlineFriend = User::factory()->create(['last_seen_at' => now()]);
    $offlineFriend = User::factory()->create(['last_seen_at' => now()->subHour()]);

    Friendship::create(['requester_id' => $me->id, 'addressee_id' => $onlineFriend->id, 'status' => FriendshipStatus::Accepted]);
    Friendship::create(['requester_id' => $me->id, 'addressee_id' => $offlineFriend->id, 'status' => FriendshipStatus::Accepted]);

    $list = Livewire\Livewire::actingAs($me)->test(Friends::class)->get('friendsList');

    $onlineRow = $list->firstWhere('user.id', $onlineFriend->id);
    $offlineRow = $list->firstWhere('user.id', $offlineFriend->id);

    expect($onlineRow['online'])->toBeTrue();
    expect($offlineRow['online'])->toBeFalse();
});
