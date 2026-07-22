<?php

use App\Models\User;
use App\Support\NavItems;

/**
 * The nav menu used to be written twice -- desktop and mobile -- each with its own
 * `:active` expression that had to be kept in sync by hand. The duplicates had already
 * drifted: leaderboard used route() on one side and url() on the other.
 */
it('provides one source for the main menu and the account menu', function () {
    $this->actingAs(User::factory()->create())->get(route('typing'));

    expect(collect(NavItems::main())->pluck('key')->all())
        ->toBe(['solo', 'multiplayer', 'klan', 'leaderboard'])
        ->and(collect(NavItems::account())->pluck('key')->all())
        ->toBe(['profile', 'achievements', 'stats', 'friends', 'chat', 'settings']);
});

it('marks the active menu according to the open page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('clans.index'));

    $klan = collect(NavItems::main())->firstWhere('key', 'klan');

    expect($klan['active'])->toBeTrue();
});

/** Clan war counts as part of Clan -- its menu must stay lit. */
it('lights the clan menu when on the clan war page', function () {
    $this->actingAs(User::factory()->create())->get(route('clan-war.index'));

    expect(collect(NavItems::main())->firstWhere('key', 'klan')['active'])->toBeTrue();
});

it('renders every destination on desktop AND mobile', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('typing'))->assertOk()->getContent();

    // Two occurrences = one for the desktop bar, one for the mobile menu.
    foreach (NavItems::account() as $item) {
        expect(substr_count($html, 'href="'.$item['href'].'"'))
            ->toBeGreaterThanOrEqual(2, "Menu {$item['key']} doesn't appear on both sides");
    }
});

it('uses the same href for leaderboard on both sides', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('typing'))->assertOk()->getContent();

    // Previously: route('leaderboard') on desktop, url('/leaderboard') on mobile.
    expect(substr_count($html, 'href="'.route('leaderboard').'"'))->toBeGreaterThanOrEqual(2);
});

it('leaves no commented-out menu markup', function () {
    $nav = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

    expect($nav)->not->toContain('soon</span>');
});
