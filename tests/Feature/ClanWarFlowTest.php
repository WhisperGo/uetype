<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\ClanWar;
use App\Livewire\TypingEngine;
use App\Livewire\TypingResult;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult as TypingResultModel;
use App\Models\User;
use Livewire\Livewire;

/**
 * Alur Clan War dari sisi PEMAIN (bukan integritas skor): melihat poin lawan,
 * dan kembali ke Clan War setelah menyelesaikan / gagal sebuah war attempt --
 * bukan terlempar ke sesi solo tanpa jalan pulang.
 */

/** Bikin satu clan + leader-nya (helper lokal file ini). */
function flowClanWithLeader(string $name, int $power = 1000): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => $power]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    return [$leader, $clan];
}

/** Dua clan yang sedang berperang (Ongoing), dengan leader masing-masing. */
function ongoingWarBetween(): array
{
    [$leaderA, $clanA] = flowClanWithLeader('Alpha');
    [$leaderB, $clanB] = flowClanWithLeader('Bravo');

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Ongoing,
        'challenger_power_before' => $clanA->power,
        'opponent_power_before' => $clanB->power,
        'accept_deadline_at' => now()->subHour(),
        'started_at' => now(),
        'ends_at' => now()->addDays(3),
    ]);

    return [$war, $leaderA, $clanA, $leaderB, $clanB];
}

/** Klaim mode yang SUDAH disubmit dengan poin tertentu untuk satu clan. */
function flowSubmittedClaim(ClanWarModel $war, Clan $clan, User $user, string $mode, string $config, float $points): ClanWarModeClaim
{
    $result = TypingResultModel::create([
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

it('exposes the opponent clan points, counting only submitted claims', function () {
    [$war, $leaderA, $clanA, $leaderB, $clanB] = ongoingWarBetween();

    // Opponent (Bravo): one submitted (50) + one unsubmitted (0-counted).
    flowSubmittedClaim($war, $clanB, $leaderB, 'time', '30', 50.0);
    ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanB->id, 'user_id' => $leaderB->id,
        'mode' => 'time', 'mode_config' => '15', 'claimed_at' => now(),
    ]);

    // My clan (Alpha): a submitted 30 -- must NOT bleed into the opponent total.
    flowSubmittedClaim($war, $clanA, $leaderA, 'words', '25', 30.0);

    $component = Livewire::actingAs($leaderA)->test(ClanWar::class);

    expect((float) $component->instance()->opponentClanPoints)->toBe(50.0)
        ->and((float) $component->instance()->myClanPoints)->toBe(30.0);
});

it('shows both clans point totals on the ongoing war header', function () {
    [$war, $leaderA, $clanA, $leaderB, $clanB] = ongoingWarBetween();
    flowSubmittedClaim($war, $clanB, $leaderB, 'time', '30', 42.0);

    Livewire::actingAs($leaderA)->test(ClanWar::class)
        ->assertSee(__('clan.war.your_points'))
        ->assertSee(__('clan.war.opponent_points'));
});

it('sends an honest war attempt back to the clan war page, not solo', function () {
    [$war, $leaderA, $clanA] = ongoingWarBetween();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clanA->id, 'user_id' => $leaderA->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($leaderA)->test(TypingEngine::class, ['warClaimId' => $claim->id]);

    runWarAttemptClock($claim);

    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 290])
        ->assertRedirect(route('typing.result'));

    // The result screen must know this was a war attempt so it can offer the way back.
    expect(session('typing_result')['war'] ?? null)->not->toBeNull();
});

it('offers a back-to-clan-war action on a war result instead of next test', function () {
    session()->put('typing_result', [
        'wpm' => 60, 'rawWpm' => 65, 'accuracy' => 95, 'time' => 30,
        'mode' => 'time', 'subMode' => '30', 'war' => ['mode' => 'time', 'config' => '30'],
    ]);

    Livewire::test(TypingResult::class)
        ->assertSee(__('result.back_to_war'))
        ->assertDontSee(__('result.next_test_title'));
});

it('keeps the normal next-test action on a solo result', function () {
    session()->put('typing_result', [
        'wpm' => 60, 'rawWpm' => 65, 'accuracy' => 95, 'time' => 30,
        'mode' => 'time', 'subMode' => '30',
    ]);

    Livewire::test(TypingResult::class)
        ->assertSee(__('result.next_test_title'))
        ->assertDontSee(__('result.back_to_war'));
});
