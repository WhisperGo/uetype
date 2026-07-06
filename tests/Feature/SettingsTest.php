<?php

use App\Livewire\Settings;
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
