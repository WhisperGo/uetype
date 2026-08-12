<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| xpToReachLevel & levelForXp adalah fungsi statis MURNI (tak menyentuh DB):
| total_xp satu-satunya sumber kebenaran, level selalu diturunkan darinya.
| Keduanya harus saling invers di batas tiap level.
|--------------------------------------------------------------------------
*/

it('menempatkan level 1 di 0 XP', function () {
    expect(User::xpToReachLevel(1))->toBe(0)
        ->and(User::levelForXp(0))->toBe(1);
});

it('menjaga level minimum 1 meski XP negatif', function () {
    // Pengaman: total_xp tak seharusnya negatif, tapi rumus tak boleh menghasilkan level 0.
    expect(User::levelForXp(-500))->toBe(1);
});

it('menghitung ambang XP tiap level dengan rumus segitiga BASE*n(n-1)/2', function () {
    // BASE=100: level 2 butuh 100, level 3 butuh 300, level 4 butuh 600.
    expect(User::xpToReachLevel(2))->toBe(100)
        ->and(User::xpToReachLevel(3))->toBe(300)
        ->and(User::xpToReachLevel(4))->toBe(600);
});

it('membuat levelForXp benar-benar invers dari xpToReachLevel di tiap batas', function () {
    // Tepat di ambang sebuah level -> levelForXp mengembalikan level itu.
    foreach (range(1, 30) as $level) {
        $threshold = User::xpToReachLevel($level);

        expect(User::levelForXp($threshold))->toBe($level);
    }
});

it('tetap di level saat ini sampai ambang berikutnya benar-benar tercapai', function () {
    // 1 XP di bawah ambang level 3 (300) masih level 2; tepat 300 baru level 3.
    expect(User::levelForXp(299))->toBe(2)
        ->and(User::levelForXp(300))->toBe(3);
});

it('tak pernah turun level saat XP bertambah (monoton)', function () {
    $previous = 1;

    foreach (range(0, 5000, 50) as $xp) {
        $level = User::levelForXp($xp);

        expect($level)->toBeGreaterThanOrEqual($previous);

        $previous = $level;
    }
});
