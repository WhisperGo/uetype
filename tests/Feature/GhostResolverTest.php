<?php

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\GhostResolver;

beforeEach(function () {
    $this->resolver = new GhostResolver;
});

/** Satu baris rekor dengan angka yang masuk akal. */
function ghostResult(User $user, string $mode, string $config, float $wpm): TypingResult
{
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm + 5,
        'accuracy' => 96,
        'correct_chars' => 300,
        'incorrect_chars' => 10,
        'duration_seconds' => 30,
    ]);
}

/**
 * Ghost adalah PACE yang dikejar, dan sebuah pace cuma bermakna melawan tes yang sama.
 * Dulu 'own' & 'friend' membaca users.highest_wpm -- satu angka lintas mode -- sehingga
 * balapan di time 120 dipacu oleh sprint time 15 yang tak mungkin dipertahankan dua menit.
 */
it('resolve own memakai rekor viewer untuk mode+config aktif', function () {
    // Rekor karier 150 (dari sprint time/15) tak boleh bocor ke pace time/30.
    $me = User::factory()->create(['highest_wpm' => 150]);
    ghostResult($me, 'time', '15', 150);
    ghostResult($me, 'time', '30', 88.5);

    $ghost = $this->resolver->resolve('own', null, 'time', '30', $me->id);

    expect($ghost)->not->toBeNull()
        ->and($ghost['type'])->toBe('own')
        ->and($ghost['wpm'])->toBe(88.5)
        ->and($ghost['label'])->toBe('Your Best');
});

it('resolve own mengembalikan null kalau belum punya rekor di config itu', function () {
    // Punya rekor karier & rekor di time/30, tapi belum pernah main time/60.
    $me = User::factory()->create(['highest_wpm' => 120]);
    ghostResult($me, 'time', '30', 120);

    expect($this->resolver->resolve('own', null, 'time', '60', $me->id))->toBeNull();
});

it('resolve friend memakai rekor teman untuk mode+config aktif', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create(['username' => 'kawan', 'highest_wpm' => 200]);
    ghostResult($friend, 'time', '30', 70);
    ghostResult($friend, 'time', '15', 200);

    $f = Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    $ghost = $this->resolver->resolve('friend', $f->id, 'time', '30', $me->id);

    expect($ghost['wpm'])->toBe(70.0)
        ->and($ghost['label'])->toBe('kawan');
});

it('resolve friend menolak friendship yang bukan milik viewer', function () {
    $me = User::factory()->create();
    $a = User::factory()->create();
    $b = User::factory()->create(['highest_wpm' => 999]);

    // Friendship antara dua orang LAIN.
    $f = Friendship::create([
        'requester_id' => $a->id,
        'addressee_id' => $b->id,
        'status' => FriendshipStatus::Accepted,
    ]);

    expect($this->resolver->resolve('friend', $f->id, 'time', '30', $me->id))->toBeNull();
});

it('resolve friend menolak friendship yang belum accepted', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create(['highest_wpm' => 80]);

    $f = Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $friend->id,
        'status' => FriendshipStatus::Pending,
    ]);

    expect($this->resolver->resolve('friend', $f->id, 'time', '30', $me->id))->toBeNull();
});

it('resolve leaderboard menurunkan MAX(net_wpm) untuk mode/config', function () {
    $target = User::factory()->create(['username' => 'juara']);
    $viewer = User::factory()->create();

    ghostResult($target, 'time', '30', 100);
    ghostResult($target, 'time', '30', 130);   // terbaik utk time/30
    ghostResult($target, 'time', '60', 90);    // config lain, tak dipakai

    $ghost = $this->resolver->resolve('leaderboard', $target->id, 'time', '30', $viewer->id);

    expect($ghost['wpm'])->toBe(130.0)
        ->and($ghost['label'])->toBe('juara');
});

it('resolve leaderboard mengikuti config saat ini', function () {
    $target = User::factory()->create();
    $viewer = User::factory()->create();

    ghostResult($target, 'time', '30', 130);
    // Tak ada rekor time/60 -> resolve untuk time/60 harus null (fail-safe).

    expect($this->resolver->resolve('leaderboard', $target->id, 'time', '60', $viewer->id))->toBeNull();
});

it('resolve type tak dikenal mengembalikan null', function () {
    $me = User::factory()->create(['highest_wpm' => 90]);

    expect($this->resolver->resolve('bogus', null, 'time', '30', $me->id))->toBeNull();
});
