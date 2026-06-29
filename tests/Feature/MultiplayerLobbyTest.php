<?php

use App\Livewire\MultiplayerLobby;
use App\Models\User;
use Livewire\Livewire;

describe('multiplayer lobby', function () {
    it('renders the lobby page for authenticated users', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/multiplayer');

        $response->assertOk();
        $response->assertSee('Create Room');
        $response->assertSee('Join Room');
    });

    it('renders the waiting room after creating a room', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(MultiplayerLobby::class)
            ->call('createRoom')
            ->assertSee('Room Code - Share with friends')
            ->assertSee('Players');
    });
});
