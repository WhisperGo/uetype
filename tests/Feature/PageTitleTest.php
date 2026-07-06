<?php

use App\Support\PageTitle;

test('a known route gets a page title with the brand suffix', function () {
    app()->setLocale('en');

    expect(PageTitle::forRoute('leaderboard'))->toBe('Leaderboard | UEType')
        ->and(PageTitle::forRoute('settings'))->toBe('Settings | UEType');
});

test('the home route falls back to the brand only', function () {
    app()->setLocale('en');

    expect(PageTitle::forRoute('home'))->toBe('UEType')
        ->and(PageTitle::forRoute('typing'))->toBe('UEType');
});

test('an unknown or null route falls back to the brand only', function () {
    app()->setLocale('en');

    expect(PageTitle::forRoute(null))->toBe('UEType')
        ->and(PageTitle::forRoute('does.not.exist'))->toBe('UEType');
});

test('the page title is localized to the active locale', function () {
    app()->setLocale('id');

    expect(PageTitle::forRoute('leaderboard'))->toBe('Papan Peringkat | UEType');
});

test('the home page renders the brand title', function () {
    $this->get('/')->assertSee('<title>UEType</title>', false);
});

test('a livewire full-page renders its localized title', function () {
    $this->get('/about')->assertSee('<title>About | UEType</title>', false);
});
