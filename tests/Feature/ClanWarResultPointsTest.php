<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
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
 * Layar hasil sebuah war attempt harus memberi tahu APA yang diterima clan dari run itu.
 * Sebelumnya poin memang sudah dihitung & tersimpan ke clan_war_mode_claims.points, tapi
 * dibuang sebagai variabel lokal -- pemain menyelesaikan slot lalu kembali ke halaman war
 * tanpa pernah tahu kontribusinya berapa.
 */

/** Satu clan + leader-nya, plus war Ongoing melawan clan lain. Mengembalikan [user, clan, war]. */
function pointsWarFixture(): array
{
    $me = User::factory()->create();
    $myClan = Clan::create(['name' => 'Alpha', 'leader_id' => $me->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $myClan->id, 'user_id' => $me->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

    $rival = User::factory()->create();
    $rivalClan = Clan::create(['name' => 'Bravo', 'leader_id' => $rival->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $rivalClan->id, 'user_id' => $rival->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $myClan->id, 'opponent_clan_id' => $rivalClan->id,
        'status' => ClanWarStatus::Ongoing,
        'challenger_power_before' => 1000, 'opponent_power_before' => 1000,
        'accept_deadline_at' => now()->subHour(),
        'started_at' => now(), 'ends_at' => now()->addDays(3),
    ]);

    return [$me, $myClan, $war];
}

/** Isi session hasil ketik dengan konteks war yang sudah jadi (tanpa lewat typing engine). */
function pointsResultSession(?array $score, string $mode = 'time', string $config = '30'): void
{
    session()->put('typing_result', [
        'wpm' => 60, 'rawWpm' => 65, 'accuracy' => 95, 'time' => 30,
        'mode' => $mode, 'subMode' => $config,
        'totalKeystrokes' => 300, 'correctKeystrokes' => 290, 'incorrectKeystrokes' => 10,
        'war' => ['mode' => $mode, 'config' => $config, 'score' => $score],
    ]);
}

it('membawa poin yang benar-benar diberikan ke session hasil', function () {
    [$me, $myClan, $war] = pointsWarFixture();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $myClan->id, 'user_id' => $me->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($me)->test(TypingEngine::class, ['warClaimId' => $claim->id]);
    runWarAttemptClock($claim);
    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 290]);

    $score = session('typing_result')['war']['score'];

    expect($score)->not->toBeNull()
        // Angka di layar HARUS angka yang diterima scoreboard, bukan hitungan kedua.
        ->and($score['points'])->toBe((float) $claim->fresh()->points)
        ->and($score['ceiling'])->toBe(80)
        ->and($score['basis'])->toBe('wpm')
        // Pipa internal tak boleh bocor ke view.
        ->and($score)->not->toHaveKey('war_id');
});

it('tidak melaporkan poin apa pun kalau slotnya sudah diisi rekan sekelompok', function () {
    [$me, $myClan, $war] = pointsWarFixture();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $myClan->id, 'user_id' => $me->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($me)->test(TypingEngine::class, ['warClaimId' => $claim->id]);

    // Rekan menyelesaikan slot yang sama di tengah run kita.
    $theirs = TypingResultModel::create([
        'user_id' => $me->id, 'mode' => 'time', 'mode_config' => '30',
        'net_wpm' => 70, 'raw_wpm' => 75, 'accuracy' => 92, 'correct_chars' => 250,
        'incorrect_chars' => 8, 'duration_seconds' => 30, 'xp_earned' => 90,
    ]);
    $claim->update(['typing_result_id' => $theirs->id, 'points' => 44.0]);

    runWarAttemptClock($claim);
    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 290]);

    expect(session('typing_result')['war']['score'])->toBeNull()
        // Poin milik rekan tak boleh tertimpa.
        ->and((float) $claim->fresh()->points)->toBe(44.0);
});

it('tak pernah membuang war attempt sebagai sesi yang ditinggalkan', function () {
    [$me, $myClan, $war] = pointsWarFixture();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $myClan->id, 'user_id' => $me->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    $component = Livewire::actingAs($me)->test(TypingEngine::class, ['warClaimId' => $claim->id]);
    runWarAttemptClock($claim);

    // Jeda 25 detik dari sesi 30 detik: jauh di atas ambang AFK biasa. Di war ini SENGAJA
    // tidak menolak -- hasil yang ditolak tak mengisi claim, jadi berhenti mengetik akan jadi
    // cara membuang percobaan buruk lalu mengulang slot yang sama (ClanWarRerollTest).
    $component->call('saveResult', [
        'durationMs' => 30000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 290,
        'maxIdleMs' => 25000,
    ]);

    expect(session('typing_result')['afk'])->toBeFalse()
        ->and(session('typing_result')['war']['score'])->not->toBeNull();
});

it('menampilkan poin, ceiling, dan kedua faktornya di layar hasil war', function () {
    $user = User::factory()->create();

    pointsResultSession([
        'ceiling' => 100, 'basis' => 'wpm', 'basis_value' => 112.0, 'basis_scale' => 150,
        'performance_ratio' => 0.7467, 'accuracy_multiplier' => 0.98, 'points' => 73.18,
    ], 'time', '60');

    Livewire::actingAs($user)->test(TypingResult::class)
        ->assertSee(__('result.war.heading'))
        ->assertSee('73.2')   // poin
        ->assertSee('100')    // ceiling
        ->assertSee('112')    // faktor kecepatan
        ->assertSee('0.98');  // faktor akurasi
});

it('menyebut durasi, bukan wpm, pada hasil war survival', function () {
    $user = User::factory()->create();

    pointsResultSession([
        'ceiling' => 150, 'basis' => 'duration', 'basis_value' => 45.0, 'basis_scale' => 90,
        'performance_ratio' => 0.5, 'accuracy_multiplier' => 1.0, 'points' => 75.0,
    ], 'survival', 'hard');

    Livewire::actingAs($user)->test(TypingResult::class)
        ->assertSee(__('result.war.factor_pace_survival', ['value' => '45', 'ratio' => '0.50', 'scale' => 90]))
        ->assertDontSee(__('result.war.factor_pace_wpm', ['value' => '45', 'ratio' => '0.50', 'scale' => 90]));
});

it('mengatakan attempt-nya tak dihitung alih-alih memajang poin yang tak pernah diberikan', function () {
    $user = User::factory()->create();

    pointsResultSession(null);

    Livewire::actingAs($user)->test(TypingResult::class)
        ->assertSee(__('result.war.not_counted_title'))
        ->assertDontSee(__('result.war.heading'))
        // Jalan pulang tetap ditawarkan.
        ->assertSee(__('result.back_to_war'));
});

it('tetap merender session lama yang belum punya kunci score', function () {
    $user = User::factory()->create();

    session()->put('typing_result', [
        'wpm' => 60, 'rawWpm' => 65, 'accuracy' => 95, 'time' => 30,
        'mode' => 'time', 'subMode' => '30',
        'war' => ['mode' => 'time', 'config' => '30'],
    ]);

    Livewire::actingAs($user)->test(TypingResult::class)
        ->assertOk()
        ->assertSee(__('result.back_to_war'));
});

it('tidak menampilkan panel war pada hasil solo', function () {
    $user = User::factory()->create();

    session()->put('typing_result', [
        'wpm' => 60, 'rawWpm' => 65, 'accuracy' => 95, 'time' => 30,
        'mode' => 'time', 'subMode' => '30',
    ]);

    Livewire::actingAs($user)->test(TypingResult::class)
        ->assertDontSee(__('result.war.heading'))
        ->assertDontSee(__('result.war.not_counted_title'));
});

it('tak memberi tamu konteks war sama sekali', function () {
    [$me, $myClan, $war] = pointsWarFixture();

    $claim = ClanWarModeClaim::create([
        'clan_war_id' => $war->id, 'clan_id' => $myClan->id, 'user_id' => $me->id,
        'mode' => 'time', 'mode_config' => '30', 'claimed_at' => now(),
    ]);

    // Tamu memakai war_claim milik orang lain: resolveWarClaim() menolak, warLock tetap null.
    $component = Livewire::test(TypingEngine::class, ['warClaimId' => $claim->id]);
    runWarAttemptClock($claim);
    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 290]);

    expect(session('typing_result')['war'])->toBeNull();
});
