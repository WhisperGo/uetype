<?php

use App\Livewire\Stats;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Halaman /stats: seluruh agregat performa yang dulu menumpang di /profile.
 * Fokus tes: gerbang auth, agregat benar (termasuk survival yang dinilai dari
 * durasi, bukan WPM), dan halaman tetap utuh saat user belum punya satu tes pun.
 */
function makeResult(User $user, array $attributes = []): TypingResult
{
    return TypingResult::create(array_merge([
        'user_id' => $user->id,
        'mode' => 'time',
        'mode_config' => '30',
        'net_wpm' => 100,
        'raw_wpm' => 105,
        'accuracy' => 96,
        'correct_chars' => 250,
        'incorrect_chars' => 10,
        'duration_seconds' => 30,
    ], $attributes));
}

it('requires authentication', function () {
    $this->get(route('stats'))->assertRedirect(route('login'));
});

it('renders the stats page for a signed in user', function () {
    actingAs(User::factory()->create())
        ->get(route('stats'))
        ->assertOk()
        ->assertSee('All time Statistics');
});

it('renders without errors when the user has no typing results at all', function () {
    $user = User::factory()->create();

    // Kasus paling rawan: semua agregat nol / array kosong (avg() -> null,
    // max() pada array kosong -> error). Halaman harus tetap tampil.
    Livewire::actingAs($user)->test(Stats::class)
        ->assertOk()
        ->assertSee('No records yet.')
        ->assertSee('No tests yet.');
});

it('shows the best wpm per words and time config', function () {
    $user = User::factory()->create();

    makeResult($user, ['mode' => 'words', 'mode_config' => '15', 'net_wpm' => 131]);
    makeResult($user, ['mode' => 'words', 'mode_config' => '15', 'net_wpm' => 90]); // bukan rekor
    makeResult($user, ['mode' => 'words', 'mode_config' => '25', 'net_wpm' => 122]);
    makeResult($user, ['mode' => 'time', 'mode_config' => '15', 'net_wpm' => 124]);

    Livewire::actingAs($user)->test(Stats::class)
        ->assertSee('131')   // rekor 15 kata, bukan 90
        ->assertSee('122')
        ->assertSee('124')
        ->assertSee('15 words')
        ->assertSee('15 seconds');
});

it('ranks survival records by duration, not by wpm', function () {
    $user = User::factory()->create();

    // WPM tinggi tapi cepat mati; WPM rendah tapi bertahan lama.
    // Rekor survival harus mengambil yang BERTAHAN LEBIH LAMA.
    makeResult($user, ['mode' => 'survival', 'mode_config' => 'hard', 'net_wpm' => 150, 'duration_seconds' => 12]);
    makeResult($user, ['mode' => 'survival', 'mode_config' => 'hard', 'net_wpm' => 40, 'duration_seconds' => 167]);

    Livewire::actingAs($user)->test(Stats::class)
        ->assertSee('2m 47s')       // 167 detik
        ->assertDontSee('0m 12s');
});

it('translates survival difficulty labels', function () {
    $user = User::factory()->create();
    makeResult($user, ['mode' => 'survival', 'mode_config' => 'medium', 'duration_seconds' => 130]);

    Livewire::actingAs($user)->test(Stats::class)
        ->assertSee('Normal')       // 'medium' ditampilkan sebagai "Normal"
        ->assertSee('Survival - Time survived');
});

it('computes the activity totals', function () {
    $user = User::factory()->create();

    makeResult($user, ['duration_seconds' => 60, 'correct_chars' => 300, 'accuracy' => 90, 'net_wpm' => 100]);
    makeResult($user, ['duration_seconds' => 60, 'correct_chars' => 300, 'accuracy' => 100, 'net_wpm' => 120]);

    Livewire::actingAs($user)->test(Stats::class)
        ->assertSee('2m')     // total 120 detik
        ->assertSee('95%')    // rata-rata akurasi
        ->assertSee('110');   // rata-rata WPM
});

it('builds a mode distribution that sums to the tests actually taken', function () {
    $user = User::factory()->create();

    makeResult($user, ['mode' => 'words']);
    makeResult($user, ['mode' => 'words']);
    makeResult($user, ['mode' => 'time']);
    makeResult($user, ['mode' => 'survival', 'mode_config' => 'easy']);

    Livewire::actingAs($user)->test(Stats::class)
        ->assertSee('Words 50%')
        ->assertSee('Time 25%')
        ->assertSee('Survival 25%');
});

it('never shows a ghost slice, because ghost runs are stored as time or words rows', function () {
    $user = User::factory()->create();
    makeResult($user, ['mode' => 'words']);

    // TypingEngine tak pernah menulis mode 'ghost'; slice-nya akan selalu 0%.
    Livewire::actingAs($user)->test(Stats::class)->assertDontSee('Ghost');
});

it('only aggregates the signed in users own results', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    makeResult($me, ['mode' => 'words', 'mode_config' => '15', 'net_wpm' => 80]);
    makeResult($other, ['mode' => 'words', 'mode_config' => '15', 'net_wpm' => 199]);

    Livewire::actingAs($me)->test(Stats::class)
        ->assertSee('80')
        ->assertDontSee('199');
});

it('limits the chart series to the selected day range', function () {
    $user = User::factory()->create();

    $old = makeResult($user, ['net_wpm' => 55]);
    $old->created_at = now()->subDays(20);
    $old->save();

    makeResult($user, ['net_wpm' => 77]);

    // 7 hari: hanya sesi baru. 30 hari: keduanya.
    $component = Livewire::actingAs($user)->test(Stats::class, ['range' => '7']);
    expect($component->viewData('series')['wpm'])->toBe([77.0]);

    $component->call('setRange', '30');
    expect($component->viewData('series')['wpm'])->toBe([55.0, 77.0]);
});

it('rejects an out of range value from the query string', function () {
    $user = User::factory()->create();

    // $range masuk lewat #[Url] -> dikendalikan klien, jadi harus di-whitelist.
    Livewire::actingAs($user)->test(Stats::class)
        ->call('setRange', 'DROP TABLE')
        ->assertSet('range', '7');
});

it('only previews a slice of the achievements, not the full list', function () {
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(Stats::class);
    $achievements = $component->viewData('achievements');

    // Daftar penuh milik /achievements; halaman ini cuma cuplikan.
    expect($achievements['preview'])->toHaveCount(8)
        ->and($achievements['total'])->toBeGreaterThan(8)
        ->and($achievements['remaining'])->toBe($achievements['total'] - 8);

    // Sisa yang tak muat jadi tautan "+N" ke halaman achievement.
    $component->assertSee('+' . $achievements['remaining'])
        ->assertSee(route('achievements.index'));
});

it('puts earned achievements first in the preview', function () {
    $user = User::factory()->create(['highest_wpm' => 100]); // membuka 'Speed Demon'

    $preview = Livewire::actingAs($user)->test(Stats::class)->viewData('achievements')['preview'];

    // Achievement yang sudah diraih tak boleh terdorong keluar cuplikan
    // oleh achievement yang masih terkunci.
    expect($preview[0]['earned'])->toBeTrue();
});

it('reports how many achievements are unlocked out of the total', function () {
    $user = User::factory()->create(['highest_wpm' => 100]);

    Livewire::actingAs($user)->test(Stats::class)->assertSee('1 of 15 unlocked');
});

it('exposes the stats link on the profile page instead of the charts', function () {
    $user = User::factory()->create();

    actingAs($user)->get(route('profile.me'))
        ->assertOk()
        ->assertSee(route('stats'))
        // Grafik & kartu aktivitas sudah pindah; profil tak lagi merendernya.
        ->assertDontSee('profileWpmChart');
});
