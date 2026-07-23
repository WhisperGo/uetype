<?php

use App\Models\User;

/**
 * Every account starts with is_admin = false, so this command is the only way to open
 * the monitoring dashboard for the first time.
 */
it('grants admin rights by email', function () {
    $user = User::factory()->create(['email' => 'someone@example.test']);

    $this->artisan('user:admin', ['email' => 'someone@example.test'])
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeTrue();
});

it('revokes admin rights with --revoke', function () {
    $user = User::factory()->admin()->create(['email' => 'admin@example.test']);

    $this->artisan('user:admin', ['email' => 'admin@example.test', '--revoke' => true])
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeFalse();
});

it('fails when the email matches no account', function () {
    $this->artisan('user:admin', ['email' => 'nobody@example.test'])
        ->assertExitCode(1);
});

it('is safe to run twice', function () {
    $user = User::factory()->admin()->create(['email' => 'admin@example.test']);

    $this->artisan('user:admin', ['email' => 'admin@example.test'])
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeTrue();
});
