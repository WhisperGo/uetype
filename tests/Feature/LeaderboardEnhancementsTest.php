<?php

use App\Enums\FriendshipStatus;
use App\Livewire\TypingEngine;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;
use Livewire\Volt\Volt;

test('sending a friend request from the leaderboard creates a pending friendship', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $this->actingAs($me);

    Volt::test('leaderboard')->call('sendRequest', $other->id);

    $friendship = Friendship::where('requester_id', $me->id)
        ->where('addressee_id', $other->id)
        ->first();

    expect($friendship)->not->toBeNull()
        ->and($friendship->status)->toBe(FriendshipStatus::Pending);
});

test('the leaderboard friend request is a no-op against yourself', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    Volt::test('leaderboard')->call('sendRequest', $me->id);

    expect(Friendship::count())->toBe(0);
});

test('the leaderboard friend request does not duplicate an existing relation', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $other->id,
        'status' => FriendshipStatus::Accepted,
    ]);
    $this->actingAs($me);

    Volt::test('leaderboard')->call('sendRequest', $other->id);

    expect(Friendship::count())->toBe(1);
});

test('a valid ghost deep-link locks the mode to the requested time/words config', function () {
    $me = User::factory()->create();
    $rival = User::factory()->create();
    $this->actingAs($me);

    TypingResult::create([
        'user_id' => $rival->id,
        'mode' => 'time',
        'mode_config' => '60',
        'net_wpm' => 95,
        'raw_wpm' => 100,
        'accuracy' => 97,
        'correct_chars' => 400,
        'incorrect_chars' => 5,
        'duration_seconds' => 60,
    ]);

    $component = Livewire::test(TypingEngine::class, [
        'ghostUserId' => $rival->id,
        'ghostMode' => 'time',
        'ghostConfig' => '60',
    ]);

    expect($component->get('mainMode'))->toBe('time')
        ->and($component->get('subMode'))->toBe('60');

    $component->assertDispatched('ghost-selected', function ($event, $params) use ($rival) {
        return $event === 'ghost-selected'
            && (float) $params['wpm'] === 95.0
            && $params['label'] === $rival->username;
    });
});

test('a malformed ghost deep-link is ignored and does not dispatch a ghost', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    $component = Livewire::test(TypingEngine::class, [
        'ghostUserId' => 999999,
        'ghostMode' => 'survival',
        'ghostConfig' => 'hard',
    ]);

    $component->assertNotDispatched('ghost-selected');
    expect($component->get('ghostUserId'))->toBeNull();
});
