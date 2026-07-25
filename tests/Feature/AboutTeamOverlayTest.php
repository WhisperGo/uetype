<?php

use App\Livewire\About;
use Livewire\Livewire;

/**
 * Kartu tim di /about bisa diklik untuk membuka overlay profil (satu <x-modal>
 * yang isinya diisi Alpine dari member terpilih). Bio diambil dari lang.
 */
it('renders the about page for guests', function () {
    $this->get('/about')->assertOk();
});

it('renders every team member name and role', function () {
    $response = Livewire::test(About::class);

    foreach (['Jessie La Vonna Sanjaya', 'Jason Wijaya', 'William Fernando Sukemi',
        'Imanuel Yusuf Setio Budi', 'Kevin Fernando'] as $name) {
        $response->assertSee($name);
    }

    // Default $escape=true: '&' ter-render sebagai '&amp;' di HTML.
    $response->assertSee('Product & Design')
        ->assertSee('QA & Testing');
});

it('makes each team card a button that opens the profile overlay', function () {
    $html = Livewire::test(About::class)->html();

    // Satu modal dipakai bersama, bukan satu per member.
    expect(substr_count($html, "'team-member'"))->toBeGreaterThan(1);
    expect(substr_count($html, 'x-on:open-modal.window'))->toBe(1);

    // Tiap kartu memicu open-modal.
    expect(substr_count($html, "\$dispatch('open-modal', 'team-member')"))->toBe(5);
});

it('embeds each localized bio into the card payload', function () {
    app()->setLocale('en');

    $html = Livewire::test(About::class)->html();

    // Bio EN ikut ter-render di payload x-on:click tiap kartu.
    expect($html)->toContain('one piece');      // jessie
    expect($html)->toContain('race conditions'); // kevin
});

it('uses the indonesian bio when the locale is id', function () {
    app()->setLocale('id');

    $html = Livewire::test(About::class)->html();

    expect($html)->toContain('terasa menyatu');        // jessie
    expect($html)->toContain('chat langsung');         // yusuf
    expect($html)->not->toContain('one piece');
});

it('never leaves a bio translation key unresolved', function () {
    foreach (['en', 'id'] as $locale) {
        app()->setLocale($locale);

        $html = Livewire::test(About::class)->html();

        expect($html)->not->toContain('about.bio.');
    }
});
