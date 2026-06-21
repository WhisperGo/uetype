<?php

use App\Services\AntiCheatService;

beforeEach(function () {
    $this->service = new AntiCheatService();
});

it('accepts a normal human session and recomputes wpm from chars and duration', function () {
    // 300 karakter benar dari 310 total dalam 60 detik → Net 60 WPM, Raw 62 WPM.
    $result = $this->service->check(correctChars: 300, totalChars: 310, durationSeconds: 60.0);

    expect($result['valid'])->toBeTrue()
        ->and($result['net_wpm'])->toBe(60.0)
        ->and($result['raw_wpm'])->toBe(62.0)
        ->and($result['accuracy'])->toBe(96.77)
        ->and($result['reasons'])->toBeEmpty();
});

it('rejects sessions shorter than the minimum duration', function () {
    $result = $this->service->check(correctChars: 50, totalChars: 50, durationSeconds: 0.5);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('duration_too_short');
});

it('rejects superhuman wpm', function () {
    // 2000 karakter dalam 5 detik → 4800 WPM.
    $result = $this->service->check(correctChars: 2000, totalChars: 2000, durationSeconds: 5.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('wpm_too_high');
});

it('rejects inconsistent char counts (correct greater than total)', function () {
    $result = $this->service->check(correctChars: 100, totalChars: 80, durationSeconds: 30.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('char_count_inconsistent');
});

it('rejects a session with no input', function () {
    $result = $this->service->check(correctChars: 0, totalChars: 0, durationSeconds: 30.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('no_input');
});

it('rejects huge-duration tiny-input sessions (survival leaderboard cheat vector)', function () {
    // Vektor cheat survival: klaim bertahan 600 detik dengan hanya 10 karakter
    // untuk menjuarai papan durasi. Throughput 0.017 cps << ambang 0.5 cps.
    $result = $this->service->check(correctChars: 10, totalChars: 10, durationSeconds: 600.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('throughput_too_low');
});

it('does not flag throughput for a fast short burst', function () {
    // Sesi pendek wajar (5 detik, 40 karakter = 8 cps) tak boleh kena ambang throughput.
    $result = $this->service->check(correctChars: 40, totalChars: 40, durationSeconds: 5.0);

    expect($result['valid'])->toBeTrue()
        ->and($result['reasons'])->not->toContain('throughput_too_low');
});
