<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\TypingEngine;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarFixedText;
use App\Models\ClanWarModeClaim;
use App\Models\User;
use Livewire\Livewire;

/**
 * Bikin satu clan yang sedang perang + user leader-nya, kembalikan
 * [leader, clan, war]. Berbeda war/clan tiap panggilan supaya bisa uji
 * bahwa teks tetap identik LINTAS war/clan.
 */
function clanInWar(string $clanName): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => $clanName, 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $opponentLeader = User::factory()->create();
    $opponent = Clan::create(['name' => $clanName.' Opp', 'leader_id' => $opponentLeader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $opponent->id, 'user_id' => $opponentLeader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clan->id,
        'opponent_clan_id' => $opponent->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDay(),
        'challenger_power_before' => 1000,
        'opponent_power_before' => 1000,
        'started_at' => now()->subHours(2),
        'ends_at' => now()->addDays(3),
    ]);

    return [$leader, $clan, $war];
}

function wordsClaimFor(ClanWarModel $war, Clan $clan, User $user, string $config): ClanWarModeClaim
{
    return ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clan->id, 'user_id' => $user->id,
        'mode' => 'words', 'mode_config' => $config, 'claimed_at' => now(),
    ]);
}

it('seeds exactly the 4 words fixed texts with correct word counts', function () {
    expect(ClanWarFixedText::count())->toBe(4);

    foreach (['10' => 10, '25' => 25, '50' => 50, '100' => 100] as $config => $expected) {
        $content = ClanWarFixedText::forWords($config);
        expect($content)->not->toBeNull();
        expect(str_word_count($content))->toBe($expected);
    }
});

it('gives every player the identical locked text for the same words mode across different wars', function () {
    [$leaderA, $clanA, $warA] = clanInWar('Alpha');
    [$leaderB, $clanB, $warB] = clanInWar('Bravo');

    $claimA = wordsClaimFor($warA, $clanA, $leaderA, '50');
    $claimB = wordsClaimFor($warB, $clanB, $leaderB, '50');

    $textA = Livewire::actingAs($leaderA)
        ->withQueryParams(['war_claim' => $claimA->id])
        ->test(TypingEngine::class)
        ->get('textToType');

    $textB = Livewire::actingAs($leaderB)
        ->withQueryParams(['war_claim' => $claimB->id])
        ->test(TypingEngine::class)
        ->get('textToType');

    // Byte-for-byte identik -- itulah inti keadilannya.
    expect($textA)->toBe($textB);
    expect($textA)->toBe(ClanWarFixedText::forWords('50'));
});

it('does not reroll the words text when restart is called during war-lock', function () {
    [$leader, $clan, $war] = clanInWar('Alpha');
    $claim = wordsClaimFor($war, $clan, $leader, '25');

    $component = Livewire::actingAs($leader)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    $before = $component->get('textToType');
    $component->call('restart');
    $after = $component->get('textToType');

    expect($after)->toBe($before);
    expect($after)->toBe(ClanWarFixedText::forWords('25'));
});

it('does not reroll a time-mode war attempt when restart is called during war-lock', function () {
    [$leader, $clan, $war] = clanInWar('Alpha');
    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $clan->id, 'user_id' => $leader->id,
        'mode' => 'time', 'mode_config' => '60', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($leader)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    $before = $component->get('textToType');
    $component->call('restart');
    $after = $component->get('textToType');

    // Time tidak pakai teks tetap, tapi restart tetap diblokir -> teks tak berubah.
    expect($after)->toBe($before);
});

it('still lets a normal solo session reroll its text on restart (no regression)', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', 'words', '100');

    $before = $component->get('textToType');

    // Ulangi beberapa kali; sesi solo harus bebas menghasilkan teks berbeda.
    $changed = false;
    for ($i = 0; $i < 5; $i++) {
        $component->call('restart');
        if ($component->get('textToType') !== $before) {
            $changed = true;
            break;
        }
    }

    expect($changed)->toBeTrue();
});
