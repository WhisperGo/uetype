<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;

/**
 * P2: $user->clan dulu adalah ACCESSOR yang membungkus method biasa, bukan relasi.
 * Dua akibatnya diuji di sini, karena keduanya tak terlihat oleh test fungsional:
 *
 *   1. tak ada cache  -> query berulang tiap kali properti dibaca
 *   2. tak bisa with() -> N+1 mustahil dihindari di daftar user mana pun
 */
function userInClan(?string $clanName = null): array
{
    $user = User::factory()->create();
    $clan = Clan::create([
        'name' => $clanName ?? 'Clan '.uniqid(),
        'tag' => 'TAG',
        'leader_id' => $user->id,
        'power' => 1000,
    ]);
    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $user->id,
        'role' => ClanRole::Leader,
        'status' => ClanMemberStatus::Active,
    ]);

    return [$user, $clan];
}

test('clan bisa di-eager-load lewat with()', function () {
    [$user, $clan] = userInClan('Clan Eager');

    $loaded = User::with('clan')->find($user->id);

    expect($loaded->relationLoaded('clan'))->toBeTrue()
        ->and($loaded->clan->id)->toBe($clan->id);
});

test('membaca clan berkali-kali hanya menembak satu query', function () {
    [$user] = userInClan();

    $fresh = User::find($user->id);

    $queries = countQueries(function () use ($fresh) {
        $fresh->clan;
        $fresh->clan;
        $fresh->clan;
    });

    // Relasi di-cache Eloquent setelah akses pertama. Dulu (accessor) tiap akses
    // menembak DUA query (clan_members + clans) -> 6 query untuk 3 pembacaan.
    expect($queries)->toBe(1);
});

test('daftar user yang di-eager-load tidak menembak query per user', function () {
    foreach (range(1, 5) as $i) {
        userInClan('Clan '.$i);
    }

    $users = User::with('clan')->get();

    // Setelah eager load, membaca clan tiap user TIDAK boleh menyentuh DB lagi.
    $queries = countQueries(function () use ($users) {
        foreach ($users as $u) {
            $u->clan?->name;
        }
    });

    expect($queries)->toBe(0);
});

test('user tanpa clan mengembalikan null', function () {
    $user = User::factory()->create();

    expect(User::find($user->id)->clan)->toBeNull()
        ->and(User::with('clan')->find($user->id)->clan)->toBeNull();
});

test('keanggotaan pending tidak dianggap sebagai clan aktif', function () {
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Clan Pending', 'leader_id' => $leader->id, 'power' => 1000]);

    $pendingUser = User::factory()->create();
    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $pendingUser->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Pending,
    ]);

    expect(User::find($pendingUser->id)->clan)->toBeNull()
        ->and(User::with('clan')->find($pendingUser->id)->clan)->toBeNull();
});

test('clan_role tetap mengembalikan peran dari clan_members', function () {
    [$user] = userInClan();

    expect(User::find($user->id)->clan_role)->toBe(ClanRole::Leader->value);
});
