<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\FriendshipStatus;
use App\Livewire\Chat;
use App\Livewire\Clans;
use App\Livewire\Friends;
use App\Livewire\Stats;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

/**
 * Budget query per halaman.
 *
 * Ini BUKAN sekadar alat ukur sekali pakai. Test fungsional biasa tetap hijau
 * walau sebuah halaman menembak 500 query -- selama outputnya benar. Budget di
 * sini yang menangkapnya: begitu ada yang menambahkan lagi query di dalam loop,
 * test ini merah, jauh sebelum sampai ke produksi.
 *
 * Angkanya sengaja dipatok pada jumlah yang KONSTAN (tak tumbuh seiring jumlah
 * teman/clan/hasil), karena itulah inti perbedaan antara query yang di-batch dan
 * N+1. Kalau suatu fitur memang butuh query tambahan, naikkan angkanya SADAR --
 * jangan hapus test-nya.
 */

/** User dengan $n teman (accepted), masing-masing pernah bertukar pesan. */
function userWithFriends(int $n): User
{
    $me = User::factory()->create();

    User::factory()->count($n)->create()->each(function (User $friend) use ($me) {
        Friendship::create([
            'requester_id' => $me->id,
            'addressee_id' => $friend->id,
            'status' => FriendshipStatus::Accepted,
        ]);

        Message::create([
            'sender_id' => $friend->id,
            'recipient_id' => $me->id,
            'body' => 'halo',
        ]);
    });

    return $me;
}

test('inbox chat tidak menembak query per teman', function () {
    $me = userWithFriends(3);

    $sedikit = countQueries(fn () => Livewire::actingAs($me)->test(Chat::class));

    // Tambah 15 teman lagi (total 18) -- jumlah query TIDAK boleh ikut naik.
    User::factory()->count(15)->create()->each(function (User $friend) use ($me) {
        Friendship::create([
            'requester_id' => $me->id,
            'addressee_id' => $friend->id,
            'status' => FriendshipStatus::Accepted,
        ]);
        Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'halo']);
    });

    $banyak = countQueries(fn () => Livewire::actingAs($me)->test(Chat::class));

    expect($banyak)->toBe($sedikit);
});

/**
 * ChatOverlay dirender di LAYOUT GLOBAL, jadi N+1 di sini dibayar oleh setiap
 * halaman situs -- bukan cuma /chat. Ini yang membuat /profile dulu menembak
 * 3 query per teman padahal sama sekali tak berurusan dengan chat.
 */
test('overlay chat global tidak menembak query per teman', function () {
    $me = userWithFriends(3);

    $sedikit = countQueries(fn () => $this->actingAs($me)->get(route('profile.me')));

    User::factory()->count(15)->create()->each(function (User $friend) use ($me) {
        Friendship::create([
            'requester_id' => $me->id,
            'addressee_id' => $friend->id,
            'status' => FriendshipStatus::Accepted,
        ]);
        Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'halo']);
    });

    $banyak = countQueries(fn () => $this->actingAs($me)->get(route('profile.me')));

    // Request HTTP penuh boleh beda 1-2 query (session/visit-monitoring pada request
    // pertama), jadi yang dikunci adalah TIDAK BERTAMBAH -- bukan sama persis.
    // 15 teman tambahan lewat jalur lama = +45 query.
    expect($banyak)->toBeLessThanOrEqual($sedikit);
});

test('daftar teman tidak menembak query per teman', function () {
    $me = userWithFriends(2);

    $sedikit = countQueries(fn () => Livewire::actingAs($me)->test(Friends::class));

    User::factory()->count(12)->create()->each(function (User $friend) use ($me) {
        Friendship::create([
            'requester_id' => $me->id,
            'addressee_id' => $friend->id,
            'status' => FriendshipStatus::Accepted,
        ]);
    });

    $banyak = countQueries(fn () => Livewire::actingAs($me)->test(Friends::class));

    expect($banyak)->toBe($sedikit);
});

test('pencarian teman tidak menembak query per hasil', function () {
    $me = User::factory()->create(['username' => 'akuu']);

    // Hasil pencarian hanya dirender di tab 'find' -- tanpa setTab, computed-nya
    // tak pernah dievaluasi dan test ini akan lulus semu.
    $ukur = fn () => countQueries(
        fn () => Livewire::actingAs($me)->test(Friends::class)
            ->call('setTab', 'find')
            ->set('search', 'cari')
    );

    for ($i = 0; $i < 2; $i++) {
        User::factory()->create(['username' => 'cari'.$i]);
    }

    $sedikit = $ukur();

    // Lebih banyak kandidat cocok -> query TIDAK boleh ikut bertambah.
    for ($i = 2; $i < 12; $i++) {
        User::factory()->create(['username' => 'cari'.$i]);
    }

    expect($ukur())->toBe($sedikit);
});

test('browse clan tidak menembak query per clan', function () {
    $me = User::factory()->create();

    $bikinClan = function (int $n) {
        for ($i = 0; $i < $n; $i++) {
            $leader = User::factory()->create();
            $clan = Clan::create([
                'name' => 'Clan '.uniqid(),
                'leader_id' => $leader->id,
                'power' => 1000,
            ]);
            ClanMember::create([
                'clan_id' => $clan->id, 'user_id' => $leader->id,
                'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
            ]);
        }
    };

    $bikinClan(2);
    $sedikit = countQueries(fn () => Livewire::actingAs($me)->test(Clans::class)->call('setTab', 'browse'));

    $bikinClan(12);
    $banyak = countQueries(fn () => Livewire::actingAs($me)->test(Clans::class)->call('setTab', 'browse'));

    expect($banyak)->toBe($sedikit);
});

test('halaman stats memakai query dalam jumlah wajar', function () {
    $me = User::factory()->create();

    foreach (range(1, 20) as $i) {
        TypingResult::create([
            'user_id' => $me->id,
            'mode' => $i % 2 === 0 ? 'time' : 'words',
            'mode_config' => $i % 2 === 0 ? '30' : '25',
            'net_wpm' => 60 + $i,
            'raw_wpm' => 65 + $i,
            'accuracy' => 95,
            'correct_chars' => 300,
            'incorrect_chars' => 10,
            'duration_seconds' => 30,
        ]);
    }

    $queries = countQueries(fn () => Livewire::actingAs($me)->test(Stats::class));

    // Agregat (count/avg/sum) atas tabel & filter yang sama harus digabung jadi
    // SATU query, bukan satu query per angka.
    expect($queries)->toBeLessThanOrEqual(12);
});

test('halaman clan sendiri tidak mengulang query membership yang sama', function () {
    $me = User::factory()->create();
    $clan = Clan::create(['name' => 'Clan Ku', 'leader_id' => $me->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $clan->id, 'user_id' => $me->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

    $queries = countQueries(fn () => Livewire::actingAs($me)->test(Clans::class));

    // myMembership/myClan diakses dari mount(), beberapa computed, dan view.
    // Tanpa cache, query yang sama persis dijalankan berulang kali.
    expect($queries)->toBeLessThanOrEqual(10);
});
