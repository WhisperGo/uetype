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
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Sesi yang ditinggal pemain (AFK) bukan percobaan mengetik sungguhan: di mode time,
 * timer berjalan sendiri sampai habis lalu MENGIRIM hasilnya, jadi "ketik dua huruf lalu
 * pergi" mendarat di riwayat sebagai baris 1 WPM dan menyeret turun rata-rata pemain.
 *
 * Sinyal pembedanya adalah JEDA, bukan kecepatan rata-rata: pengetik lambat menyebar
 * ketikannya merata, sesi AFK punya satu sunyi panjang. Karena itu throughput tidak
 * dipakai di sini -- ambangnya akan ikut membuang pemula jujur (lihat test kedua).
 */
function afkSession(User $user, string $main, string $sub, float $backdate)
{
    $component = Livewire::actingAs($user)->test(TypingEngine::class)
        ->call('setMode', $main, $sub);

    // Pemain sungguhan menghabiskan durasinya untuk mengetik; test memanggil saveResult
    // seketika, yang tanpa ini terlihat seperti pemalsuan otomatis oleh guard elapsed-time.
    app(SoloSessionGuard::class)->backdate($backdate);

    return $component;
}

/** Satu clan + war Ongoing + satu slot mode yang diklaim, untuk menguji pengecualian war. */
function afkWarClaim(string $mode, string $config): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Clan AFK', 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $clan->id, 'user_id' => $leader->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

    $rivalLeader = User::factory()->create();
    $rival = Clan::create(['name' => 'Clan AFK Rival', 'leader_id' => $rivalLeader->id, 'power' => 1000]);
    ClanMember::create([
        'clan_id' => $rival->id, 'user_id' => $rivalLeader->id,
        'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active,
    ]);

    $war = ClanWarModel::create([
        'challenger_clan_id' => $clan->id,
        'opponent_clan_id' => $rival->id,
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

it('does not record a time-mode session the player walked away from', function () {
    $user = User::factory()->create();

    // time 60 -> ambang jeda 15 detik. Ketik sebentar lalu diam 40 detik sampai timer habis.
    afkSession($user, 'time', '60', 60)
        ->call('saveResult', 60000, 40, 40, [], [], [], 0, null, null, null, [], 40000)
        ->assertRedirect(route('typing.result'));

    // Tidak ditulis ke DB sama sekali: tanpa baris riwayat, tanpa XP, tanpa PB.
    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0)
        ->and($user->fresh()->total_xp)->toBe(0)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);

    // Tapi pemain tetap melihat layar hasilnya, ditandai agar banner bisa tampil.
    expect(session('typing_result')['afk'] ?? null)->toBeTrue();
});

/**
 * Penjaga false-positive yang paling penting. Pemula hunt-and-peck 5 WPM = 25 karakter
 * dalam 60 detik = 0.42 cps, DI BAWAH ambang throughput 0.5 -- jadi kalau deteksi AFK
 * memakai throughput, hasil pemula jujur ikut dibuang. Jeda-terpanjangnya kecil, jadi
 * aturan berbasis jeda tetap menyimpannya.
 */
it('still records a genuinely slow typist who never paused', function () {
    $user = User::factory()->create();

    afkSession($user, 'time', '60', 60)
        ->call('saveResult', 60000, 25, 25, [], [], [], 0, null, null, null, [], 4000);

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(1)
        ->and(session('typing_result')['afk'] ?? null)->toBeFalse();
});

/**
 * Ambang tetap akan berperilaku sangat berbeda antar sub-mode: jeda 12 detik itu hampir
 * seluruh sesi time 15, tapi cuma selingan wajar di time 120. Karena itu ambangnya
 * proporsional (25% durasi) dengan lantai 10 detik.
 */
it('scales the idle threshold with the length of the session', function () {
    // time 15 -> ambang = lantai 10 dtk. Jeda 12 dtk = AFK.
    $short = User::factory()->create();
    afkSession($short, 'time', '15', 15)
        ->call('saveResult', 15000, 40, 40, [], [], [], 0, null, null, null, [], 12000);

    expect(TypingResult::where('user_id', $short->id)->count())->toBe(0);

    // time 120 -> ambang = 30 dtk. Jeda 12 dtk cuma jeda berpikir, tetap dicatat.
    $long = User::factory()->create();
    afkSession($long, 'time', '120', 120)
        ->call('saveResult', 120000, 400, 400, [], [], [], 0, null, null, null, [], 12000);

    expect(TypingResult::where('user_id', $long->id)->count())->toBe(1);
});

/**
 * Pengecualian yang menutup celah, bukan sekadar batasan cakupan.
 *
 * Hasil yang DITOLAK tak pernah mengisi claim (penolakan terjadi sebelum transaksi yang
 * memanggil attachToWarClaim), jadi claim-nya tetap terbuka. Kalau AFK ikut menolak hasil
 * war, pemain yang sedang jelek tinggal berhenti mengetik untuk membuang percobaannya lalu
 * mengulang slot itu -- dan di war mode Words teksnya tetap, jadi ia mengulang dengan teks
 * yang sudah dilihatnya. Itu mementahkan aturan "satu claim, satu teks, satu kesempatan".
 */
/**
 * Hasilnya tetap DITAMPILKAN, cuma tidak dicatat. Jalur reject anti-cheat melempar pemain
 * balik ke /typing tanpa melihat apa pun -- untuk AFK itu terasa seperti sesinya ditelan
 * aplikasi, padahal pemain tak melakukan kesalahan apa-apa.
 */
it('shows the result screen with a not-recorded banner instead of swallowing the session', function () {
    $user = User::factory()->create();

    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 3.0, 'rawWpm' => 3, 'accuracy' => 100.0, 'time' => 60,
        'mode' => 'time', 'subMode' => '60', 'score' => null,
        'totalKeystrokes' => 40, 'correctKeystrokes' => 40, 'incorrectKeystrokes' => 0,
        'wpmHistory' => [5, 4, 3], 'rawHistory' => [5, 4, 3],
        'missedChars' => [], 'xpEarned' => 0,
        'isPersonalBest' => false, 'previousBest' => 0, 'consistency' => 70,
        'levelData' => ['level' => 1, 'progress' => 0, 'needed' => 100, 'next_level' => 2],
        'drainEventCount' => 0, 'survivalPreviousBest' => null, 'isSurvivalPersonalBest' => false,
        'ghostResult' => null, 'afk' => true,
    ]]);

    actingAs($user)->get('/result')
        ->assertOk()
        ->assertSee(__('result.afk_not_recorded'));
});

it('leaves the banner off a session that was actually played', function () {
    $user = User::factory()->create();

    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 60.0, 'rawWpm' => 63, 'accuracy' => 96.0, 'time' => 60,
        'mode' => 'time', 'subMode' => '60', 'score' => null,
        'totalKeystrokes' => 320, 'correctKeystrokes' => 300, 'incorrectKeystrokes' => 20,
        'wpmHistory' => [55, 60, 62], 'rawHistory' => [58, 63, 65],
        'missedChars' => [], 'xpEarned' => 20,
        'isPersonalBest' => false, 'previousBest' => 70, 'consistency' => 90,
        'levelData' => ['level' => 2, 'progress' => 40, 'needed' => 100, 'next_level' => 3],
        'drainEventCount' => 0, 'survivalPreviousBest' => null, 'isSurvivalPersonalBest' => false,
        'ghostResult' => null, 'afk' => false,
    ]]);

    actingAs($user)->get('/result')
        ->assertOk()
        ->assertDontSee(__('result.afk_not_recorded'));
});

it('keeps recording a war attempt even when the player went idle', function () {
    [$leader, $claim] = afkWarClaim('time', '60');

    $component = Livewire::actingAs($leader)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class);

    app(SoloSessionGuard::class)->backdate(60);

    // Jeda 45 detik: jauh di atas ambang, tapi sesi ini terkunci war.
    $component->call('saveResult', 60000, 200, 200, [], [], [], 0, null, null, null, [], 45000);

    expect(TypingResult::where('user_id', $leader->id)->count())->toBe(1)
        ->and($claim->fresh()->typing_result_id)->not->toBeNull();
});
