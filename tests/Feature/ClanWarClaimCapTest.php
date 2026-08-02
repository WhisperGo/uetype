<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\ClanWar;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\User;
use App\Services\ClanWarModeCatalog;
use Livewire\Livewire;

/**
 * Grid war punya 9 slot, dan batas klaim per anggota dulu tetap 4.
 *
 * 9 / 4 = butuh 3 anggota. Aturan itu tertulis di dokumen tapi TAK PERNAH ditegakkan di kode,
 * jadi clan 2 orang boleh menerima war lalu menemukan slot ke-9 yang tak bisa diambil siapa pun
 * -- dan karena early finish menuntut 9/9 di kedua sisi, clan lawan yang sudah tuntas ikut
 * terkunci menunggu 3 hari penuh. Yang menanggung hukumannya justru pihak yang tak bersalah.
 *
 * Sekarang capnya mengecil mengikuti roster, dan di-SNAPSHOT saat war diterima supaya tak bisa
 * dinaikkan dengan menendang anggota di tengah war.
 */
function capWarFor(int $memberCount, ?int $snapshotCap = null): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Alpha Cap', 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $members = [$leader];

    foreach (range(2, max(1, $memberCount)) as $i) {
        if ($i > $memberCount) {
            break;
        }

        $mate = User::factory()->create();
        ClanMember::create(['clan_id' => $clan->id, 'user_id' => $mate->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);
        $members[] = $mate;
    }

    $rival = User::factory()->create();
    $rivalClan = Clan::create(['name' => 'Beta Cap', 'leader_id' => $rival->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $rivalClan->id, 'user_id' => $rival->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clan->id,
        'opponent_clan_id' => $rivalClan->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDay(),
        'challenger_power_before' => 1000,
        'opponent_power_before' => 1000,
        'challenger_max_claims' => $snapshotCap,
        'started_at' => now()->subHours(2),
        'ends_at' => now()->addDays(3),
    ]);

    return [$members, $clan, $war];
}

/** Semua 9 mode wajib, dalam urutan grid. */
function everyWarMode(): array
{
    return array_map(fn (array $m) => [$m['mode'], $m['config']], ClanWarModeCatalog::MODES);
}

function claimAsManyAsPossible(User $player): void
{
    $component = Livewire::actingAs($player)->test(ClanWar::class);

    foreach (everyWarMode() as [$mode, $config]) {
        $component->call('claimMode', $mode, $config);
    }
}

it('lets a solo-member clan claim all nine slots', function () {
    [$members] = capWarFor(1);

    claimAsManyAsPossible($members[0]);

    // "Satu akun menentukan war sendirian" bukan risiko di clan beranggota satu -- itu
    // memang seluruh clan-nya. Menguncinya dari fitur bukan perlindungan.
    expect(ClanWarModeClaim::where('user_id', $members[0]->id)->count())->toBe(9);
});

it('caps a two-member clan at five slots each so the grid is fillable', function () {
    [$members] = capWarFor(2);

    claimAsManyAsPossible($members[0]);

    expect(ClanWarModeClaim::where('user_id', $members[0]->id)->count())->toBe(5);

    claimAsManyAsPossible($members[1]);

    // Inti defect-nya: 9 slot benar-benar terisi, bukan 8.
    expect(ClanWarModeClaim::where('clan_id', $members[0]->clan->id)->count())->toBe(9);
});

it('keeps a three-member clan at four slots each', function () {
    [$members] = capWarFor(3);

    claimAsManyAsPossible($members[0]);

    // Begitu roster cukup, batas F-03 kembali berlaku penuh.
    expect(ClanWarModeClaim::where('user_id', $members[0]->id)->count())
        ->toBe(ClanWarModeCatalog::MIN_CLAIMS_PER_MEMBER);
});

it('snapshots the cap so kicking members mid-war cannot raise it', function () {
    // War diterima saat clan beranggota 3 -> snapshot 4.
    [$members, $clan] = capWarFor(3, snapshotCap: 4);

    // Dua anggota keluar. Roster live sekarang 1, yang secara live berarti cap 9.
    ClanMember::where('clan_id', $clan->id)->where('user_id', '!=', $members[0]->id)->delete();

    claimAsManyAsPossible($members[0]);

    // Snapshot yang menang: mengecilkan roster tak boleh jadi cara memusatkan seluruh war
    // ke satu akun -- persis konsentrasi yang dicegah capnya.
    expect(ClanWarModeClaim::where('user_id', $members[0]->id)->count())->toBe(4);
});

it('falls back to the live roster when a war has no snapshot', function () {
    // War lama (sebelum kolomnya ada) dan setiap helper test yang membuat war langsung.
    [$members] = capWarFor(2, snapshotCap: null);

    claimAsManyAsPossible($members[0]);

    expect(ClanWarModeClaim::where('user_id', $members[0]->id)->count())->toBe(5);
});

it('tells the player their quota ran out instead of blaming a teammate', function () {
    [$members] = capWarFor(3);

    $component = Livewire::actingAs($members[0])->test(ClanWar::class);

    // Habiskan jatah, lalu satu klaim lagi -- inilah yang pesannya harus jelaskan.
    foreach (array_slice(everyWarMode(), 0, 5) as [$mode, $config]) {
        $component->call('claimMode', $mode, $config);
    }

    // Ketiga jalur gagal dulu memakai pesan yang sama: "mode ini baru saja diambil anggota
    // lain" -- keliru secara fakta, dan menyuruh pemain mencari rekan yang tak ada.
    $component->assertSee(__('clan.error.max_claims', ['max' => 4]))
        ->assertDontSee(__('clan.error.mode_taken'));
});

it('stops offering a claim button once the quota is gone', function () {
    [$members] = capWarFor(3);

    claimAsManyAsPossible($members[0]);

    Livewire::actingAs($members[0])->test(ClanWar::class)
        ->assertSee(__('clan.war.no_quota'))
        // Ditambatkan pada handler-nya, bukan pada labelnya: "Claim" juga muncul di
        // "Cancel Claim" dan "claimed by", jadi assertion atas teksnya akan lulus/gagal
        // karena kalimat yang sama sekali lain.
        ->assertDontSee('claimMode(');
});
