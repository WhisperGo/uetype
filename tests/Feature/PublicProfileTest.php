<?php

use App\Enums\FriendshipStatus;
use App\Livewire\FriendButton;
use App\Models\Friendship;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

it('renders another user public profile page', function () {
    $me = User::factory()->create();
    $other = User::factory()->create(['username' => 'targetplayer']);

    actingAs($me)
        ->get(route('profile.show', $other))
        ->assertOk()
        ->assertSee('targetplayer')
        ->assertSee('Profil Pemain');
});

it('does not leak private fields on a public profile', function () {
    $me = User::factory()->create();
    $other = User::factory()->create([
        'username' => 'targetplayer',
        'email' => 'secret@example.com',
    ]);

    $response = actingAs($me)->get(route('profile.show', $other));

    // Email adalah data privat — tak boleh bocor di profil publik.
    $response->assertDontSee('secret@example.com');
    // Kartu "Koin" / "XP total" hanya di profil sendiri, bukan publik.
    $response->assertDontSee('Koin');
});

it('redirects to own full profile when visiting own public profile url', function () {
    $me = User::factory()->create(['email' => 'mine@example.com']);

    // Membuka /users/{id-sendiri} harus menampilkan profil penuh sendiri,
    // yang MEMANG menampilkan email sendiri (halaman privat milik sendiri).
    actingAs($me)
        ->get(route('profile.show', $me))
        ->assertOk()
        ->assertSee('mine@example.com');
});

it('requires authentication to view a public profile', function () {
    $other = User::factory()->create();

    $this->get(route('profile.show', $other))
        ->assertRedirect(route('login'));
});

it('sends a friend request via the friend button', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    Livewire::actingAs($me)
        ->test(FriendButton::class, ['target' => $other])
        ->assertSet('relation', 'none')
        ->call('sendRequest')
        ->assertSet('relation', 'sent');

    $this->assertDatabaseHas('friendships', [
        'requester_id' => $me->id,
        'addressee_id' => $other->id,
        'status' => FriendshipStatus::Pending->value,
    ]);
});

it('accepts an incoming friend request via the friend button', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    Friendship::create([
        'requester_id' => $other->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Pending,
    ]);

    Livewire::actingAs($me)
        ->test(FriendButton::class, ['target' => $other])
        ->assertSet('relation', 'incoming')
        ->call('acceptRequest')
        ->assertSet('relation', 'friends');

    $this->assertDatabaseHas('friendships', [
        'requester_id' => $other->id,
        'addressee_id' => $me->id,
        'status' => FriendshipStatus::Accepted->value,
    ]);
});

it('does not let the friend button act on someone elses pending request (trust boundary)', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();
    $stranger = User::factory()->create();

    // Permintaan pending yang TIDAK melibatkan $me.
    Friendship::create([
        'requester_id' => $other->id,
        'addressee_id' => $stranger->id,
        'status' => FriendshipStatus::Pending,
    ]);

    // $me mencoba "accept" lewat tombol di profil $other — tak ada permintaan
    // masuk untuk $me, jadi tak boleh berubah apa pun.
    Livewire::actingAs($me)
        ->test(FriendButton::class, ['target' => $other])
        ->assertSet('relation', 'none')
        ->call('acceptRequest')
        ->assertSet('relation', 'none');

    // Baris pending milik orang lain tetap utuh.
    $this->assertDatabaseHas('friendships', [
        'requester_id' => $other->id,
        'addressee_id' => $stranger->id,
        'status' => FriendshipStatus::Pending->value,
    ]);
});
