<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\TypingEngine;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\User;
use Livewire\Livewire;

/**
 * War-lock harus menutup SEMUA jalur reroll teks, bukan cuma restart().
 *
 * Sekali sebuah mode diklaim, pemain hanya dapat SATU kesempatan dengan SATU teks.
 * Kalau teks bisa diacak ulang, pemain tinggal reroll sampai dapat rangkaian kata
 * yang mudah -- keunggulan tak adil atas clan lawan yang mengerjakan mode sama.
 * restart() sudah diblokir untuk alasan ini; setContentLang() ternyata belum,
 * padahal ia juga memanggil generateText().
 */
function warClaimFor(string $mode, string $config): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Clan Reroll', 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $clan->id, 'user_id' => $leader->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

    $rivalLeader = User::factory()->create();
    $rivalClan = Clan::create(['name' => 'Clan Rival', 'leader_id' => $rivalLeader->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $rivalClan->id, 'user_id' => $rivalLeader->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

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
        'user_id' => $leader->id,
        'mode' => $mode,
        'mode_config' => $config,
        'claimed_at' => now(),
    ]);

    return [$leader, $claim];
}

/** REGRESI B6: mode time tak pakai teks tetap -> ganti bahasa dulu = reroll gratis. */
test('ganti bahasa saat war-lock tidak me-reroll teks (mode time)', function () {
    [$leader, $claim] = warClaimFor('time', '60');

    // war_claim adalah #[Url(as: 'war_claim')] -> masuk lewat query param, bukan mount param.
    $component = Livewire::actingAs($leader)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    $textAwal = $component->get('textToType');

    $component->call('setContentLang', 'id');

    expect($component->get('textToType'))->toBe($textAwal);
});

test('ganti bahasa saat war-lock tidak me-reroll teks (mode survival)', function () {
    [$leader, $claim] = warClaimFor('survival', 'hard');

    // war_claim adalah #[Url(as: 'war_claim')] -> masuk lewat query param, bukan mount param.
    $component = Livewire::actingAs($leader)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    $textAwal = $component->get('textToType');

    $component->call('setContentLang', 'id');

    expect($component->get('textToType'))->toBe($textAwal);
});

test('restart saat war-lock tetap tidak me-reroll teks', function () {
    [$leader, $claim] = warClaimFor('time', '60');

    // war_claim adalah #[Url(as: 'war_claim')] -> masuk lewat query param, bukan mount param.
    $component = Livewire::actingAs($leader)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    $textAwal = $component->get('textToType');

    $component->call('restart');

    expect($component->get('textToType'))->toBe($textAwal);
});

/** Di luar war, ganti bahasa memang HARUS mengganti teks (fitur normal). */
test('di sesi solo biasa, ganti bahasa tetap mengganti teks', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(TypingEngine::class);

    $textAwal = $component->get('textToType');

    $component->call('setContentLang', 'id');

    expect($component->get('textToType'))->not->toBe($textAwal)
        ->and($component->get('contentLang'))->toBe('id');
});
