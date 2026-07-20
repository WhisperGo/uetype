<?php

use App\Models\User;
use App\Support\NavItems;

/**
 * Menu navigasi dulu ditulis dua kali -- desktop dan mobile -- masing-masing
 * dengan ekspresi `:active` yang harus disinkronkan manual. Duplikasinya sudah
 * menyimpang: leaderboard memakai route() di satu sisi dan url() di sisi lain.
 */
it('menyediakan satu sumber untuk menu utama dan menu akun', function () {
    $this->actingAs(User::factory()->create())->get(route('typing'));

    expect(collect(NavItems::main())->pluck('key')->all())
        ->toBe(['solo', 'multiplayer', 'klan', 'leaderboard'])
        ->and(collect(NavItems::account())->pluck('key')->all())
        ->toBe(['profile', 'achievements', 'stats', 'friends', 'chat', 'settings']);
});

it('menandai menu aktif sesuai halaman yang dibuka', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('clans.index'));

    $klan = collect(NavItems::main())->firstWhere('key', 'klan');

    expect($klan['active'])->toBeTrue();
});

/** Clan war dianggap bagian dari Klan -- menunya harus tetap menyala. */
it('menyalakan menu klan saat berada di halaman clan war', function () {
    $this->actingAs(User::factory()->create())->get(route('clan-war.index'));

    expect(collect(NavItems::main())->firstWhere('key', 'klan')['active'])->toBeTrue();
});

it('merender setiap tujuan di desktop DAN mobile', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('typing'))->assertOk()->getContent();

    // Dua kemunculan = satu untuk bilah desktop, satu untuk menu mobile.
    foreach (NavItems::account() as $item) {
        expect(substr_count($html, 'href="'.$item['href'].'"'))
            ->toBeGreaterThanOrEqual(2, "Menu {$item['key']} tak muncul di kedua sisi");
    }
});

it('memakai href yang sama untuk leaderboard di kedua sisi', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('typing'))->assertOk()->getContent();

    // Dulu: route('leaderboard') di desktop, url('/leaderboard') di mobile.
    expect(substr_count($html, 'href="'.route('leaderboard').'"'))->toBeGreaterThanOrEqual(2);
});

it('tidak menyisakan markup menu yang dikomentari', function () {
    $nav = file_get_contents(resource_path('views/layouts/navigation.blade.php'));

    expect($nav)->not->toContain('soon</span>');
});
