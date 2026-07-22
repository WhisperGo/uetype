<?php

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\User;

/**
 * The nav's friend-request badge reads its count from /friends/pending-count.
 * It must count only PENDING requests where the current user is the addressee
 * (incoming), never sent requests or accepted friendships, and be auth-gated.
 */
it('counts only pending incoming requests for the signed-in user', function () {
    $me = User::factory()->create();

    // Two incoming pending requests -> should count.
    Friendship::create([
        'requester_id' => User::factory()->create()->id,
        'addressee_id' => $me->id, 'status' => FriendshipStatus::Pending,
    ]);
    Friendship::create([
        'requester_id' => User::factory()->create()->id,
        'addressee_id' => $me->id, 'status' => FriendshipStatus::Pending,
    ]);

    // A request I SENT (outgoing) -> must NOT count.
    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => User::factory()->create()->id, 'status' => FriendshipStatus::Pending,
    ]);

    // An accepted friendship -> must NOT count.
    Friendship::create([
        'requester_id' => User::factory()->create()->id,
        'addressee_id' => $me->id, 'status' => FriendshipStatus::Accepted,
    ]);

    // An incoming pending request to SOMEONE ELSE -> must NOT count.
    Friendship::create([
        'requester_id' => User::factory()->create()->id,
        'addressee_id' => User::factory()->create()->id, 'status' => FriendshipStatus::Pending,
    ]);

    $this->actingAs($me)
        ->getJson(route('friends.pending-count'))
        ->assertOk()
        ->assertExactJson(['count' => 2]);
});

it('returns zero when there are no incoming requests', function () {
    $me = User::factory()->create();

    $this->actingAs($me)
        ->getJson(route('friends.pending-count'))
        ->assertOk()
        ->assertExactJson(['count' => 0]);
});

it('requires authentication', function () {
    $this->getJson(route('friends.pending-count'))->assertUnauthorized();
});
