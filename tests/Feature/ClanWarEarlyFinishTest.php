<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\ClanWarAttempt;
use App\Services\ClanWarModeCatalog;
use App\Services\ClanWarResolver;

/**
 * Early finish dulu menuntut 9/9 di KEDUA sisi, dan itu menggantung war selamanya.
 *
 * Clan yang tak sanggup mencapai 9 -- rosternya menyusut, atau seorang anggota membuka
 * percobaan lalu menghilang -- tak akan pernah memenuhinya, sehingga clan LAWAN yang sudah
 * mengerjakan semuanya tetap wajib menunggu tiga hari penuh. Hukumannya jatuh ke pihak yang
 * tak melakukan kesalahan apa pun.
 *
 * Aturannya kini "tak ada lagi yang bisa dimainkan". Clan yang malas tetap tak bisa memicunya,
 * karena kemalasan selalu terbaca sebagai PENDING.
 */
function earlyFinishWar(int $challengerMembers = 1, int $cap = 4): array
{
    $makeClan = function (string $name, int $memberCount) {
        $leader = User::factory()->create();
        $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => 1000]);
        ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

        for ($i = 1; $i < $memberCount; $i++) {
            $mate = User::factory()->create();
            ClanMember::create(['clan_id' => $clan->id, 'user_id' => $mate->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);
        }

        return [$clan, $leader];
    };

    [$mine, $myLeader] = $makeClan('Alpha Finish', $challengerMembers);
    [$theirs, $theirLeader] = $makeClan('Beta Finish', 3);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $mine->id,
        'opponent_clan_id' => $theirs->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDay(),
        'challenger_power_before' => 1000,
        'opponent_power_before' => 1000,
        'challenger_max_claims' => $cap,
        'opponent_max_claims' => 4,
        'started_at' => now()->subHours(2),
        // Jauh di masa depan: apa pun yang terjadi di bawah adalah early finish, bukan jatuh tempo.
        'ends_at' => now()->addDays(3),
    ]);

    return [$war, $mine, $myLeader, $theirs, $theirLeader];
}

/** Isi $count slot untuk sebuah clan dengan hasil yang benar-benar tersubmit. */
function submitSlots(ClanWarModel $war, Clan $clan, User $player, int $count): void
{
    foreach (array_slice(ClanWarModeCatalog::MODES, 0, $count) as $mode) {
        $result = TypingResult::create([
            'user_id' => $player->id, 'mode' => $mode['mode'], 'mode_config' => $mode['config'],
            'net_wpm' => 60, 'raw_wpm' => 65, 'accuracy' => 95,
            'correct_chars' => 290, 'incorrect_chars' => 10, 'duration_seconds' => 30, 'xp_earned' => 10,
        ]);

        ClanWarModeClaim::create([
            'clan_war_id' => $war->id, 'clan_id' => $clan->id, 'user_id' => $player->id,
            'mode' => $mode['mode'], 'mode_config' => $mode['config'],
            'attempt_started_at' => now()->subHour(),
            'typing_result_id' => $result->id, 'points' => 40, 'claimed_at' => now()->subHour(),
        ]);
    }
}

it('finishes early once a shrunken clan has exhausted its quota', function () {
    // Cap 4 dan hanya satu anggota tersisa: 4 slot adalah SELURUH yang clan ini sanggup ambil.
    [$war, $mine, $myLeader, $theirs, $theirLeader] = earlyFinishWar(challengerMembers: 1, cap: 4);

    submitSlots($war, $mine, $myLeader, 4);
    submitSlots($war, $theirs, $theirLeader, 9);

    app(ClanWarResolver::class)->resolveDue();

    // Dulu ini menahan clan lawan tiga hari penuh padahal tak ada lagi yang bisa terjadi.
    expect($war->refresh()->status)->toBe(ClanWarStatus::Finished);
});

it('does not finish early while a clan still has quota and open slots', function () {
    [$war, $mine, $myLeader, $theirs, $theirLeader] = earlyFinishWar(challengerMembers: 3, cap: 4);

    submitSlots($war, $mine, $myLeader, 4);
    submitSlots($war, $theirs, $theirLeader, 9);

    app(ClanWarResolver::class)->resolveDue();

    // Dua rekan lain masih punya jatah penuh: pekerjaan clan ini belum habis.
    expect($war->refresh()->status)->toBe(ClanWarStatus::Ongoing);
});

it('does not finish early while a claim sits unopened', function () {
    [$war, $mine, $myLeader, $theirs, $theirLeader] = earlyFinishWar(challengerMembers: 1, cap: 4);

    submitSlots($war, $mine, $myLeader, 3);
    submitSlots($war, $theirs, $theirLeader, 9);

    // Slot keempat dipegang tapi belum pernah dibuka -- itu pekerjaan yang masih menunggu,
    // dan inilah yang mencegah clan malas memicu early finish.
    ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $mine->id, 'user_id' => $myLeader->id,
        'mode' => 'time', 'mode_config' => '120', 'claimed_at' => now(),
    ]);

    app(ClanWarResolver::class)->resolveDue();

    expect($war->refresh()->status)->toBe(ClanWarStatus::Ongoing);
});

it('does not finish early while an attempt is still in flight', function () {
    [$war, $mine, $myLeader, $theirs, $theirLeader] = earlyFinishWar(challengerMembers: 1, cap: 4);

    submitSlots($war, $mine, $myLeader, 3);
    submitSlots($war, $theirs, $theirLeader, 9);

    ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $mine->id, 'user_id' => $myLeader->id,
        'mode' => 'time', 'mode_config' => '120',
        'attempt_started_at' => now()->subMinute(),
        'claimed_at' => now()->subMinute(),
    ]);

    app(ClanWarResolver::class)->resolveDue();

    // Seseorang mungkin sedang mengetiknya saat ini juga.
    expect($war->refresh()->status)->toBe(ClanWarStatus::Ongoing);
});

it('treats a stale unfinished attempt as settled', function () {
    [$war, $mine, $myLeader, $theirs, $theirLeader] = earlyFinishWar(challengerMembers: 1, cap: 4);

    submitSlots($war, $mine, $myLeader, 3);
    submitSlots($war, $theirs, $theirLeader, 9);

    ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $mine->id, 'user_id' => $myLeader->id,
        'mode' => 'time', 'mode_config' => '120',
        // Jauh melewati durasi slot mana pun: tak ada yang masih mengerjakannya.
        'attempt_started_at' => now()->subMinutes(ClanWarAttempt::STALE_MINUTES + 5),
        'claimed_at' => now()->subHour(),
    ]);

    app(ClanWarResolver::class)->resolveDue();

    expect($war->refresh()->status)->toBe(ClanWarStatus::Finished);
});
