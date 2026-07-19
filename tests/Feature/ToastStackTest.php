<?php

use App\Models\User;

/**
 * Notifikasi teman, clan, dan chat dulu punya container toast SENDIRI-SENDIRI --
 * ketiganya di `fixed bottom-5 right-5 z-[60]` yang sama persis. Dua notifikasi
 * yang datang bersamaan saling menimpa alih-alih menumpuk. Test ini mengunci
 * bahwa hanya ada SATU tumpukan, supaya bug itu tak kembali lewat penambahan
 * jenis notifikasi baru.
 */
it('merender tepat satu tumpukan toast untuk user login', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('friends.index'))->assertOk()->getContent();

    expect(substr_count($html, 'toastStack('))->toBe(1);
});

it('tidak merender tumpukan toast untuk tamu', function () {
    $html = $this->get(route('typing'))->assertOk()->getContent();

    expect($html)->not->toContain('toastStack(');
});

/**
 * Toast harus hidup di luar $slot supaya bertahan lintas wire:navigate --
 * pemain bisa sedang mengetik atau balapan saat notifikasinya masuk.
 */
it('menyediakan tumpukan toast di semua halaman, bukan hanya halaman sosial', function () {
    $user = User::factory()->create();

    foreach ([route('typing'), route('stats'), route('clans.index')] as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

        expect($html)->toContain('toastStack(');
    }
});

/** Logikanya harus di modul, bukan tersalin ulang sebagai script inline. */
it('menaruh logika toast di modul js, bukan di dalam blade', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->not->toContain('friendToasts')
        ->and($layout)->not->toContain('clanToasts')
        ->and($layout)->not->toContain("Alpine.data('chatToasts'");

    expect(file_exists(resource_path('js/toasts.js')))->toBeTrue();
});
