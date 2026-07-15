<?php

use App\Support\PageTitle;

test('a known route gets a page title with the brand suffix', function () {
    app()->setLocale('en');

    expect(PageTitle::forRoute('leaderboard'))->toBe('Leaderboard | UeType')
        ->and(PageTitle::forRoute('settings'))->toBe('Settings | UeType');
});

test('the home route falls back to the brand only', function () {
    app()->setLocale('en');

    expect(PageTitle::forRoute('home'))->toBe('UeType')
        ->and(PageTitle::forRoute('typing'))->toBe('UeType');
});

test('an unknown or null route falls back to the brand only', function () {
    app()->setLocale('en');

    expect(PageTitle::forRoute(null))->toBe('UeType')
        ->and(PageTitle::forRoute('does.not.exist'))->toBe('UeType');
});

test('the page title is localized to the active locale', function () {
    app()->setLocale('id');

    expect(PageTitle::forRoute('leaderboard'))->toBe('Papan Peringkat | UeType');
});

test('the home page renders the brand title', function () {
    $this->get('/')->assertSee('<title>UeType</title>', false);
});

test('a livewire full-page renders its localized title', function () {
    $this->get('/about')->assertSee('<title>About | UeType</title>', false);
});
