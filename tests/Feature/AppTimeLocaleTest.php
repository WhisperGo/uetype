<?php

use App\Support\AppTime;
use Carbon\Carbon;

test('it localizes month names to the active ui locale', function () {
    $utc = Carbon::parse('2026-01-15 00:00:00', 'UTC');

    app()->setLocale('id');
    expect(AppTime::format($utc, 'F'))->toBe('Januari');

    app()->setLocale('en');
    expect(AppTime::format($utc, 'F'))->toBe('January');
});
