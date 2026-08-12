<?php

use App\Models\User;

/**
 * addExp() dulu berbentuk read-modify-write:
 *
 *     $this->total_xp += $xpEarned;
 *     $this->save();
 *
 * Nilai lama dibaca ke memori PHP, ditambah, lalu ditulis balik UTUH. Dua penulis
 * yang memegang instance model berbeda -- misalnya hasil solo yang tersimpan
 * hampir bersamaan dengan finalisasi balapan, dua jalur yang memang sama-sama
 * memanggil addExp() -- akan sama-sama membaca nilai awal, dan yang menulis
 * belakangan MENIMPA tambahan yang pertama. EXP hilang tanpa jejak: tak ada error,
 * tak ada log, angkanya cuma lebih kecil dari seharusnya.
 *
 * Test ini tak bisa membuktikan keamanan konkurensi sungguhan (butuh dua koneksi
 * paralel). Yang ia buktikan: penambahan dilakukan DI DATABASE, bukan dari nilai
 * yang sudah basi di memori -- dan itulah bedanya `increment()` dengan `+=`.
 */
it('does not lose xp when two stale model instances both add', function () {
    $user = User::factory()->create(['total_xp' => 100]);

    // Dua instance dari baris yang sama, keduanya memegang total_xp = 100.
    $a = User::find($user->id);
    $b = User::find($user->id);

    $xpA = $a->addExp(500, 100);
    $xpB = $b->addExp(500, 100);

    // Kalau penambahan memakai nilai basi di memori, yang kedua menimpa yang
    // pertama dan hasilnya 100 + $xpB saja.
    expect($user->fresh()->total_xp)->toBe(100 + $xpA + $xpB);
});

it('keeps the in-memory model in step with the row it just incremented', function () {
    $user = User::factory()->create(['total_xp' => 0]);

    $earned = $user->addExp(500, 100);

    // increment() menulis ke DB tapi TIDAK otomatis menyegarkan atribut yang sudah
    // ada di memori. Panel hasil membaca levelData() dari instance yang sama tepat
    // setelah addExp(), jadi kalau atributnya tertinggal, pemain melihat level &
    // progres lamanya -- seolah balapannya tak memberi EXP sama sekali.
    expect($user->total_xp)->toBe($earned)
        ->and($user->levelData()['total_xp'])->toBe($earned);
});

it('still returns the amount earned, not the running total', function () {
    $user = User::factory()->create(['total_xp' => 1000]);

    // Nilai balik dipakai sebagai "XP dari sesi ini" (disimpan ke room_members.xp_earned
    // dan ditampilkan di panel hasil), bukan total kumulatif.
    expect($user->addExp(500, 100))->toBeLessThan(1000);
});
