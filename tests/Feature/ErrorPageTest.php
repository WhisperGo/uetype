<?php

/**
 * Custom themed error pages (resources/views/errors/*). Laravel picks these up
 * automatically by HTTP status. We verify the 404 renders our branded page
 * (code + localized copy + back-home link) rather than the plain default, and
 * that each error view compiles and shows its status code.
 */
it('renders the custom themed 404 page for an unknown url', function () {
    $response = $this->get('/this-route-does-not-exist');

    $response->assertNotFound()
        ->assertSee('404')
        ->assertSee('Page not found')
        ->assertSee('Back to home')
        ->assertSee(route('home'));
});

it('localizes the 404 page to the active locale', function () {
    app()->setLocale('id');

    $this->get('/nope-not-here')
        ->assertNotFound()
        ->assertSee('Halaman tidak ditemukan')
        ->assertSee('Kembali ke beranda');
});

it('renders each error view with its status code', function () {
    foreach (['404', '403', '419', '500', '503'] as $code) {
        $html = view("errors.{$code}")->render();
        expect($html)->toContain($code)
            ->and($html)->toContain('UETYPE'); // shared branded layout is in use
    }
});

it('omits the back-home button on the maintenance (503) page', function () {
    // The whole app is down during 503, so a home link would just fail.
    $html = view('errors.503')->render();

    expect($html)->not->toContain(__('errors.back_home'));
});
