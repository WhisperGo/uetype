<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Livewire\ClanWar;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\EloCalculator;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

function makeClanWithLeader(string $name, int $power = 1000): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => $power]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    return [$leader, $clan];
}

/**
 * Bikin klaim mode war yang SUDAH disubmit dengan poin tertentu (langsung set
 * points, tak lewat typing engine) -- untuk menguji resolver berbasis poin.
 */
function submitWarClaim(ClanWarModel $war, Clan $clan, User $user, string $mode, string $config, float $points): ClanWarModeClaim
{
    $result = TypingResult::create([
        'user_id' => $user->id, 'mode' => $mode, 'mode_config' => $config,
        'net_wpm' => 80, 'raw_wpm' => 85, 'accuracy' => 95, 'correct_chars' => 300,
        'incorrect_chars' => 5, 'duration_seconds' => 30, 'xp_earned' => 100,
    ]);

    return ClanWarModeClaim::create([
        'clan_war_id' => $war->id,
        'clan_id' => $clan->id,
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'typing_result_id' => $result->id,
        'points' => $points,
        'claimed_at' => now(),
    ]);
}

it('lets a leader challenge another free clan, creating a pending war', function () {
    [$leaderA, $clanA] = makeClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeClanWithLeader('Clan B');

    Livewire::actingAs($leaderA)
        ->test(ClanWar::class)
        ->call('challengeClan', $clanB->id);

    $this->assertDatabaseHas('clan_wars', [
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Pending->value,
    ]);
});

it('toasts the opponent leader when a war is challenged', function () {
    Event::fake([ClanUpdated::class]);
    [$leaderA] = makeClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeClanWithLeader('Clan B');

    Livewire::actingAs($leaderA)->test(ClanWar::class)->call('challengeClan', $clanB->id);

    Event::assertDispatched(ClanUpdated::class, function ($e) use ($leaderB) {
        return $e->userId === $leaderB->id
            && $e->notification['type'] === 'war-challenge';
    });
});

it('toasts the challenger leader when a war is accepted', function () {
    Event::fake([ClanUpdated::class]);
    [$leaderA, $clanA] = makeClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeClanWithLeader('Clan B');

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id, 'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Pending, 'accept_deadline_at' => now()->addHour(),
    ]);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('acceptChallenge', $war->id);

    Event::assertDispatched(ClanUpdated::class, function ($e) use ($leaderA) {
        return $e->userId === $leaderA->id
            && $e->notification['type'] === 'war-accepted';
    });
});

it('toasts the challenger leader when a war is declined', function () {
    Event::fake([ClanUpdated::class]);
    [$leaderA, $clanA] = makeClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeClanWithLeader('Clan B');

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id, 'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Pending, 'accept_deadline_at' => now()->addHour(),
    ]);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('declineChallenge', $war->id);

    $war->refresh();
    expect($war->status)->toBe(ClanWarStatus::Declined);

    Event::assertDispatched(ClanUpdated::class, function ($e) use ($leaderA) {
        return $e->userId === $leaderA->id
            && $e->notification !== null
            && $e->notification['type'] === 'war-declined';
    });
});

it('does not let a non-leader of the opponent clan accept or decline a challenge (trust boundary)', function () {
    [$leaderA, $clanA] = makeClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeClanWithLeader('Clan B');
    $stranger = User::factory()->create();

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Pending,
        'accept_deadline_at' => now()->addHour(),
    ]);

    Livewire::actingAs($stranger)->test(ClanWar::class)->call('acceptChallenge', $war->id);

    $this->assertDatabaseHas('clan_wars', ['id' => $war->id, 'status' => ClanWarStatus::Pending->value]);
});

it('excludes clans already at war from the challengeable list and rejects challenging them directly', function () {
    [$leaderA, $clanA] = makeClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeClanWithLeader('Clan B');
    [$leaderC, $clanC] = makeClanWithLeader('Clan C');

    ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->addHour(),
        'challenger_power_before' => 1000,
        'opponent_power_before' => 1000,
        'started_at' => now(),
        'ends_at' => now()->addDays(3),
    ]);

    $component = Livewire::actingAs($leaderC)->test(ClanWar::class);
    $ids = $component->get('challengeableClans')->pluck('id');

    expect($ids)->not->toContain($clanA->id);
    expect($ids)->not->toContain($clanB->id);

    // Leader C mencoba menantang clan A yang sudah war -- harus ditolak server-side.
    $component->call('challengeClan', $clanA->id);
    $this->assertDatabaseMissing('clan_wars', ['challenger_clan_id' => $clanC->id, 'opponent_clan_id' => $clanA->id]);
});

it('snapshots power and sets the 3-day window when a challenge is accepted', function () {
    [$leaderA, $clanA] = makeClanWithLeader('Clan A', 1200);
    [$leaderB, $clanB] = makeClanWithLeader('Clan B', 1000);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Pending,
        'accept_deadline_at' => now()->addHour(),
    ]);

    Livewire::actingAs($leaderB)->test(ClanWar::class)->call('acceptChallenge', $war->id);

    $war->refresh();
    expect($war->status)->toBe(ClanWarStatus::Ongoing);
    expect($war->challenger_power_before)->toBe(1200);
    expect($war->opponent_power_before)->toBe(1000);
    expect($war->started_at)->not->toBeNull();
    expect($war->started_at->diffInDays($war->ends_at))->toEqual(3);
});

it('expires a pending challenge once its accept deadline has passed, on-the-fly via mount', function () {
    [$leaderA, $clanA] = makeClanWithLeader('Clan A');
    [$leaderB, $clanB] = makeClanWithLeader('Clan B');

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Pending,
        'accept_deadline_at' => now()->subMinute(),
    ]);

    // Cukup me-mount komponen (siapa pun) -- tak ada command yang dijalankan.
    Livewire::actingAs($leaderB)->test(ClanWar::class);

    $war->refresh();
    expect($war->status)->toBe(ClanWarStatus::Expired);
});

it('resolves a finished war on-the-fly, applying Elo deltas asymmetric to power difference', function () {
    [$leaderA, $clanA] = makeClanWithLeader('Clan A', 1500);
    [$leaderB, $clanB] = makeClanWithLeader('Clan B', 1300);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDays(4),
        'challenger_power_before' => 1500,
        'opponent_power_before' => 1300,
        'started_at' => now()->subDays(3),
        'ends_at' => now()->subMinute(),
    ]);

    // Clan A menang telak (poin klaim mode lebih tinggi) atas clan B.
    submitWarClaim($war, $clanA, $leaderA, 'time', '30', 80.0);
    submitWarClaim($war, $clanB, $leaderB, 'time', '30', 10.0);

    [$expectedDeltaA, $expectedDeltaB] = EloCalculator::calculate(1500, 1300, 1.0);

    Livewire::actingAs($leaderA)->test(ClanWar::class);

    $war->refresh();
    expect($war->status)->toBe(ClanWarStatus::Finished);
    expect($war->result)->toBe('win');
    expect($war->challenger_power_delta)->toBe($expectedDeltaA);
    expect($war->opponent_power_delta)->toBe($expectedDeltaB);

    // Menang vs lawan lebih lemah -> untung LEBIH SEDIKIT dari K penuh.
    expect($expectedDeltaA)->toBeLessThan(EloCalculator::K_FACTOR);
    expect($expectedDeltaA)->toBeGreaterThan(0);

    $clanA->refresh();
    $clanB->refresh();
    expect($clanA->power)->toBe(1500 + $expectedDeltaA);
    expect($clanB->power)->toBe(1300 + $expectedDeltaB);
});

it('resolves a draw with power-asymmetric deltas that still sum to zero', function () {
    [$leaderA, $clanA] = makeClanWithLeader('Clan A', 1500);
    [$leaderB, $clanB] = makeClanWithLeader('Clan B', 1700);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clanA->id,
        'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDays(4),
        'challenger_power_before' => 1500,
        'opponent_power_before' => 1700,
        'started_at' => now()->subDays(3),
        'ends_at' => now()->subMinute(),
    ]);

    // Poin klaim mode sama persis -> draw.
    submitWarClaim($war, $clanA, $leaderA, 'time', '30', 80.0);
    submitWarClaim($war, $clanB, $leaderB, 'time', '30', 80.0);

    Livewire::actingAs($leaderA)->test(ClanWar::class);

    $war->refresh();
    expect($war->result)->toBe('draw');

    // Clan A (lebih lemah) seri vs clan B (lebih kuat) -> A untung, B rugi, besaran sama.
    expect($war->challenger_power_delta)->toBeGreaterThan(0);
    expect($war->opponent_power_delta)->toBeLessThan(0);
    expect($war->challenger_power_delta + $war->opponent_power_delta)->toBe(0);
});

it('requires authentication to view the clan war page', function () {
    $this->get(route('clan-war.index'))->assertRedirect(route('login'));
});

it('elo calculator is zero-sum and rewards upsets more than expected wins', function () {
    [$deltaEqualWin] = EloCalculator::calculate(1000, 1000, 1.0);
    [$deltaUnderdogWin] = EloCalculator::calculate(1000, 1400, 1.0);
    [$deltaFavoriteWin] = EloCalculator::calculate(1400, 1000, 1.0);

    // Menang vs lawan setara -> sekitar K/2.
    expect($deltaEqualWin)->toBe((int) round(EloCalculator::K_FACTOR * 0.5));

    // Menang sebagai underdog (power lebih rendah) untung LEBIH BESAR daripada
    // menang sebagai favorit (power lebih tinggi).
    expect($deltaUnderdogWin)->toBeGreaterThan($deltaFavoriteWin);

    // Zero-sum: delta A + delta B selalu 0 untuk kombinasi power manapun.
    [$dA, $dB] = EloCalculator::calculate(1234, 987, 0.5);
    expect($dA + $dB)->toBe(0);
});
