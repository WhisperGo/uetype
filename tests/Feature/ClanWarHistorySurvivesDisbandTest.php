<?php

use App\Enums\ClanWarStatus;
use App\Livewire\ClanLeaderboard;
use App\Livewire\Clans;
use App\Livewire\ClanShow;
use App\Models\Clan;
use App\Models\ClanWar;
use App\Models\User;
use Livewire\Livewire;

/**
 * Perang yang sudah SELESAI adalah fakta tentang DUA clan, jadi bubarnya salah satu tak boleh
 * menghapusnya.
 *
 * Dulu `clan_wars` cascade dari kedua sisi. Pembubaran diblokir saat war Pending/Ongoing, tapi
 * war Finished tak pernah tercakup: clan A bubar, barisnya hilang, dan clan B kehilangan
 * riwayat yang ia menangkan -- sementara Elo dari war itu tetap menempel di `clans.power`.
 * Papan lalu menampilkan clan yang power-nya tak bisa dijelaskan oleh rekamannya sendiri, dan
 * tak ada yang error: angkanya cuma diam-diam salah sejak saat itu.
 */
// userInClan() datang dari tests/Pest.php.

function finishedWarBetween(Clan $challenger, Clan $opponent, string $result = 'win'): ClanWar
{
    return ClanWar::create([
        'challenger_clan_id' => $challenger->id,
        'opponent_clan_id' => $opponent->id,
        'status' => ClanWarStatus::Finished,
        'result' => $result,
        'accept_deadline_at' => now()->subDays(4),
        'challenger_power_before' => 1000,
        'opponent_power_before' => 1000,
        'challenger_power_delta' => 16,
        'opponent_power_delta' => -16,
        'started_at' => now()->subDays(4),
        'ends_at' => now()->subDays(1),
    ]);
}

/** Bubarkan clan lewat komponen, persis seperti leader-nya menekan tombol. */
function disband(User $leader, Clan $clan): void
{
    Livewire::actingAs($leader)->test(Clans::class)
        ->set('confirmDisbandName', $clan->name)
        ->call('disbandClan');
}

it('snapshots both clan names when a war is created', function () {
    [, $alpha] = userInClan('Alpha');
    [, $bravo] = userInClan('Bravo');

    $war = finishedWarBetween($alpha, $bravo);

    // Ditulis oleh ClanWar::booted(), bukan oleh pemanggilnya: cara baru memulai war tak boleh
    // bisa lupa mengisi snapshot yang justru dibuat untuk hidup lebih lama dari clan-nya.
    expect($war->challenger_name)->toBe('Alpha')
        ->and($war->opponent_name)->toBe('Bravo');
});

it('keeps the war row when the opposing clan disbands', function () {
    [$alphaLeader, $alpha] = userInClan('Alpha');
    [, $bravo] = userInClan('Bravo');

    $war = finishedWarBetween($alpha, $bravo);

    disband($alphaLeader, $alpha);

    expect(Clan::find($alpha->id))->toBeNull()
        ->and(ClanWar::find($war->id))->not->toBeNull()
        // Sisi yang bubar jadi null; sisi yang bertahan tetap utuh.
        ->and(ClanWar::find($war->id)->challenger_clan_id)->toBeNull()
        ->and(ClanWar::find($war->id)->opponent_clan_id)->toBe($bravo->id);
});

it('still names the disbanded opponent in the surviving clans history', function () {
    [$alphaLeader, $alpha] = userInClan('Alpha');
    [, $bravo] = userInClan('Bravo');

    finishedWarBetween($alpha, $bravo);

    disband($alphaLeader, $alpha);

    $summary = $bravo->warSummary($bravo->finishedWars()->first());

    // Modelnya memang hilang -- tak ada halaman clan untuk dituju -- tapi namanya tidak,
    // sehingga barisnya tetap terbaca sebagai kalimat, bukan lubang.
    expect($summary['opponent'])->toBeNull()
        ->and($summary['opponent_name'])->toBe('Alpha')
        // Bravo kalah sebagai opponent dari war ber-result 'win'.
        ->and($summary['result'])->toBe('loss');
});

it('does not shrink the surviving clans win count when its rival disbands', function () {
    $viewer = User::factory()->create();
    [$alphaLeader, $alpha] = userInClan('Alpha');
    [, $bravo] = userInClan('Bravo');

    // Bravo menang: ia opponent di war ber-result 'loss' (result ditulis dari sudut challenger).
    finishedWarBetween($alpha, $bravo, result: 'loss');

    $winsOf = fn () => Livewire::actingAs($viewer)->test(ClanLeaderboard::class)
        ->get('ranking')->firstWhere('clan.id', $bravo->id)['wins'];

    expect($winsOf())->toBe(1);

    disband($alphaLeader, $alpha);

    // Inti temuannya: kemenangan Bravo dulu ikut menyusut jadi 0 di sini, sementara power
    // yang ia dapat dari war itu tetap tinggal.
    expect($winsOf())->toBe(1);
});

it('renders the clan detail history without a link when the opponent is gone', function () {
    $viewer = User::factory()->create();
    [$alphaLeader, $alpha] = userInClan('Alpha');
    [, $bravo] = userInClan('Bravo');

    finishedWarBetween($alpha, $bravo);

    disband($alphaLeader, $alpha);

    // Halaman harus tetap dirender: tanpa penjagaan null, route('clans.show', null) fatal.
    Livewire::actingAs($viewer)->test(ClanShow::class, ['clan' => $bravo])
        ->assertOk()
        ->assertSee('Alpha');
});
