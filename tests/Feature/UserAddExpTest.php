<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| addExp() mengakumulasi total_xp lalu menyimpan (butuh DB), jadi test-nya
| di Feature. Rumusnya: round(correctChars * 0.1 * (0.5 + 0.5*acc/100)).
| Berbasis VOLUME karakter benar, bukan WPM -- menghargai latihan, bukan bakat.
|--------------------------------------------------------------------------
*/

it('memberi XP penuh pada akurasi 100%', function () {
    // 1000 char x 0.1 x (0.5 + 0.5) = 100 XP.
    $user = User::factory()->create(['total_xp' => 0]);

    $earned = $user->addExp(correctChars: 1000, accuracy: 100.0);

    expect($earned)->toBe(100)
        ->and($user->fresh()->total_xp)->toBe(100);
});

it('memberi separuh XP pada akurasi 0% (bonus akurasi mulai dari 0.5x)', function () {
    // 1000 char x 0.1 x (0.5 + 0) = 50 XP.
    $user = User::factory()->create(['total_xp' => 0]);

    expect($user->addExp(correctChars: 1000, accuracy: 0.0))->toBe(50);
});

it('mengakumulasi XP lintas beberapa sesi', function () {
    $user = User::factory()->create(['total_xp' => 0]);

    $user->addExp(correctChars: 1000, accuracy: 100.0); // +100
    $user->addExp(correctChars: 500, accuracy: 100.0);  // +50

    expect($user->fresh()->total_xp)->toBe(150);
});

it('tak pernah memberi XP negatif dari input liar', function () {
    $user = User::factory()->create(['total_xp' => 10]);

    // correctChars negatif dijepit ke 0 -> 0 XP; total_xp tak berkurang.
    expect($user->addExp(correctChars: -999, accuracy: 100.0))->toBe(0)
        ->and($user->fresh()->total_xp)->toBe(10);
});

it('menjepit akurasi di atas 100 ke maksimum 1.0x', function () {
    $user = User::factory()->create(['total_xp' => 0]);

    // Akurasi 150 (mustahil) diperlakukan sebagai 100 -> XP penuh, tak lebih.
    expect($user->addExp(correctChars: 1000, accuracy: 150.0))->toBe(100);
});
