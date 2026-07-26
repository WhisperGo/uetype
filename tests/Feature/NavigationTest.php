<?php

use App\Models\User;
use App\Support\NavItems;
use Illuminate\Support\Facades\Blade;

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

/**
 * ---- Geometri navbar ----
 *
 * PEST tak bisa mengukur piksel atau layout shift, jadi ketiga test di bawah mengunci
 * KONTRAK MARKUP yang menyebabkannya -- bukan hasil visualnya. Bukti visual sungguhan
 * hanya bisa lewat DevTools (Rendering -> Layout Shift Regions). Yang dijaga di sini
 * adalah supaya penyebabnya tak diam-diam kembali.
 */
it('reserves the logo space before the image loads', function () {
    // logo.png berukuran 6250x6250 (943 KB) tapi dirender pada h-12 dengan w-auto.
    // Tanpa atribut dimensi, browser tak tahu rasionya sampai file tiba: lebar 0 dulu,
    // lalu melompat ke 48px dan mendorong wordmark + seluruh grup menu ke kanan.
    // Dimensi INTRINSIK (bukan 48) yang dipakai, karena komponen ini dirender pada tiga
    // tinggi berbeda (h-12 di nav & guest, h-10 di halaman error) -- browser cuma perlu rasionya.
    $html = $this->actingAs(User::factory()->create())->get(route('typing'))->assertOk()->getContent();

    expect($html)->toContain('width="6250"')->toContain('height="6250"');
});

it('keeps the desktop nav links from stretching to the full bar height', function () {
    // `align-items: stretch` default membuat tiap <a> setinggi bar (66px) padahal
    // labelnya cuma 20px -- itu 23px area klik mati di atas dan bawah tiap kata.
    // `sm:-my-px` sisa warisan Breeze: dulu untuk menimpakan `border-b-2` item aktif
    // ke border bawah navbar, dan border itu sudah lama diganti warna + font-weight.
    //
    // Diperiksa pada HTML yang DIRENDER, bukan file sumbernya: komentar Blade dibuang
    // saat kompilasi, jadi nama kelas yang disebut di komentar penjelas tak ikut terhitung.
    $html = $this->actingAs(User::factory()->create())->get(route('typing'))->assertOk()->getContent();

    expect($html)->toContain('sm:items-center')->not->toContain('sm:-my-px');
});

it('keeps the desktop nav link padding symmetric and tight', function () {
    // `pt-1` (tanpa pasangan bawah) juga sisa Breeze -- ia menggeser label ~2px dari
    // titik tengah bar. Padding simetris yang kecil menjaga kotak klik dekat dengan
    // katanya, tapi tetap 32px sehingga masih di atas target sentuh minimum WCAG 2.5.8.
    //
    // Komponennya dirender SENDIRIAN, bukan lewat halaman: `pt-1` juga dipakai sah oleh
    // wordmark UETYPE dan label bahasa di dropdown, jadi memindai seluruh halaman akan
    // gagal karena alasan yang keliru.
    $html = Blade::render('<x-nav-link href="/x">Solo</x-nav-link>');

    expect($html)->toContain('py-1.5')->not->toContain('pt-1');
});

it('keeps the email out of the nav, including the mobile panel', function () {
    // The mobile hamburger printed the address under the username while the desktop
    // dropdown showed only username + level. A nav panel opens wherever the user happens
    // to be standing, so it is the wrong surface for an address; Settings and your own
    // profile already show it.
    $user = User::factory()->create(['email' => 'private-address@example.com']);

    $html = $this->actingAs($user)->get(route('typing'))->assertOk()->getContent();

    expect($html)->toContain($user->username)->not->toContain('private-address@example.com');
});
