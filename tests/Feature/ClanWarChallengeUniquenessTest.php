<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\ClanWar;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\User;
use Livewire\Livewire;

/**
 * Satu clan hanya boleh punya SATU war aktif (Pending atau Ongoing) pada satu waktu.
 *
 * Gerbangnya adalah read-then-write: `! $this->myActiveWar` dan `$opponent->activeWar() !== null`
 * keduanya SELECT, lalu `create()`. Tanpa penguncian, dua leader yang menekan tombol dalam
 * jendela yang sama bisa sama-sama lolos dan menghasilkan dua baris war untuk pasangan clan yang
 * sama. Clan::activeWar() memakai ->first(), jadi UI hanya menampilkan salah satunya sementara
 * yang lain tetap hidup: kedua clan terkunci dari war baru, dan begitu ends_at lewat, SATU
 * pertandingan membayar Elo DUA KALI.
 *
 * Konkurensi sungguhan butuh dua koneksi dan tak bisa direproduksi di dalam transaksi test, jadi
 * yang dikunci di sini adalah invariannya -- setiap jalur yang bisa dicapai satu permintaan.
 */
// userInClan() datang dari tests/Pest.php dan sudah mengembalikan [leader, clan].

it('refuses a second challenge while the challenger already has one pending', function () {
    [$meLeader, $myClan] = userInClan('Aggressor');
    [, $first] = userInClan('Target One');
    [, $second] = userInClan('Target Two');

    Livewire::actingAs($meLeader)->test(ClanWar::class)->call('challengeClan', $first->id);
    Livewire::actingAs($meLeader)->test(ClanWar::class)->call('challengeClan', $second->id);

    expect(ClanWarModel::where('challenger_clan_id', $myClan->id)->count())->toBe(1);
    expect(ClanWarModel::where('opponent_clan_id', $second->id)->count())->toBe(0);
});

it('refuses a challenge against a clan that is already in a war', function () {
    [$aLeader, $aClan] = userInClan('Alpha Chal');
    [, $bClan] = userInClan('Beta Chal');
    [$cLeader, $cClan] = userInClan('Gamma Chal');

    Livewire::actingAs($aLeader)->test(ClanWar::class)->call('challengeClan', $bClan->id);

    // B sudah terikat; C tak boleh menumpuk war kedua di atasnya.
    Livewire::actingAs($cLeader)->test(ClanWar::class)->call('challengeClan', $bClan->id);

    expect(ClanWarModel::count())->toBe(1);
    expect(ClanWarModel::first()->challenger_clan_id)->toBe($aClan->id);
    expect(ClanWarModel::where('challenger_clan_id', $cClan->id)->count())->toBe(0);
});

it('refuses a counter-challenge from the clan that was just challenged', function () {
    [$aLeader, $aClan] = userInClan('Alpha Counter');
    [$bLeader, $bClan] = userInClan('Beta Counter');

    Livewire::actingAs($aLeader)->test(ClanWar::class)->call('challengeClan', $bClan->id);

    // B ada di sisi OPPONENT, bukan challenger -- gerbangnya harus melihat kedua sisi.
    Livewire::actingAs($bLeader)->test(ClanWar::class)->call('challengeClan', $aClan->id);

    expect(ClanWarModel::count())->toBe(1);
    expect(ClanWarModel::first()->challenger_clan_id)->toBe($aClan->id);
});

it('lets a clan challenge again once the previous war is settled', function () {
    [$aLeader, $aClan] = userInClan('Alpha Again');
    [, $bClan] = userInClan('Beta Again');
    [, $cClan] = userInClan('Gamma Again');

    Livewire::actingAs($aLeader)->test(ClanWar::class)->call('challengeClan', $bClan->id);

    ClanWarModel::where('challenger_clan_id', $aClan->id)
        ->update(['status' => ClanWarStatus::Declined]);

    Livewire::actingAs($aLeader)->test(ClanWar::class)->call('challengeClan', $cClan->id);

    // Gerbangnya menutup war yang AKTIF, bukan menghukum clan selamanya.
    expect(ClanWarModel::where('opponent_clan_id', $cClan->id)
        ->where('status', ClanWarStatus::Pending)->count())->toBe(1);
});

it('refuses a clan challenging itself', function () {
    [$leader, $clan] = userInClan('Alpha Self');

    Livewire::actingAs($leader)->test(ClanWar::class)->call('challengeClan', $clan->id);

    expect(ClanWarModel::count())->toBe(0);
});

it('refuses a challenge issued by someone who is not the leader', function () {
    [, $myClan] = userInClan('Alpha Rank');
    [, $theirClan] = userInClan('Beta Rank');

    $member = User::factory()->create();
    ClanMember::create([
        'clan_id' => $myClan->id,
        'user_id' => $member->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Active,
    ]);

    Livewire::actingAs($member)->test(ClanWar::class)->call('challengeClan', $theirClan->id);

    expect(ClanWarModel::count())->toBe(0);
});
