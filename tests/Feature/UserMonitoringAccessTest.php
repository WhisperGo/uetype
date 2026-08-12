<?php

use App\Models\User;
use App\Support\NavItems;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * The monitoring dashboard exposes every user's IP, browser and page history, and its
 * DELETE routes can wipe the audit trail. It used to be registered by the package's own
 * route provider with only web + visit-monitoring middleware, so it was fully public.
 * These tests lock it to admins.
 */
$pages = [
    'user-monitoring.visits-monitoring',
    'user-monitoring.actions-monitoring',
    'user-monitoring.authentications-monitoring',
];

$deletes = [
    'user-monitoring.visits-monitoring-delete',
    'user-monitoring.actions-monitoring-delete',
    'user-monitoring.authentications-monitoring-delete',
];

it('hides every dashboard page from guests', function (string $route) {
    $this->get(route($route))->assertNotFound();
})->with($pages);

it('hides every dashboard page from signed-in non-admins', function (string $route) {
    actingAs(User::factory()->create())->get(route($route))->assertNotFound();
})->with($pages);

it('opens every dashboard page for admins', function (string $route) {
    actingAs(User::factory()->admin()->create())->get(route($route))->assertOk();
})->with($pages);

/** The delete routes are the dangerous half -- testing only GET would leave them open. */
it('blocks record deletion for guests', function (string $route) {
    $this->delete(route($route, 1))->assertNotFound();
})->with($deletes);

it('blocks record deletion for non-admins', function (string $route) {
    actingAs(User::factory()->create())->delete(route($route, 1))->assertNotFound();
})->with($deletes);

/** 404, not 403: a 403 would confirm the panel exists. */
it('returns 404 rather than 403 so the panel is not discoverable', function () {
    actingAs(User::factory()->create())
        ->get(route('user-monitoring.visits-monitoring'))
        ->assertStatus(404);
});

/**
 * The vendor controllers paginate without eager loading `user`, while AppServiceProvider
 * turns lazy loading into an exception outside production. Rendering the username column
 * therefore threw LazyLoadingViolationException on a populated table.
 *
 * Each table gets TWO rows on purpose: Laravel exempts a single-model result from the
 * guard (one extra query is not an N+1), so a one-row fixture would pass even unfixed.
 */
it('renders rows that belong to a user without a lazy loading violation', function () {
    $admin = User::factory()->admin()->create();
    $subject = User::factory()->create(['username' => 'WatchedTyper']);
    $other = User::factory()->create(['username' => 'SecondTyper']);

    foreach ([$subject, $other] as $who) {
        DB::table('visits_monitoring')->insert([
            'user_id' => $who->id,
            'browser_name' => 'Chrome',
            'platform' => 'Windows',
            'device' => 'Windows',
            'ip' => '127.0.0.1',
            'page' => 'http://localhost/typing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('actions_monitoring')->insert([
            'user_id' => $who->id,
            'action_type' => 'store',
            'table_name' => 'messages',
            'browser_name' => 'Chrome',
            'platform' => 'Windows',
            'device' => 'Windows',
            'ip' => '127.0.0.1',
            'page' => 'http://localhost/chat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('authentications_monitoring')->insert([
            'user_id' => $who->id,
            'action_type' => 'login',
            'browser_name' => 'Chrome',
            'platform' => 'Windows',
            'device' => 'Windows',
            'ip' => '127.0.0.1',
            'page' => 'http://localhost/login',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    foreach (['visits', 'actions', 'authentications'] as $tab) {
        actingAs($admin)
            ->get(route("user-monitoring.{$tab}-monitoring"))
            ->assertOk()
            ->assertSee('WatchedTyper')
            ->assertSee('SecondTyper');
    }
});

/** user_id is nullable: guest visits must fall back to the "Guest" label, not blow up. */
it('renders guest rows that have no user', function () {
    DB::table('visits_monitoring')->insert([
        'user_id' => null,
        'browser_name' => 'Firefox',
        'platform' => 'Linux',
        'device' => 'Linux',
        'ip' => '127.0.0.1',
        'page' => 'http://localhost/typing',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    actingAs(User::factory()->admin()->create())
        ->get(route('user-monitoring.visits-monitoring'))
        ->assertOk()
        ->assertSee(__('monitoring.guest'));
});

it('leaves the monitoring link out of the account menu for regular users', function () {
    actingAs(User::factory()->create())->get(route('typing'));

    expect(collect(NavItems::account())->pluck('key')->all())
        ->not->toContain('monitoring');
});

it('shows the monitoring link in the account menu for admins', function () {
    actingAs(User::factory()->admin()->create())->get(route('typing'));

    $item = collect(NavItems::account())->firstWhere('key', 'monitoring');

    expect($item)->not->toBeNull()
        ->and($item['href'])->toBe(route('user-monitoring.visits-monitoring'));
});
