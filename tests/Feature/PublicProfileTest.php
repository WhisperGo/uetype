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
        ->assertSee(__('profile.header_public'));
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

it('builds the public profile url from the username, not the numeric id (anti-enumeration)', function () {
    $me = User::factory()->create();
    $other = User::factory()->create(['username' => 'targetplayer']);

    // URL harus berisi username, tak ada jejak ID numerik yang bisa
    // dienumerasi (misal /users/18 -> /users/19 -> ...).
    expect(route('profile.show', $other))->toContain('/users/targetplayer')
        ->not->toContain('/users/'.$other->id);

    // Membuka lewat ID mentah harus GAGAL (404) -- ID bukan lagi jalur valid.
    actingAs($me)->get('/users/'.$other->id)->assertNotFound();
});

// ---- BACK ARROW ----
//
// A public profile is reached from the clan roster, clan detail, friends, chat and the
// leaderboard. The arrow used to be hardcoded to Friends, so returning from a clan
// member's profile dropped the visitor on a page they had never been on.

/**
 * The href on the profile's back arrow, as rendered.
 *
 * Anchored on the aria-label and scanned backwards to the nearest href, rather than
 * matching attributes in order: the tag also carries an Alpine @click containing "> 1",
 * so any `[^>]*` between the two attributes stops at the wrong character.
 */
function backArrowHref(string $html): ?string
{
    $label = 'aria-label="'.__('profile.back').'"';
    $labelPos = strpos($html, $label);

    if ($labelPos === false) {
        return null;
    }

    $tagStart = strrpos(substr($html, 0, $labelPos), '<a ');

    if ($tagStart === false) {
        return null;
    }

    preg_match('/href="([^"]*)"/', substr($html, $tagStart, $labelPos - $tagStart), $m);

    return $m[1] ?? null;
}

it('points the back arrow at the page the visitor came from', function (string $from) {
    $me = User::factory()->create();
    $other = User::factory()->create(['username' => 'targetplayer']);

    $html = actingAs($me)
        ->get(route('profile.show', $other), ['referer' => url($from)])
        ->assertOk()
        ->getContent();

    expect(backArrowHref($html))->toBe($from);
})->with([
    'clan roster' => '/clans',
    'clan detail' => '/clans/1',
    'leaderboard' => '/leaderboard',
    'chat' => '/chat',
]);

it('keeps the query string of the origin page', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    // Losing ?mode=dm&with=x would return the visitor to a different chat than the one
    // they left.
    $html = actingAs($me)
        ->get(route('profile.show', $other), ['referer' => url('/chat?mode=dm&with=someone')])
        ->assertOk()
        ->getContent();

    expect(backArrowHref($html))->toBe('/chat?mode=dm&amp;with=someone');
});

it('falls back to friends when there is no referer', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $html = actingAs($me)
        ->get(route('profile.show', $other))
        ->assertOk()
        ->getContent();

    expect(backArrowHref($html))->toBe(route('friends.index'));
});

it('refuses an off-site referer rather than linking to it (open redirect)', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    $response = actingAs($me)
        ->get(route('profile.show', $other), ['referer' => 'https://evil.example.com/phish'])
        ->assertOk();

    // The Referer is client-controlled: echoing it into an href unchecked would turn
    // every profile page into a redirect to anywhere.
    $response->assertDontSee('evil.example.com');
    expect(backArrowHref($response->getContent()))->toBe(route('friends.index'));
});

it('does not point back at another profile page', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    // Hopping profile -> profile would otherwise make the arrow lead to the profile just
    // left, never back to the list the visitor started from.
    $html = actingAs($me)
        ->get(route('profile.show', $other), ['referer' => url('/users/someoneelse')])
        ->assertOk()
        ->getContent();

    expect(backArrowHref($html))->toBe(route('friends.index'));
});

it('renders no back arrow on your own profile', function () {
    $me = User::factory()->create();

    actingAs($me)
        ->get(route('profile.me'))
        ->assertOk()
        ->assertDontSee(__('profile.back'));
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
