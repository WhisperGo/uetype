<?php

use Illuminate\Support\Facades\Blade;

/**
 * <x-header-link>: satu definisi untuk tautan sekunder di kepala halaman ("Leaderboard",
 * "Kembali ke Clan").
 *
 * Sebelumnya ada LIMA varian tulisan tangan di lima halaman -- empat ukuran font, tiga ukuran
 * chevron, hanya satu yang punya aria-label, dan tak satu pun punya focus ring. Yang di Clan
 * War bahkan tanpa padding sama sekali: kotak kliknya setinggi teks 12px, separuh dari minimum
 * 24px WCAG 2.5.8.
 */
it('memberi kotak klik 32px tanpa mengubah tinggi baris header', function () {
    $html = Blade::render('<x-header-link href="/x" wire:navigate>Leaderboard</x-header-link>');

    expect($html)
        // Kotak membesar...
        ->toContain('py-2.5')
        ->toContain('px-2')
        // ...tapi margin negatif membatalkannya, jadi baris header tak bergeser
        // (aturan mengikat di docs/design-system.md).
        ->toContain('-my-2.5')
        ->toContain('-mx-2')
        // Atribut pemanggil diteruskan lewat $attributes, bukan prop.
        ->toContain('href="/x"')
        ->toContain('wire:navigate');
});

it('tak pernah mengirim focus:outline-none tanpa cincin fokus yang terlihat', function () {
    $html = Blade::render('<x-header-link href="/x">Leaderboard</x-header-link>');

    expect($html)->toContain('focus:outline-none')
        ->and($html)->toContain('focus-visible:ring');
});

it('menggambar chevron hanya untuk tautan kembali, dan menyembunyikannya dari screen reader', function () {
    $back = Blade::render('<x-header-link back href="/x">Kembali</x-header-link>');
    $plain = Blade::render('<x-header-link href="/x">Leaderboard</x-header-link>');

    expect($back)->toContain('<svg')
        // Chevron cuma mengulang apa yang teksnya sudah katakan.
        ->and($back)->toContain('aria-hidden="true"')
        ->and($plain)->not->toContain('<svg');
});

it('menambahkan nama aksesibel hanya saat teks yang terlihat memang butuh', function () {
    $labelled = Blade::render('<x-header-link href="/x" label="Kembali ke papan peringkat">Leaderboard</x-header-link>');
    $plain = Blade::render('<x-header-link href="/x">Leaderboard</x-header-link>');

    expect($labelled)->toContain('aria-label="Kembali ke papan peringkat"')
        // Teks slot sudah jadi nama aksesibelnya; aria-label wajib hanya di kontrol tanpa teks.
        ->and($plain)->not->toContain('aria-label');
});

it('tak pernah dipakai tanpa teks -- kontrol ikon-saja itu wilayah x-icon-button', function () {
    // Tautan tanpa teks mendapat ukuran hanya dari glyph-nya: itu kasus 44px yang dijaga
    // TouchTargetTest, bukan kasus 32px komponen ini.
    foreach (bladeViews() as $path) {
        expect(tanpaKomentarBlade(file_get_contents($path)))
            ->not->toMatch('/<x-header-link\b[^>]*>\s*<\/x-header-link>/');
    }
});

it('tak menyisakan halaman yang menulis tautan header-nya sendiri', function () {
    $views = [
        'livewire/clan-war',
        'livewire/clan-leaderboard',
        'livewire/clan-show',
        'achievements/index',
    ];

    foreach ($views as $view) {
        expect(file_get_contents(resource_path("views/{$view}.blade.php")))
            ->toContain('<x-header-link');
    }

    // Panah profil ikon-saja: 44px, bukan 32px.
    expect(file_get_contents(resource_path('views/profile/show.blade.php')))
        ->toContain('<x-icon-button');
});

it('menjaga panah tetap di markup, bukan di dalam string terjemahan', function () {
    foreach (['en', 'id'] as $locale) {
        $clan = require base_path("lang/{$locale}/clan.php");

        // Panah literal di dalam terjemahan adalah tipografi yang tak bisa diterjemahkan --
        // dan dibacakan screen reader sebagai kata.
        expect($clan['back_to_clan'])->not->toContain('←')
            // Dua kunci untuk satu makna, bedanya cuma panah: yang duplikat dibuang.
            ->and($clan)->not->toHaveKey('back_to_clan_plain');
    }
});
