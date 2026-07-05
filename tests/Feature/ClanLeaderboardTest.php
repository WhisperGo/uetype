<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\ClanLeaderboard;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

function makeClan(string $name, int $power): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => $power]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    return [$leader, $clan];
}

it('ranks clans by power descending on the leaderboard', function () {
    $me = User::factory()->create();
    [, $weak] = makeClan('Weak', 900);
    [, $strong] = makeClan('Strong', 1400);
    [, $mid] = makeClan('Mid', 1100);

    $ranking = Livewire::actingAs($me)->test(ClanLeaderboard::class)->get('ranking');

    expect($ranking->pluck('clan.id')->all())->toBe([$strong->id, $mid->id, $weak->id]);
});

it('counts wins per clan on the leaderboard', function () {
    $me = User::factory()->create();
    [, $clanA] = makeClan('Clan A', 1000);
    [, $clanB] = makeClan('Clan B', 1000);

    // Clan A menang sebagai challenger.
    ClanWar::create([
        'challenger_clan_id' => $clanA->id, 'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Finished, 'result' => 'win',
        'accept_deadline_at' => now()->subDays(4), 'challenger_power_before' => 1000,
        'opponent_power_before' => 1000, 'challenger_power_delta' => 16, 'opponent_power_delta' => -16,
        'started_at' => now()->subDays(4), 'ends_at' => now()->subDays(1),
    ]);
    // Clan A menang lagi, kali ini sebagai opponent (result 'loss' dari sudut challenger).
    ClanWar::create([
        'challenger_clan_id' => $clanB->id, 'opponent_clan_id' => $clanA->id,
        'status' => ClanWarStatus::Finished, 'result' => 'loss',
        'accept_deadline_at' => now()->subDays(4), 'challenger_power_before' => 1000,
        'opponent_power_before' => 1000, 'challenger_power_delta' => -16, 'opponent_power_delta' => 16,
        'started_at' => now()->subDays(4), 'ends_at' => now()->subDays(1),
    ]);

    $ranking = Livewire::actingAs($me)->test(ClanLeaderboard::class)->get('ranking');
    $rowA = $ranking->firstWhere('clan.id', $clanA->id);
    $rowB = $ranking->firstWhere('clan.id', $clanB->id);

    expect($rowA['wins'])->toBe(2);
    expect($rowB['wins'])->toBe(0);
});

it('renders a clan detail page with its match history from both perspectives', function () {
    $me = User::factory()->create();
    [, $clanA] = makeClan('Alpha', 1000);
    [, $clanB] = makeClan('Bravo', 1000);

    ClanWar::create([
        'challenger_clan_id' => $clanA->id, 'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Finished, 'result' => 'win',
        'accept_deadline_at' => now()->subDays(4), 'challenger_power_before' => 1000,
        'opponent_power_before' => 1000, 'challenger_power_delta' => 16, 'opponent_power_delta' => -16,
        'started_at' => now()->subDays(4), 'ends_at' => now()->subDays(1),
    ]);

    // Dari sisi clan A (challenger, menang): WIN, +16, vs Bravo.
    actingAs($me)->get(route('clans.show', $clanA))
        ->assertOk()
        ->assertSee('Alpha')
        ->assertSee('Bravo')
        ->assertSee('win')
        ->assertSee('+16');

    // Dari sisi clan B (opponent, kalah): LOSS, -16, vs Alpha.
    actingAs($me)->get(route('clans.show', $clanB))
        ->assertOk()
        ->assertSee('loss')
        ->assertSee('-16');
});

it('summarizes a war from the clan perspective correctly (challenger vs opponent inversion)', function () {
    [, $clanA] = makeClan('Alpha', 1000);
    [, $clanB] = makeClan('Bravo', 1000);

    $war = ClanWar::create([
        'challenger_clan_id' => $clanA->id, 'opponent_clan_id' => $clanB->id,
        'status' => ClanWarStatus::Finished, 'result' => 'win',
        'accept_deadline_at' => now()->subDays(4), 'challenger_power_before' => 1000,
        'opponent_power_before' => 1000, 'challenger_power_delta' => 16, 'opponent_power_delta' => -16,
        'started_at' => now()->subDays(4), 'ends_at' => now()->subDays(1),
    ]);

    $summaryA = $clanA->warSummary($war);
    expect($summaryA['result'])->toBe('win');
    expect($summaryA['delta'])->toBe(16);
    expect($summaryA['opponent']->id)->toBe($clanB->id);

    $summaryB = $clanB->warSummary($war);
    expect($summaryB['result'])->toBe('loss');
    expect($summaryB['delta'])->toBe(-16);
    expect($summaryB['opponent']->id)->toBe($clanA->id);
});

it('requires authentication to view the clan leaderboard and detail pages', function () {
    [, $clan] = makeClan('Alpha', 1000);

    $this->get(route('clan-leaderboard.index'))->assertRedirect(route('login'));
    $this->get(route('clans.show', $clan))->assertRedirect(route('login'));
});
