<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Livewire\ClanWar;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Dua defect di sekitar tantangan war yang masih PENDING, keduanya soal siapa yang boleh
 * berbuat apa:
 *
 *   1. Tantangan yang sudah dikirim tak bisa ditarik kembali. Karena Clan::activeWar()
 *      menghitung Pending sebagai war aktif, satu salah-klik mengunci DUA clan sampai
 *      accept_deadline_at lewat -- termasuk clan lawan yang tak melakukan apa pun.
 *   2. Panel "tantangan masuk" beserta tombol Terima/Tolak dirender untuk SELURUH member,
 *      padahal servernya menolak siapa pun di luar pengurus. Tombolnya diklik, tak terjadi
 *      apa-apa, tanpa satu pun pesan.
 *
 * Kewenangan war = leader + co-leader (ClanRole::canManageWar). Member biasa tetap melihat
 * panelnya -- mereka berhak tahu clan-nya sedang ditantang -- hanya tanpa tombol.
 */

/**
 * Sengaja TIDAK memakai ulang makeClanWithLeader() milik ClanWarTest: fungsi global Pest hanya
 * ada kalau file yang mendeklarasikannya ikut dimuat, jadi meminjamnya membuat berkas ini lulus
 * di suite penuh tapi fatal saat dijalankan sendiri -- persis cara orang menjalankan satu file
 * saat menelusuri kegagalan.
 */
function makeWarClanWithLeader(string $name, int $power = 1000): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => $power]);

    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $leader->id,
        'role' => ClanRole::Leader,
        'status' => ClanMemberStatus::Active,
    ]);

    return [$leader, $clan];
}

function addWarRoleMember(Clan $clan, ClanRole $role): User
{
    $user = User::factory()->create();

    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $user->id,
        'role' => $role,
        'status' => ClanMemberStatus::Active,
    ]);

    return $user;
}

function pendingWarBetween(Clan $challenger, Clan $opponent): ClanWarModel
{
    return ClanWarModel::create([
        'challenger_clan_id' => $challenger->id,
        'opponent_clan_id' => $opponent->id,
        'status' => ClanWarStatus::Pending,
        'accept_deadline_at' => now()->addHour(),
    ]);
}

// ===========================================================================
// Defect 1 -- membatalkan tantangan yang sudah dikirim
// ===========================================================================

it('lets the challenging leader cancel a pending challenge', function () {
    [$leaderA, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    expect($war->fresh()->status)->toBe(ClanWarStatus::Cancelled);
});

it('lets a co-leader cancel the clan pending challenge', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $coLeader = addWarRoleMember($clanA, ClanRole::CoLeader);
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($coLeader)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    expect($war->fresh()->status)->toBe(ClanWarStatus::Cancelled);
});

it('does not let an ordinary member cancel the challenge', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $member = addWarRoleMember($clanA, ClanRole::Member);
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($member)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    expect($war->fresh()->status)->toBe(ClanWarStatus::Pending);
});

/**
 * Membatalkan adalah menarik tantangan SENDIRI, bukan cara kedua untuk menolak. Kalau pihak
 * yang ditantang bisa memanggilnya, ia menolak tanpa penantang pernah diberi tahu -- dan
 * melewati declineChallenge() yang justru mengirim notifikasinya.
 */
it('does not let the challenged clan cancel the challenge', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeWarClanWithLeader('Clan B');
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    expect($war->fresh()->status)->toBe(ClanWarStatus::Pending);
});

it('frees both clans to challenge again after a cancel', function () {
    [$leaderA, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    // Inti dari defect ini: bukan sekadar barisnya berubah status, melainkan kedua clan
    // benar-benar lepas dari kuncian "satu war aktif per clan".
    expect($clanA->fresh()->activeWar())->toBeNull()
        ->and($clanB->fresh()->activeWar())->toBeNull();

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('challengeClan', $clanB->id);

    expect(ClanWarModel::where('status', ClanWarStatus::Pending)->count())->toBe(1);
});

/**
 * Update bersyarat, bukan update polos -- idiom yang sama dengan acceptChallenge() dan
 * ClanWarResolver::settleWar(). Antara panel penantang dirender dan tombol Batalkan ditekan,
 * lawan bisa sudah menerimanya. Tanpa syarat `where status = Pending`, penulis terakhir menang
 * dan sebuah war yang sudah BERJALAN dibatalkan dari layar yang basi.
 */
it('leaves an accepted war untouched when a cancel arrives late', function () {
    [$leaderA, $clanA] = makeWarClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeWarClanWithLeader('Clan B');
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('acceptChallenge', $war->id);
    $accepted = $war->fresh();

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    $after = $war->fresh();
    expect($after->status)->toBe(ClanWarStatus::Ongoing)
        ->and($after->started_at->timestamp)->toBe($accepted->started_at->timestamp)
        ->and($after->ends_at->timestamp)->toBe($accepted->ends_at->timestamp);
});

it('tells the challenged leader that the challenge was withdrawn', function () {
    Event::fake([ClanUpdated::class]);
    [$leaderA, $clanA] = makeWarClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeWarClanWithLeader('Clan B');
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    Event::assertDispatched(ClanUpdated::class, function ($e) use ($leaderB) {
        return $e->userId === $leaderB->id
            && $e->notification !== null
            && $e->notification['type'] === 'war-cancelled'
            && $e->notification['message'] === __('clan.notify.war_cancelled', ['clan' => 'Clan A']);
    });
});

/**
 * Kelas defect yang sama dengan 3.7 di clan-war.md: panel yang dilihat SELURUH member berubah,
 * jadi seluruh member kedua clan harus dirender ulang -- bukan cuma leader yang dapat toast.
 */
it('silently refreshes the rest of both rosters when a challenge is cancelled', function () {
    Event::fake([ClanUpdated::class]);
    [$leaderA, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $memberA = addWarRoleMember($clanA, ClanRole::Member);
    $memberB = addWarRoleMember($clanB, ClanRole::Member);
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    foreach ([$memberA, $memberB] as $member) {
        Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $member->id && $e->notification === null);
    }
});

it('keeps a cancelled challenge out of the war history', function () {
    [$leaderA, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelChallenge', $war->id);

    // Tak ada Elo yang dipertaruhkan dan tak ada satu pun mode yang dimainkan, jadi ini bukan
    // pertandingan -- riwayat hanya memuat war yang benar-benar selesai (status Finished).
    expect($clanA->fresh()->finishedWars())->toBeEmpty();
});

// ===========================================================================
// Defect 2 -- kewenangan & visibilitas panel tantangan masuk
// ===========================================================================

it('lets a co-leader accept an incoming challenge', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $coLeader = addWarRoleMember($clanB, ClanRole::CoLeader);
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($coLeader)->test(ClanWar::class)->call('acceptChallenge', $war->id);

    expect($war->fresh()->status)->toBe(ClanWarStatus::Ongoing);
});

it('lets a co-leader decline an incoming challenge', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $coLeader = addWarRoleMember($clanB, ClanRole::CoLeader);
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($coLeader)->test(ClanWar::class)->call('declineChallenge', $war->id);

    expect($war->fresh()->status)->toBe(ClanWarStatus::Declined);
});

it('does not let an ordinary member accept or decline an incoming challenge', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $member = addWarRoleMember($clanB, ClanRole::Member);
    $war = pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($member)->test(ClanWar::class)->call('acceptChallenge', $war->id);
    expect($war->fresh()->status)->toBe(ClanWarStatus::Pending);

    Livewire::actingAs($member)->test(ClanWar::class)->call('declineChallenge', $war->id);
    expect($war->fresh()->status)->toBe(ClanWarStatus::Pending);
});

it('shows the accept and decline buttons to both a leader and a co-leader', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeWarClanWithLeader('Clan B');
    $coLeader = addWarRoleMember($clanB, ClanRole::CoLeader);
    pendingWarBetween($clanA, $clanB);

    foreach ([$leaderB, $coLeader] as $officer) {
        Livewire::actingAs($officer)->test(ClanWar::class)
            ->assertSee('acceptChallenge')
            ->assertSee('declineChallenge');
    }
});

/**
 * Inti defect 2: bukan menyembunyikan tantangannya, melainkan menghapus tombol yang tak akan
 * pernah berbuat apa-apa di tangan member. Mereka tetap diberi tahu -- clan-nya sedang
 * ditantang, dan itu kabar yang menyangkut mereka.
 */
it('hides the accept and decline buttons from an ordinary member but still tells them about the challenge', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $member = addWarRoleMember($clanB, ClanRole::Member);
    pendingWarBetween($clanA, $clanB);

    Livewire::actingAs($member)->test(ClanWar::class)
        ->assertDontSee('acceptChallenge')
        ->assertDontSee('declineChallenge')
        ->assertSee(__('clan.war.incoming'))
        ->assertSee(__('clan.war.awaiting_officers'));
});

it('shows the cancel button to officers of the challenging clan only', function () {
    [$leaderA, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $coLeaderA = addWarRoleMember($clanA, ClanRole::CoLeader);
    $memberA = addWarRoleMember($clanA, ClanRole::Member);
    pendingWarBetween($clanA, $clanB);

    foreach ([$leaderA, $coLeaderA] as $officer) {
        Livewire::actingAs($officer)->test(ClanWar::class)->assertSee('cancelChallenge');
    }

    Livewire::actingAs($memberA)->test(ClanWar::class)
        ->assertDontSee('cancelChallenge')
        ->assertSee(__('clan.war.waiting'));
});

/**
 * Konsekuensi dari model kewenangan yang dipilih: kalau co-leader boleh MENJAWAB tantangan,
 * tak ada lagi alasan ia dilarang MENGAJUKAN. Membiarkan mengajukan tetap leader-only akan
 * menghasilkan co-leader yang bisa membatalkan tantangan yang tak boleh ia buat.
 */
it('lets a co-leader issue a challenge but never an ordinary member', function () {
    [, $clanA] = makeWarClanWithLeader('Clan A');
    [, $clanB] = makeWarClanWithLeader('Clan B');
    $coLeader = addWarRoleMember($clanA, ClanRole::CoLeader);
    $member = addWarRoleMember($clanA, ClanRole::Member);

    Livewire::actingAs($member)->test(ClanWar::class)->call('challengeClan', $clanB->id);
    expect(ClanWarModel::count())->toBe(0);

    Livewire::actingAs($coLeader)->test(ClanWar::class)->call('challengeClan', $clanB->id);
    expect(ClanWarModel::where('status', ClanWarStatus::Pending)->count())->toBe(1);
});
