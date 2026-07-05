<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\ClanWar;
use App\Livewire\TypingEngine;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\ClanWarModeCatalog;
use App\Services\ClanWarScorer;
use Livewire\Livewire;

/**
 * Bikin dua clan yang sedang berperang (Ongoing), kembalikan
 * [leaderA, clanA, leaderB, clanB, war].
 */
function warBetweenTwoClans(): array
{
    $leaderA = User::factory()->create();
    $clanA = Clan::create(['name' => 'Clan A', 'leader_id' => $leaderA->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clanA->id, 'user_id' => $leaderA->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $leaderB = User::factory()->create();
    $clanB = Clan::create(['name' => 'Clan B', 'leader_id' => $leaderB->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clanB->id, 'user_id' => $leaderB->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDays(1),
        'challenger_power_before' => 1000,
        'opponent_power_before' => 1000,
        'started_at' => now()->subHours(2),
        'ends_at' => now()->addDays(3),
    ]);

    return [$leaderA, $clanA, $leaderB, $clanB, $war];
}

it('has exactly 9 war modes matching the allowed submodes', function () {
    expect(ClanWarModeCatalog::MODES)->toHaveCount(9);

    $configs = collect(ClanWarModeCatalog::MODES)->map(fn ($m) => $m['mode'].':'.$m['config'])->all();

    expect($configs)->toContain('time:15', 'time:30', 'time:60', 'time:120');
    expect($configs)->toContain('words:10', 'words:25', 'words:50', 'words:100');
    expect($configs)->toContain('survival:hard');
    // Survival easy/medium TIDAK termasuk.
    expect($configs)->not->toContain('survival:easy');
    expect($configs)->not->toContain('survival:medium');
});

it('lets a member claim an open mode and locks it for the clan', function () {
    [$leaderA, $clanA, , , $war] = warBetweenTwoClans();

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('claimMode', 'words', '25');

    $this->assertDatabaseHas('clan_war_mode_claims', [
        'clan_war_id' => $war->id,
        'clan_id' => $clanA->id,
        'user_id' => $leaderA->id,
        'mode' => 'words',
        'mode_config' => '25',
        'typing_result_id' => null,
    ]);
});

it('rejects a second claim of the same mode by the same clan (unique lock)', function () {
    [$leaderA, $clanA, , , $war] = warBetweenTwoClans();

    // Member kedua di clan A.
    $memberA2 = User::factory()->create();
    ClanMember::create(['clan_id' => $clanA->id, 'user_id' => $memberA2->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);

    ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'words', 'mode_config' => '25', 'claimed_at' => now(),
    ]);

    Livewire::actingAs($memberA2)->test(ClanWar::class)->call('claimMode', 'words', '25');

    // Masih hanya satu klaim untuk slot itu.
    expect(ClanWarModeClaim::where('clan_war_id', $war->id)->where('clan_id', $clanA->id)
        ->where('mode', 'words')->where('mode_config', '25')->count())->toBe(1);
});

it('lets the opposing clan claim the same mode independently', function () {
    [$leaderA, $clanA, $leaderB, $clanB, $war] = warBetweenTwoClans();

    ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'words', 'mode_config' => '25', 'claimed_at' => now(),
    ]);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('claimMode', 'words', '25');

    // Kedua clan punya klaim untuk mode yang sama -- itu memang harusnya bisa.
    expect(ClanWarModeClaim::where('clan_war_id', $war->id)->where('mode', 'words')->where('mode_config', '25')->count())->toBe(2);
});

it('lets the claimer cancel an unsubmitted claim, reopening the mode', function () {
    [$leaderA, $clanA, , , $war] = warBetweenTwoClans();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'words', 'mode_config' => '25', 'claimed_at' => now(),
    ]);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelClaim', $claim->id);

    $this->assertDatabaseMissing('clan_war_mode_claims', ['id' => $claim->id]);
});

it('does not let a member of another clan cancel a claim (trust boundary)', function () {
    [$leaderA, $clanA, $leaderB, , $war] = warBetweenTwoClans();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'words', 'mode_config' => '25', 'claimed_at' => now(),
    ]);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('cancelClaim', $claim->id);

    $this->assertDatabaseHas('clan_war_mode_claims', ['id' => $claim->id]);
});

it('cannot cancel a claim that is already submitted', function () {
    [$leaderA, $clanA, , , $war] = warBetweenTwoClans();

    $result = TypingResult::create([
        'user_id' => $leaderA->id, 'mode' => 'words', 'mode_config' => '25',
        'net_wpm' => 80, 'raw_wpm' => 85, 'accuracy' => 95, 'correct_chars' => 100,
        'incorrect_chars' => 2, 'duration_seconds' => 12, 'xp_earned' => 40,
    ]);
    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'words', 'mode_config' => '25', 'typing_result_id' => $result->id,
        'points' => 50, 'claimed_at' => now(),
    ]);

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('cancelClaim', $claim->id);

    $this->assertDatabaseHas('clan_war_mode_claims', ['id' => $claim->id]);
});

it('locks the typing engine mode to the claim and forbids switching mode', function () {
    [$leaderA, $clanA, , , $war] = warBetweenTwoClans();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'time', 'mode_config' => '60', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($leaderA)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    $component->assertSet('mainMode', 'time')->assertSet('subMode', '60');

    // Coba paksa ganti mode -> ditolak, mode tetap terkunci.
    $component->call('setMode', 'words', '10')
        ->assertSet('mainMode', 'time')
        ->assertSet('subMode', '60');
});

it('ignores an invalid war_claim query param and behaves as a normal solo session', function () {
    [$leaderA, $clanA, $leaderB, $clanB, $war] = warBetweenTwoClans();

    // Klaim milik clan B, tapi diakses oleh leader A -> tak valid untuknya.
    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanB->id, 'user_id' => $leaderB->id,
        'mode' => 'time', 'mode_config' => '60', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($leaderA)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    // warClaimId di-reset ke null; mode bebas (bukan terkunci ke 'time'/'60').
    $component->assertSet('warClaimId', null)->assertSet('warLock', null);
});

it('scores a time attempt per the ceiling × wpm × accuracy formula', function () {
    // Words-25 ceiling = 70. net_wpm 150 (skala penuh), accuracy 100 -> full ceiling.
    $result = TypingResult::create([
        'user_id' => User::factory()->create()->id, 'mode' => 'words', 'mode_config' => '25',
        'net_wpm' => 150, 'raw_wpm' => 155, 'accuracy' => 100, 'correct_chars' => 200,
        'incorrect_chars' => 0, 'duration_seconds' => 20, 'xp_earned' => 60,
    ]);

    // ceiling 70 × min(1, 150/150)=1 × (0.5+0.5*1)=1 -> 70.
    expect(ClanWarScorer::score('words', '25', $result))->toBe(70.0);

    // net_wpm 75 (setengah skala), accuracy 100 -> 70 × 0.5 × 1 = 35.
    $result->net_wpm = 75;
    expect(ClanWarScorer::score('words', '25', $result))->toBe(35.0);
});

it('scores a survival attempt from duration instead of wpm', function () {
    // Survival-hard ceiling = 150, skala 90 detik. duration 90, accuracy 100 -> full.
    $result = TypingResult::create([
        'user_id' => User::factory()->create()->id, 'mode' => 'survival', 'mode_config' => 'hard',
        'net_wpm' => 0, 'raw_wpm' => 0, 'accuracy' => 100, 'correct_chars' => 400,
        'incorrect_chars' => 10, 'duration_seconds' => 90, 'score' => 400, 'xp_earned' => 40,
    ]);

    expect(ClanWarScorer::score('survival', 'hard', $result))->toBe(150.0);

    // duration 45 (setengah), accuracy 100 -> 150 × 0.5 × 1 = 75.
    $result->duration_seconds = 45;
    expect(ClanWarScorer::score('survival', 'hard', $result))->toBe(75.0);
});

it('resolves the war using summed claim points, not free xp_earned', function () {
    [$leaderA, $clanA, $leaderB, $clanB, $war] = warBetweenTwoClans();

    // Pindahkan war ke masa lalu supaya resolver menutupnya saat halaman dibuka.
    $war->update(['started_at' => now()->subDays(3), 'ends_at' => now()->subMinute()]);

    // Clan A submit dua klaim (total 100 poin); clan B satu klaim (30 poin).
    $rA = TypingResult::create(['user_id' => $leaderA->id, 'mode' => 'time', 'mode_config' => '30', 'net_wpm' => 80, 'raw_wpm' => 85, 'accuracy' => 95, 'correct_chars' => 300, 'incorrect_chars' => 5, 'duration_seconds' => 30, 'xp_earned' => 999]);
    ClanWarModeClaim::create(['clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id, 'mode' => 'time', 'mode_config' => '30', 'typing_result_id' => $rA->id, 'points' => 60, 'claimed_at' => now()]);
    $rA2 = TypingResult::create(['user_id' => $leaderA->id, 'mode' => 'words', 'mode_config' => '10', 'net_wpm' => 80, 'raw_wpm' => 85, 'accuracy' => 95, 'correct_chars' => 60, 'incorrect_chars' => 2, 'duration_seconds' => 8, 'xp_earned' => 999]);
    ClanWarModeClaim::create(['clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id, 'mode' => 'words', 'mode_config' => '10', 'typing_result_id' => $rA2->id, 'points' => 40, 'claimed_at' => now()]);

    $rB = TypingResult::create(['user_id' => $leaderB->id, 'mode' => 'time', 'mode_config' => '30', 'net_wpm' => 40, 'raw_wpm' => 45, 'accuracy' => 80, 'correct_chars' => 100, 'incorrect_chars' => 20, 'duration_seconds' => 30, 'xp_earned' => 5]);
    ClanWarModeClaim::create(['clan_war_id' => $war->id, 'clan_id' => $clanB->id, 'user_id' => $leaderB->id, 'mode' => 'time', 'mode_config' => '30', 'typing_result_id' => $rB->id, 'points' => 30, 'claimed_at' => now()]);

    Livewire::actingAs($leaderA)->test(ClanWar::class);

    $war->refresh();
    expect($war->status)->toBe(ClanWarStatus::Finished);
    // Clan A (100 poin) menang atas clan B (30 poin) -- meski xp_earned clan B tinggi.
    expect($war->result)->toBe('win');
});

it('ignores unsubmitted claims (points null) when resolving', function () {
    [$leaderA, $clanA, $leaderB, $clanB, $war] = warBetweenTwoClans();
    $war->update(['started_at' => now()->subDays(3), 'ends_at' => now()->subMinute()]);

    // Clan A klaim tapi TIDAK submit (typing_result_id null) -> 0 poin.
    ClanWarModeClaim::create(['clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id, 'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now()]);

    // Clan B submit 20 poin.
    $rB = TypingResult::create(['user_id' => $leaderB->id, 'mode' => 'time', 'mode_config' => '30', 'net_wpm' => 40, 'raw_wpm' => 45, 'accuracy' => 80, 'correct_chars' => 100, 'incorrect_chars' => 20, 'duration_seconds' => 30, 'xp_earned' => 5]);
    ClanWarModeClaim::create(['clan_war_id' => $war->id, 'clan_id' => $clanB->id, 'user_id' => $leaderB->id, 'mode' => 'time', 'mode_config' => '30', 'typing_result_id' => $rB->id, 'points' => 20, 'claimed_at' => now()]);

    Livewire::actingAs($leaderA)->test(ClanWar::class);

    $war->refresh();
    // Clan A 0 poin (klaim tak disubmit) vs clan B 20 -> clan A (challenger) kalah.
    expect($war->result)->toBe('loss');
});

/**
 * Submit SEMUA 9 mode untuk sebuah clan (poin sama semua, cukup untuk uji
 * penutupan) -- dipakai menguji early finish.
 */
function submitAllModesForClan(ClanWarModel $war, Clan $clan, User $user, float $pointsEach = 10.0): void
{
    foreach (ClanWarModeCatalog::MODES as $m) {
        $result = TypingResult::create([
            'user_id' => $user->id, 'mode' => $m['mode'], 'mode_config' => $m['config'],
            'net_wpm' => 80, 'raw_wpm' => 85, 'accuracy' => 95, 'correct_chars' => 100,
            'incorrect_chars' => 2, 'duration_seconds' => 20, 'xp_earned' => 40,
        ]);
        ClanWarModeClaim::create([
            'clan_war_id' => $war->id, 'clan_id' => $clan->id, 'user_id' => $user->id,
            'mode' => $m['mode'], 'mode_config' => $m['config'],
            'typing_result_id' => $result->id, 'points' => $pointsEach, 'claimed_at' => now(),
        ]);
    }
}

it('finishes the war early once BOTH clans have completed all 9 modes, before ends_at', function () {
    [$leaderA, $clanA, $leaderB, $clanB, $war] = warBetweenTwoClans();

    // ends_at masih 3 hari ke depan (dari helper) -- belum lewat waktu.
    expect($war->ends_at->isFuture())->toBeTrue();

    // Clan A unggul tiap mode, kedua clan selesaikan seluruh 9 mode.
    submitAllModesForClan($war, $clanA, $leaderA, 15.0);
    submitAllModesForClan($war, $clanB, $leaderB, 10.0);

    // Sekadar membuka halaman memicu resolusi on-the-fly.
    Livewire::actingAs($leaderA)->test(ClanWar::class);

    $war->refresh();
    expect($war->status)->toBe(ClanWarStatus::Finished);
    expect($war->result)->toBe('win'); // clan A (challenger) 135 vs clan B 90.

    // Kedua clan kini bebas berperang lagi (tak ada war aktif).
    expect($clanA->fresh()->activeWar())->toBeNull();
    expect($clanB->fresh()->activeWar())->toBeNull();
});

it('does NOT finish early when only one clan has completed all 9 modes', function () {
    [$leaderA, $clanA, $leaderB, $clanB, $war] = warBetweenTwoClans();

    // Hanya clan A selesai semua; clan B belum -> war tetap berjalan sampai ends_at.
    submitAllModesForClan($war, $clanA, $leaderA, 10.0);

    Livewire::actingAs($leaderA)->test(ClanWar::class);

    $war->refresh();
    expect($war->status)->toBe(ClanWarStatus::Ongoing);
    // Clan A masih terikat war, belum bisa menantang clan lain.
    expect($clanA->fresh()->activeWar())->not->toBeNull();
});
