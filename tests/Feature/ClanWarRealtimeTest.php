<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Livewire\ClanWar;
use App\Livewire\TypingEngine;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use App\Support\ClanWarBroadcast;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Nama helper sengaja diberi awalan `rt` (realtime): fungsi yang dideklarasikan di sebuah
 * berkas test bersifat GLOBAL begitu berkasnya dimuat, jadi memakai ulang nama seperti
 * `makeClanWithLeader` milik ClanWarTest akan menabrak deklarasinya saat suite dijalankan
 * penuh -- dan memanggil punya mereka membuat berkas ini tak bisa dijalankan sendirian.
 */
function rtClan(string $name, int $memberCount = 1): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $clan->id, 'user_id' => $leader->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

    $members = [];
    for ($i = 1; $i < $memberCount; $i++) {
        $member = User::factory()->create();
        ClanMember::create([
            'clan_id' => $clan->id, 'user_id' => $member->id,
            'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active,
        ]);
        $members[] = $member;
    }

    return [$leader, $clan, $members];
}

function rtWar(Clan $challenger, Clan $opponent, ClanWarStatus $status = ClanWarStatus::Ongoing): ClanWarModel
{
    return ClanWarModel::create([
        'challenger_clan_id' => $challenger->id,
        'opponent_clan_id' => $opponent->id,
        'status' => $status,
        'accept_deadline_at' => now()->addHour(),
        'started_at' => $status === ClanWarStatus::Ongoing ? now() : null,
        'ends_at' => $status === ClanWarStatus::Ongoing ? now()->addDays(3) : null,
    ]);
}

/** Hasil ketik + klaim yang SUDAH disubmit, dengan poin yang ditentukan langsung. */
function rtSubmittedClaim(ClanWarModel $war, Clan $clan, User $user, string $mode, string $config, float $points): ClanWarModeClaim
{
    $result = TypingResult::create([
        'user_id' => $user->id, 'mode' => $mode, 'mode_config' => $config,
        'net_wpm' => 80, 'raw_wpm' => 85, 'accuracy' => 95, 'correct_chars' => 300,
        'incorrect_chars' => 5, 'duration_seconds' => 30, 'xp_earned' => 100,
    ]);

    return ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clan->id, 'user_id' => $user->id,
        'mode' => $mode, 'mode_config' => $config,
        'typing_result_id' => $result->id, 'points' => $points, 'claimed_at' => now(),
    ]);
}

/** Isi klaim yang tadinya kosong -- meniru rekan sekelompok yang submit lebih dulu. */
function rtFillClaim(ClanWarModeClaim $claim, User $user): void
{
    $result = TypingResult::create([
        'user_id' => $user->id, 'mode' => $claim->mode, 'mode_config' => $claim->mode_config,
        'net_wpm' => 70, 'raw_wpm' => 75, 'accuracy' => 92, 'correct_chars' => 250,
        'incorrect_chars' => 8, 'duration_seconds' => 30, 'xp_earned' => 90,
    ]);

    $claim->update(['typing_result_id' => $result->id, 'points' => 44.0]);
}

it('mendaftar setiap member aktif KEDUA clan sebagai peserta war', function () {
    [$leaderA, $clanA, $membersA] = rtClan('Clan A', memberCount: 2);
    [$leaderB, $clanB, $membersB] = rtClan('Clan B', memberCount: 3);

    $war = rtWar($clanA, $clanB);

    expect($war->participantUserIds())
        ->toHaveCount(5)
        ->toContain($leaderA->id, $membersA[0]->id, $leaderB->id, $membersB[0]->id, $membersB[1]->id);
});

it('tidak menghitung member yang belum aktif sebagai peserta', function () {
    [$leaderA, $clanA] = rtClan('Clan A');
    [$leaderB, $clanB] = rtClan('Clan B');

    // Permintaan gabung yang belum disetujui: belum bagian dari clan, jadi belum bagian dari war.
    $pending = User::factory()->create();
    ClanMember::create([
        'clan_id' => $clanA->id, 'user_id' => $pending->id,
        'role' => ClanRole::Member, 'status' => ClanMemberStatus::Pending,
    ]);

    $ids = rtWar($clanA, $clanB)->participantUserIds();

    expect($ids)->toHaveCount(2)
        ->and($ids)->not->toContain($pending->id);
});

it('menyiarkan satu ClanUpdated SENYAP ke tiap peserta', function () {
    Event::fake([ClanUpdated::class]);

    [$leaderA, $clanA, $membersA] = rtClan('Clan A', memberCount: 2);
    [$leaderB, $clanB] = rtClan('Clan B');

    ClanWarBroadcast::refresh(rtWar($clanA, $clanB));

    Event::assertDispatchedTimes(ClanUpdated::class, 3);

    foreach ([$leaderA, $membersA[0], $leaderB] as $user) {
        Event::assertDispatched(
            ClanUpdated::class,
            // notification null = "render ulang dirimu", tanpa toast (guard di toasts.js).
            fn ($e) => $e->userId === $user->id && $e->notification === null
        );
    }
});

it('melewati peserta yang sudah menerima event ber-pesan untuk perubahan yang sama', function () {
    Event::fake([ClanUpdated::class]);

    [$leaderA, $clanA, $membersA] = rtClan('Clan A', memberCount: 2);
    [$leaderB, $clanB] = rtClan('Clan B');

    ClanWarBroadcast::refresh(rtWar($clanA, $clanB), exceptUserIds: [$leaderA->id]);

    Event::assertDispatchedTimes(ClanUpdated::class, 2);
    Event::assertNotDispatched(ClanUpdated::class, fn ($e) => $e->userId === $leaderA->id);
});

// ---- HALAMAN CLAN WAR MENDENGARKAN EVENT-NYA ----

it('memperbarui poin lawan tanpa reload saat event clan-updated tiba', function () {
    [$leaderA, $clanA] = rtClan('Alpha');
    [$leaderB, $clanB] = rtClan('Bravo');
    $war = rtWar($clanA, $clanB);

    // Halaman sudah dirender SEBELUM lawan mencetak poin -- persis kondisi bug-nya.
    $page = Livewire::actingAs($leaderA)->test(ClanWar::class)->assertDontSee('37.5');

    rtSubmittedClaim($war, $clanB, $leaderB, 'time', '30', 37.5);

    // Sebelum perbaikan: Livewire melempar EventHandlerDoesNotExist('clan-updated'),
    // karena jembatan di blade menembak ke komponen yang tak punya listener.
    $page->dispatch('clan-updated')->assertSee('37.5');
});

it('menyuruh kedua clan me-refresh saat sebuah mode diklaim', function () {
    Event::fake([ClanUpdated::class]);

    [$leaderA, $clanA, $membersA] = rtClan('Alpha', memberCount: 2);
    [$leaderB, $clanB] = rtClan('Bravo');
    rtWar($clanA, $clanB);

    Livewire::actingAs($membersA[0])->test(ClanWar::class)->call('claimMode', 'time', '30');

    Event::assertDispatchedTimes(ClanUpdated::class, 3);

    foreach ([$leaderA, $membersA[0], $leaderB] as $user) {
        Event::assertDispatched(
            ClanUpdated::class,
            fn ($e) => $e->userId === $user->id && $e->notification === null
        );
    }
});

it('menyuruh kedua clan me-refresh saat klaim dibatalkan', function () {
    [$leaderA, $clanA] = rtClan('Alpha');
    [$leaderB, $clanB] = rtClan('Bravo');
    $war = rtWar($clanA, $clanB);

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    Event::fake([ClanUpdated::class]);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelClaim', $claim->id);

    Event::assertDispatchedTimes(ClanUpdated::class, 2);
    Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $leaderB->id && $e->notification === null);
});

it('menyuruh kedua clan me-refresh saat sebuah war attempt disubmit', function () {
    [$leaderA, $clanA] = rtClan('Alpha');
    [$leaderB, $clanB] = rtClan('Bravo');
    $war = rtWar($clanA, $clanB);

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    Event::fake([ClanUpdated::class]);

    $component = Livewire::actingAs($leaderA)->test(TypingEngine::class, ['warClaimId' => $claim->id]);
    runWarAttemptClock($claim);
    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 290]);

    Event::assertDispatchedTimes(ClanUpdated::class, 2);
    Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $leaderB->id && $e->notification === null);
});

it('tidak menyiarkan apa pun kalau attempt-nya tak mengisi claim mana pun', function () {
    [$leaderA, $clanA] = rtClan('Alpha');
    [$leaderB, $clanB] = rtClan('Bravo');
    $war = rtWar($clanA, $clanB);

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($leaderA)->test(TypingEngine::class, ['warClaimId' => $claim->id]);

    // Rekan sekelompok menyelesaikan slot itu lebih dulu, di tengah run kita.
    rtFillClaim($claim, $leaderA);

    Event::fake([ClanUpdated::class]);

    runWarAttemptClock($claim);
    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 290]);

    Event::assertNotDispatched(ClanUpdated::class);
});

// ---- BUG 2: TANTANGAN DITERIMA / DITOLAK / DIKIRIM ----

it('menyuruh member biasa kedua clan me-refresh saat tantangan diterima', function () {
    Event::fake([ClanUpdated::class]);

    [$leaderA, $clanA, $membersA] = rtClan('Alpha', memberCount: 2);
    [$leaderB, $clanB] = rtClan('Bravo');
    $war = rtWar($clanA, $clanB, ClanWarStatus::Pending);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('acceptChallenge', $war->id);

    // Kontrak lama tetap: leader penantang dapat toast-nya.
    Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $leaderA->id
        && $e->notification !== null
        && $e->notification['type'] === 'war-accepted');

    // Yang diperbaiki: member biasa dapat refresh senyap, bukan panel "menunggu" selamanya.
    Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $membersA[0]->id && $e->notification === null);

    // Leader penantang tak dikirimi dua kali untuk perubahan yang sama.
    Event::assertDispatchedTimes(ClanUpdated::class, 3);
});

it('mengubah panel menunggu jadi war berjalan tanpa reload', function () {
    [$leaderA, $clanA, $membersA] = rtClan('Alpha', memberCount: 2);
    [$leaderB, $clanB] = rtClan('Bravo');
    $war = rtWar($clanA, $clanB, ClanWarStatus::Pending);

    $page = Livewire::actingAs($membersA[0])->test(ClanWar::class)
        ->assertSee(__('clan.war.waiting'));

    $war->update([
        'status' => ClanWarStatus::Ongoing,
        'started_at' => now(), 'ends_at' => now()->addDays(3),
    ]);

    $page->dispatch('clan-updated')
        ->assertSee(__('clan.war.ongoing'))
        ->assertDontSee(__('clan.war.waiting'));
});

it('menyuruh member biasa kedua clan me-refresh saat tantangan ditolak', function () {
    Event::fake([ClanUpdated::class]);

    [$leaderA, $clanA, $membersA] = rtClan('Alpha', memberCount: 2);
    [$leaderB, $clanB] = rtClan('Bravo');
    $war = rtWar($clanA, $clanB, ClanWarStatus::Pending);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('declineChallenge', $war->id);

    Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $membersA[0]->id && $e->notification === null);
    Event::assertDispatchedTimes(ClanUpdated::class, 3);
});

it('menyuruh member biasa kedua clan me-refresh saat tantangan dikirim', function () {
    Event::fake([ClanUpdated::class]);

    [$leaderA, $clanA, $membersA] = rtClan('Alpha', memberCount: 2);
    [$leaderB, $clanB] = rtClan('Bravo');

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('challengeClan', $clanB->id);

    // Leader lawan dapat toast tantangan; sisanya refresh senyap (termasuk member clan sendiri,
    // yang panel "menunggu"-nya baru muncul sekarang).
    Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $leaderB->id
        && $e->notification !== null
        && $e->notification['type'] === 'war-challenge');
    Event::assertDispatched(ClanUpdated::class, fn ($e) => $e->userId === $membersA[0]->id && $e->notification === null);
    Event::assertDispatchedTimes(ClanUpdated::class, 3);
});

// ---- KONTRAK JEMBATAN JS (proyek ini tak menjalankan JS di test) ----

it('menjaga rantai clan.{me} -> clan-updated tetap tersambung ujung ke ujung', function () {
    $blade = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/clan-war.blade.php')));
    $js = tanpaKomentarJs(file_get_contents(resource_path('js/toasts.js')));

    expect($js)->toContain("new CustomEvent('clan-updated-remote')")
        ->and($blade)->toContain("window.addEventListener('clan-updated-remote'")
        ->and($blade)->toContain("\$wire.dispatch('clan-updated')");
});
