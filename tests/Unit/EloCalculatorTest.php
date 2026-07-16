<?php

use App\Services\EloCalculator;

it('is zero-sum: delta A selalu berlawanan dengan delta B', function () {
    [$deltaA, $deltaB] = EloCalculator::calculate(1200, 1000, 1.0);

    expect($deltaA)->toBe(-$deltaB);
});

it('memberi delta nol saat dua clan sama kuat berakhir seri', function () {
    // Power sama -> expected 0.5; seri (scoreA 0.5) -> tak ada perpindahan poin.
    [$deltaA, $deltaB] = EloCalculator::calculate(1200, 1200, 0.5);

    expect($deltaA)->toBe(0)
        ->and($deltaB)->toBe(0);
});

it('menambah poin ke pemenang dan mengurangi dari yang kalah', function () {
    [$deltaA, $deltaB] = EloCalculator::calculate(1200, 1200, 1.0);

    expect($deltaA)->toBeGreaterThan(0)
        ->and($deltaB)->toBeLessThan(0);
});

it('memberi poin lebih banyak saat menang melawan clan yang lebih kuat', function () {
    // Menang sebagai underdog (1000 vs 1400) harus lebih berharga daripada
    // menang sebagai favorit (1400 vs 1000).
    [$menangLawanKuat] = EloCalculator::calculate(1000, 1400, 1.0);
    [$menangLawanLemah] = EloCalculator::calculate(1400, 1000, 1.0);

    expect($menangLawanKuat)->toBeGreaterThan($menangLawanLemah);
});

it('tak pernah melampaui K-factor untuk satu pertandingan', function () {
    // Delta maksimum = K x (1 - expected). Bahkan underdog ekstrem yang menang
    // tak boleh mendapat lebih dari K_FACTOR poin.
    [$deltaA] = EloCalculator::calculate(1, 3000, 1.0);

    expect($deltaA)->toBeLessThanOrEqual(EloCalculator::K_FACTOR);
});

it('menghukum favorit yang kalah lebih berat daripada underdog yang kalah', function () {
    // Favorit (diharapkan menang) yang kalah kehilangan lebih banyak poin
    // daripada underdog (diharapkan kalah) yang memang kalah.
    [$favoritKalah] = EloCalculator::calculate(1400, 1000, 0.0);
    [$underdogKalah] = EloCalculator::calculate(1000, 1400, 0.0);

    expect($favoritKalah)->toBeLessThan($underdogKalah);
});

it('menghitung delta seri sebagai bilangan bulat', function () {
    [$deltaA, $deltaB] = EloCalculator::calculate(1300, 1100, 0.5);

    expect($deltaA)->toBeInt()
        ->and($deltaB)->toBeInt();
});
