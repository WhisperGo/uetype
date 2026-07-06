<?php

use App\Support\AppTime;
use Carbon\Carbon;

test('it converts a utc time to jakarta time (+7)', function () {
    $utc = Carbon::parse('2026-07-06 02:30:00', 'UTC');

    expect(AppTime::format($utc, 'd M Y H:i'))->toBe('06 Jul 2026 09:30');
});

test('it returns a dash for a null date', function () {
    expect(AppTime::format(null, 'd M Y H:i'))->toBe('-');
});

test('it does not mutate the original instance', function () {
    $utc = Carbon::parse('2026-07-06 02:30:00', 'UTC');

    AppTime::format($utc, 'd M Y H:i');

    expect($utc->format('H:i'))->toBe('02:30')
        ->and($utc->timezone->getName())->toBe('UTC');
});
