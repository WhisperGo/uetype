<?php

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\User;

/**
 * Unique index di DB hanya `(requester_id, addressee_id)` -- satu arah. Artinya
 * A->B dan B->A bisa hidup berdampingan sebagai dua baris pending yang redundan,
 * dan DB tak akan menghalanginya.
 *
 * Pencegahannya ada di aplikasi, dan dulu disalin di TIGA tempat (FriendButton,
 * Friends, leaderboard) sebagai "cek friendshipWith() lalu create()". Sekarang
 * satu pintu: Friendship::requestBetween().
 *
 * Yang test ini TIDAK klaim: keamanan terhadap dua request yang benar-benar
 * paralel. Itu butuh unique index atas pasangan yang dinormalisasi; lihat catatan
 * di requestBetween().
 */
it('refuses a second request in the opposite direction', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    expect(Friendship::requestBetween($a->id, $b->id))->not->toBeNull();

    // Arah sebaliknya: relasinya sudah ada, jadi tak boleh melahirkan baris kedua.
    expect(Friendship::requestBetween($b->id, $a->id))->toBeNull()
        ->and(Friendship::count())->toBe(1);
});

it('refuses a duplicate request in the same direction', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    Friendship::requestBetween($a->id, $b->id);

    expect(Friendship::requestBetween($a->id, $b->id))->toBeNull()
        ->and(Friendship::count())->toBe(1);
});

it('refuses a request to yourself', function () {
    $a = User::factory()->create();

    expect(Friendship::requestBetween($a->id, $a->id))->toBeNull()
        ->and(Friendship::count())->toBe(0);
});

it('refuses a new request when the two are already friends', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    Friendship::create([
        'requester_id' => $a->id,
        'addressee_id' => $b->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    // Sudah berteman -> "tambah teman" lagi tak boleh membuat baris pending baru
    // yang akan muncul sebagai permintaan hantu di UI kedua belah pihak.
    expect(Friendship::requestBetween($b->id, $a->id))->toBeNull()
        ->and(Friendship::count())->toBe(1);
});

it('creates a pending request between two unrelated users', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $friendship = Friendship::requestBetween($a->id, $b->id);

    expect($friendship->requester_id)->toBe($a->id)
        ->and($friendship->addressee_id)->toBe($b->id)
        ->and($friendship->status)->toBe(FriendshipStatus::Pending);
});
