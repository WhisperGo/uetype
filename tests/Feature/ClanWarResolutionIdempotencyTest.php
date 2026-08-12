<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\User;
use App\Services\ClanWarResolver;

/**
 * Resolusi war menerapkan perubahan Elo TEPAT SEKALI, walau dua permintaan meresolusinya
 * bersamaan.
 *
 * `ClanWar::mount()` memanggil resolveDue() setiap kali siapa pun membuka /clan-war, dan momen
 * paling mungkin dua anggota membukanya bersamaan adalah persis saat war mereka berakhir. Dulu
 * status Finished ditulis SESUDAH increment('power'), jadi status bukan gerbang: dua pembaca
 * yang sama-sama melihat Ongoing keduanya menerapkan delta, dan power bergeser 2x.
 *
 * Kerusakannya permanen karena clans.power tak pernah dihitung ulang dari riwayat war -- tak ada
 * sumber kebenaran untuk membetulkannya sesudahnya.
 */
function dueWar(int $challengerPower = 1000, int $opponentPower = 1000): array
{
    $makeClan = function (string $name, int $power) {
        $leader = User::factory()->create();
        $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => $power]);
        ClanMember::create([
            'clan_id' => $clan->id, 'user_id' => $leader->id,
            'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
        ]);

        return $clan;
    };

    $challenger = $makeClan('Alpha Idem', $challengerPower);
    $opponent = $makeClan('Beta Idem', $opponentPower);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $challenger->id,
        'opponent_clan_id' => $opponent->id,
        'status' => ClanWarStatus::Ongoing,
        'accept_deadline_at' => now()->subDays(4),
        'challenger_power_before' => $challengerPower,
        'opponent_power_before' => $opponentPower,
        'started_at' => now()->subDays(4),
        // Sudah jatuh tempo: panggilan resolve berikutnya akan menutupnya.
        'ends_at' => now()->subMinute(),
    ]);

    return [$war, $challenger, $opponent];
}

it('applies the power change exactly once when a second resolver holds a stale war', function () {
    [$war, $challenger, $opponent] = dueWar();

    // Dua permintaan paralel sama-sama membaca war ini sebagai Ongoing SEBELUM salah satunya
    // sempat menutupnya. Instance basi inilah yang dipegang permintaan yang kalah balapan.
    $staleView = ClanWarModel::find($war->id);

    $resolver = app(ClanWarResolver::class);

    $resolver->resolveDue();

    $powerAfterFirst = $challenger->refresh()->power;
    $opponentAfterFirst = $opponent->refresh()->power;

    // Pemenang balapan sudah menutup war; yang kalah tetap memegang pandangan "Ongoing" dan
    // meneruskannya ke penyelesaian. Ia harus menolak, bukan menerapkan delta kedua.
    expect($resolver->settleWar($staleView))->toBeFalse();

    expect($challenger->refresh()->power)->toBe($powerAfterFirst);
    expect($opponent->refresh()->power)->toBe($opponentAfterFirst);
});

it('keeps the stored delta equal to the power actually applied', function () {
    [$war, $challenger, $opponent] = dueWar();

    app(ClanWarResolver::class)->resolveDue();

    $war->refresh();

    // Kalau delta pernah diterapkan dua kali, angka tersimpan ini berhenti menjelaskan power --
    // dan itu justru satu-satunya jejak yang tersisa untuk mendeteksinya.
    expect($challenger->refresh()->power)
        ->toBe($war->challenger_power_before + $war->challenger_power_delta);

    expect($opponent->refresh()->power)
        ->toBe($war->opponent_power_before + $war->opponent_power_delta);
});

it('leaves power untouched when a war is settled twice through resolveDue', function () {
    [$war, $challenger, $opponent] = dueWar();

    $resolver = app(ClanWarResolver::class);

    $resolver->resolveDue();
    $settledPower = $challenger->refresh()->power;

    $resolver->resolveDue();

    expect($challenger->refresh()->power)->toBe($settledPower);
    expect($war->refresh()->status)->toBe(ClanWarStatus::Finished);
});

it('still settles a due war exactly once, awarding the winner', function () {
    // Challenger jauh lebih lemah dan kedua sisi nol poin -> seri, tapi Elo tetap bergerak
    // karena yang lemah "menahan" yang kuat. Menguji resolusi tetap TERJADI, bukan sekadar
    // tidak terjadi dua kali.
    [$war, $challenger, $opponent] = dueWar(challengerPower: 800, opponentPower: 1200);

    app(ClanWarResolver::class)->resolveDue();

    expect($war->refresh()->status)->toBe(ClanWarStatus::Finished);
    expect($war->result)->toBe('draw');
    expect($war->challenger_power_delta)->toBeGreaterThan(0);
    // Zero-sum: apa yang didapat satu sisi persis yang dilepas sisi lain.
    expect($war->opponent_power_delta)->toBe(-$war->challenger_power_delta);
});
