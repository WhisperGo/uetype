<?php

use App\Models\TypingResult;
use App\Models\User;
use Livewire\Volt\Volt;

/** Helper: satu baris hasil ketik dengan angka yang masuk akal. */
function result(User $user, string $mode, string $config, float $wpm, float $accuracy = 95, ?float $duration = null): TypingResult
{
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm + 5,
        'accuracy' => $accuracy,
        'correct_chars' => 300,
        'incorrect_chars' => 10,
        'duration_seconds' => $duration ?? 30,
    ]);
}

/**
 * B1 -- Regresi: pola joinSub lama mencocokkan `tr.net_wpm = pb.best_score`, jadi
 * dua hasil dengan net_wpm IDENTIK menghasilkan DUA baris untuk user yang sama
 * (dan menggeser pemain lain keluar dari top 10).
 */
test('leaderboard tidak menduplikasi user yang punya dua hasil dengan wpm identik', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    $rival = User::factory()->create();

    // Dua hasil, net_wpm PERSIS SAMA -- inilah pemicu bug-nya.
    result($rival, 'time', '30', 85.00);
    result($rival, 'time', '30', 85.00);

    $rows = Volt::test('leaderboard')->get('leaderboard');

    expect($rows)->toHaveCount(1)
        ->and((int) $rows->first()->user_id)->toBe($rival->id);
});

test('leaderboard survival tidak menduplikasi durasi yang identik', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    $rival = User::factory()->create();

    result($rival, 'survival', 'medium', 70, duration: 120);
    result($rival, 'survival', 'medium', 70, duration: 120);

    $rows = Volt::test('leaderboard')
        ->call('setTab', 'survival')
        ->get('leaderboard');

    expect($rows)->toHaveCount(1);
});

test('leaderboard hanya menampilkan skor TERBAIK tiap user, bukan tiap percobaan', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    $rival = User::factory()->create();

    result($rival, 'time', '30', 60.00);
    result($rival, 'time', '30', 95.00);   // terbaik
    result($rival, 'time', '30', 71.00);

    $rows = Volt::test('leaderboard')->get('leaderboard');

    expect($rows)->toHaveCount(1)
        ->and((float) $rows->first()->score)->toBe(95.00);
});

test('leaderboard mengurutkan dari skor tertinggi', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    $lambat = User::factory()->create();
    $cepat = User::factory()->create();

    result($lambat, 'time', '30', 50.00);
    result($cepat, 'time', '30', 120.00);

    $rows = Volt::test('leaderboard')->get('leaderboard');

    expect((int) $rows->first()->user_id)->toBe($cepat->id);
});

/**
 * B2 -- Rank harus dihitung DI DATABASE (COUNT), bukan dengan menarik seluruh
 * tabel ke PHP lalu array_search. Test ini mengunci dua hal: hasilnya benar, DAN
 * jumlah query tak ikut tumbuh seiring jumlah user.
 */
test('userRank menghitung peringkat dengan benar', function () {
    $lawan = User::factory()->count(4)->create();
    foreach ($lawan as $i => $u) {
        result($u, 'time', '30', 100 + $i);   // 100, 101, 102, 103
    }

    $me = User::factory()->create();
    result($me, 'time', '30', 102.5);         // hanya kalah dari 103 -> rank 2
    $this->actingAs($me);

    expect(Volt::test('leaderboard')->get('userRank'))->toBe(2);
});

test('userRank mengembalikan Unranked kalau belum pernah main di mode itu', function () {
    $me = User::factory()->create();
    $this->actingAs($me);

    expect(Volt::test('leaderboard')->get('userRank'))->toBe('Unranked');
});

test('userRank memakai skor TERBAIK user, bukan yang terakhir', function () {
    $rival = User::factory()->create();
    result($rival, 'time', '30', 90);

    $me = User::factory()->create();
    result($me, 'time', '30', 150);   // terbaik -> harusnya rank 1
    result($me, 'time', '30', 40);    // percobaan buruk, tak boleh menurunkan rank
    $this->actingAs($me);

    expect(Volt::test('leaderboard')->get('userRank'))->toBe(1);
});

test('jumlah query leaderboard tidak tumbuh seiring jumlah user', function () {
    $me = User::factory()->create();
    result($me, 'time', '30', 80);
    $this->actingAs($me);

    $ukur = function () {
        return countQueries(function () {
            $c = Volt::test('leaderboard');
            $c->get('leaderboard');
            $c->get('userRank');
        });
    };

    $denganSedikitUser = $ukur();

    // Tambah 30 user lain yang juga punya rekor di mode yang sama.
    User::factory()->count(30)->create()->each(fn (User $u) => result($u, 'time', '30', rand(40, 130)));

    $denganBanyakUser = $ukur();

    // Query count HARUS konstan -- kalau ini gagal, ada N+1 (atau seluruh tabel
    // ditarik ke PHP) yang menskala dengan jumlah user.
    expect($denganBanyakUser)->toBe($denganSedikitUser);
});
