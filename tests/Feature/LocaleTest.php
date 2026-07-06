<?php

use App\Models\User;

test('guest switching to a supported locale stores it in the session', function () {
    $response = $this->from('/typing')->post(route('locale.update'), ['locale' => 'id']);

    $response->assertRedirect('/typing');
    expect(session('locale'))->toBe('id');
});

test('an unsupported locale is ignored and does not change the session', function () {
    session(['locale' => 'en']);

    $this->from('/typing')->post(route('locale.update'), ['locale' => 'zz']);

    expect(session('locale'))->toBe('en');
});

test('a logged-in user has their locale persisted to preferences', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'id']);

    expect($user->fresh()->preferences['locale'])->toBe('id');
    expect(session('locale'))->toBe('id');
});

test('user preferences take precedence over the session locale', function () {
    $user = User::factory()->create(['preferences' => ['locale' => 'id']]);

    session(['locale' => 'en']);

    $response = $this->actingAs($user)->get('/about');

    $response->assertOk();
    expect(app()->getLocale())->toBe('id');
});

test('the default locale is english when nothing is set', function () {
    $response = $this->get('/about');

    $response->assertOk();
    expect(app()->getLocale())->toBe('en');
});
