<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\TypingEngine;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

/**
 * Clan War points are derived from a TypingResult (net_wpm, accuracy, duration_seconds),
 * so anything that fakes a solo result also fakes war points -- and war points move clan
 * power, which is permanent.
 *
 * Survival is the sharpest case: its points scale with duration_seconds, so a long claimed
 * duration is the REWARD rather than a penalty. That inverts the usual assumption that
 * over-claiming time only hurts the sender.
 */
function tamperWarClaim(string $mode, string $config): array
{
    $user = User::factory()->create();
    $clan = Clan::create(['name' => 'Alpha', 'leader_id' => $user->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $user->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $rival = User::factory()->create();
    $rivalClan = Clan::create(['name' => 'Beta', 'leader_id' => $rival->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $rivalClan->id, 'user_id' => $rival->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clan->id,
        'opponent_clan_id' => $rivalClan->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDay(),
        'challenger_power_before' => 1000,
        'opponent_power_before' => 1000,
        'started_at' => now()->subHours(2),
        'ends_at' => now()->addDays(3),
    ]);

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id,
        'clan_id' => $clan->id,
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'claimed_at' => now(),
    ]);

    return [$user, $claim];
}

it('refuses a survival claim of time that never passed', function () {
    [$user, $claim] = tamperWarClaim('survival', 'hard');

    // Survival hard has the highest ceiling (150) and scores on duration, so claiming
    // 9999 seconds of survival used to buy full points outright.
    Livewire::actingAs($user)->test(TypingEngine::class, ['warClaimId' => $claim->id])
        ->call('saveResult', 9999000, 6000, 6000, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    $claim->refresh();

    expect($claim->typing_result_id)->toBeNull()
        ->and((float) $claim->points)->toBe(0.0)
        ->and(TypingResult::count())->toBe(0);
});

it('refuses a fabricated wpm claim in a time-mode war slot', function () {
    [$user, $claim] = tamperWarClaim('time', '120');

    // 1500 chars "in" 120s = 150 WPM, exactly the ratio that maxes out the ceiling.
    Livewire::actingAs($user)->test(TypingEngine::class, ['warClaimId' => $claim->id])
        ->call('saveResult', 120000, 1500, 1500, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    $claim->refresh();

    expect($claim->typing_result_id)->toBeNull()
        ->and((float) $claim->points)->toBe(0.0);
});

it('refuses a fabricated words-mode war claim', function () {
    [$user, $claim] = tamperWarClaim('words', '10');

    // A 10-word text is ~50 characters; 4000 is pure invention.
    Livewire::actingAs($user)->test(TypingEngine::class, ['warClaimId' => $claim->id])
        ->call('saveResult', 60000, 4000, 4000, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing'));

    expect($claim->refresh()->typing_result_id)->toBeNull();
});

it('still scores an honest war attempt', function () {
    [$user, $claim] = tamperWarClaim('time', '30');

    // ~300 characters over a 30-second slot is an ordinary ~60 WPM run.
    Livewire::actingAs($user)->test(TypingEngine::class, ['warClaimId' => $claim->id])
        ->call('saveResult', 30000, 300, 290, [], [], [], 0, 0, '', 0)
        ->assertRedirect(route('typing.result'));

    $claim->refresh();

    expect($claim->typing_result_id)->not->toBeNull()
        ->and((float) $claim->points)->toBeGreaterThan(0.0)
        // Well short of the 80-point ceiling for time/30: honest, not maxed.
        ->and((float) $claim->points)->toBeLessThan(80.0);
});
