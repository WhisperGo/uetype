<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Livewire\Clans;
use App\Livewire\Settings;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;
use Livewire\Livewire;

test('the settings page requires authentication', function () {
    $this->get(route('settings'))->assertRedirect(route('login'));
});

test('the settings page renders for an authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings'))->assertOk();
});

test('a user can update their username', function () {
    $user = User::factory()->create(['username' => 'oldname']);
    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('username', 'newname')
        ->call('saveUsername')
        ->assertHasNoErrors()
        ->assertDispatched('username-saved');

    expect($user->fresh()->username)->toBe('newname');
});

test('a username that is already taken is rejected', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create(['username' => 'mine']);
    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('username', 'taken')
        ->call('saveUsername')
        ->assertHasErrors('username');

    expect($user->fresh()->username)->toBe('mine');
});

test('a username with invalid characters is rejected', function () {
    $user = User::factory()->create(['username' => 'mine']);
    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('username', 'bad name!')
        ->call('saveUsername')
        ->assertHasErrors('username');
});

test('setLocale persists the choice to user preferences', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(Settings::class)->call('setLocale', 'id');

    expect($user->fresh()->preferences['locale'])->toBe('id');
});

test('setLocale ignores an unsupported locale', function () {
    $user = User::factory()->create(['preferences' => ['locale' => 'en']]);
    $this->actingAs($user);

    Livewire::test(Settings::class)->call('setLocale', 'zz');

    expect($user->fresh()->preferences['locale'])->toBe('en');
});

test('deleteAccount rejects a mismatched username confirmation', function () {
    $user = User::factory()->create(['username' => 'mine']);
    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('confirmUsername', 'wrong')
        ->call('deleteAccount')
        ->assertHasErrors('confirmUsername');

    expect(User::find($user->id))->not->toBeNull();
});

test('deleteAccount removes the account when the username matches', function () {
    $user = User::factory()->create(['username' => 'mine']);
    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('confirmUsername', 'mine')
        ->call('deleteAccount');

    expect(User::find($user->id))->toBeNull();
    $this->assertGuest();
});

// ---- CLAN LEADER GUARD ----
//
// clans.leader_id cascades on user delete, so deleting a leader's account used to take
// the entire clan with it -- every membership row, and every member's clan, gone as a
// side effect of one person leaving.

/** A clan led by $leader, with one extra active member. */
function clanLedBy(User $leader, string $name = 'Guarded'): Clan
{
    $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => 1000]);

    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $leader->id,
        'role' => ClanRole::Leader,
        'status' => ClanMemberStatus::Active,
    ]);

    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => User::factory()->create()->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Active,
    ]);

    return $clan;
}

test('deleteAccount refuses a clan leader, leaving the clan and its members intact', function () {
    $leader = User::factory()->create(['username' => 'chief']);
    $clan = clanLedBy($leader);
    $this->actingAs($leader);

    Livewire::test(Settings::class)
        ->set('confirmUsername', 'chief')
        ->call('deleteAccount')
        ->assertHasErrors('confirmUsername');

    expect(User::find($leader->id))->not->toBeNull();
    expect(Clan::find($clan->id))->not->toBeNull();
    expect(ClanMember::where('clan_id', $clan->id)->count())->toBe(2);
    $this->assertAuthenticated();
});

test('deleteAccount allows a co-leader and a plain member through', function (ClanRole $role) {
    $leader = User::factory()->create();
    $clan = clanLedBy($leader, 'Open '.$role->value);

    $user = User::factory()->create(['username' => 'leaving']);
    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => ClanMemberStatus::Active,
    ]);

    $this->actingAs($user);

    Livewire::test(Settings::class)
        ->set('confirmUsername', 'leaving')
        ->call('deleteAccount');

    // Only the leader is blocked -- nobody else's departure endangers the clan.
    expect(User::find($user->id))->toBeNull();
    expect(Clan::find($clan->id))->not->toBeNull();
})->with([
    'co-leader' => ClanRole::CoLeader,
    'member' => ClanRole::Member,
]);

test('deleteAccount succeeds once leadership has been transferred away', function () {
    $leader = User::factory()->create(['username' => 'chief']);
    $clan = clanLedBy($leader, 'Handover');

    $heir = ClanMember::where('clan_id', $clan->id)
        ->where('user_id', '!=', $leader->id)
        ->first();

    Livewire::actingAs($leader)
        ->test(Clans::class)
        ->call('transferLeadership', $heir->id);

    $this->actingAs($leader);

    Livewire::test(Settings::class)
        ->set('confirmUsername', 'chief')
        ->call('deleteAccount');

    // The guard points at transfer as the way out, so that route must actually work.
    expect(User::find($leader->id))->toBeNull();
    expect(Clan::find($clan->id))->not->toBeNull();
});

test('the delete modal warns a leader instead of offering the confirm field', function () {
    $leader = User::factory()->create(['username' => 'chief']);
    clanLedBy($leader, 'Warned');
    $this->actingAs($leader);

    Livewire::test(Settings::class)
        ->assertSee('Warned')
        ->assertDontSee(__('settings.danger.confirm_body'));
});
