<?php

use App\Services\KeystrokeAnalyzer;

/**
 * KeystrokeAnalyzer (§7.1): the one check that looks at the SHAPE of the typing, not its
 * magnitude. Human intervals are uneven; bots are uniform, impossibly fast, or a fixed
 * time.sleep. Fail-safe: an empty/short sample is "no data", never a rejection.
 */
beforeEach(function () {
    $this->analyzer = app(KeystrokeAnalyzer::class);
});

/** A plausible human sample: mean ~150ms with natural spread and occasional pauses. */
function humanIntervals(int $n = 120): array
{
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        // Base 120-200ms, plus the odd longer "thinking" pause -- uneven by construction.
        $out[] = ($i % 11 === 0) ? 420 : 120 + ($i * 37) % 90;
    }

    return $out;
}

it('accepts a natural, uneven human interval sample', function () {
    $r = $this->analyzer->analyze(humanIntervals(), 120);

    expect($r['has_data'])->toBeTrue()
        ->and($r['reasons'])->toBe([]);
});

it('flags a metronome: intervals all nearly identical', function () {
    // Every keystroke exactly 100ms apart -- coefficient of variation ~0.
    $r = $this->analyzer->analyze(array_fill(0, 120, 100), 120);

    expect($r['has_data'])->toBeTrue()
        ->and($r['reasons'])->toContain('keystroke_timing_uniform')
        ->and($r['reasons'])->toContain('keystroke_timing_identical');
});

it('flags a flood of physically impossible sub-40ms intervals', function () {
    // 80% of intervals faster than any human finger.
    $fast = array_merge(array_fill(0, 96, 15), array_fill(0, 24, 150));
    $r = $this->analyzer->analyze($fast, 120);

    expect($r['reasons'])->toContain('keystroke_timing_impossible');
});

it('treats an empty sample as no data, not cheating (fail-safe for old bundles)', function () {
    $r = $this->analyzer->analyze([], 0);

    expect($r['has_data'])->toBeFalse()
        ->and($r['reasons'])->toBe([]);
});

it('treats a too-short sample as no data', function () {
    $r = $this->analyzer->analyze([120, 140, 160], 3);

    expect($r['has_data'])->toBeFalse();
});

it('ignores garbage and idle spikes when judging', function () {
    // Trailing 9000ms idle + a negative + a non-numeric are dropped before analysis.
    $sample = array_merge(humanIntervals(), [9000, -5, 'x', 0]);
    $r = $this->analyzer->analyze($sample, 124);

    expect($r['has_data'])->toBeTrue()
        ->and($r['reasons'])->toBe([]);
});
