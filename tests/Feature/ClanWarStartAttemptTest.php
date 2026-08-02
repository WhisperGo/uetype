<?php

use App\Livewire\ClanWar;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

/**
 * Masuk ke mesin ketik dari grid war.
 *
 * Dulu ini sebuah <a href> yang URL-nya dirakit di dalam ekspresi Alpine memakai @js(). Blade
 * TIDAK mengompilasi directive di dalam atribut sebuah component tag (<x-btn-gold>), jadi teks
 * `@js(...)` sampai mentah ke browser, Alpine tersedak pada `=>` milik array PHP, dan seluruh
 * handler @click.prevent mati -- .prevent tetap memblokir navigasi, sehingga tombolnya benar-
 * benar tidak melakukan apa pun. Klaim bisa dibuat, percobaan tidak pernah bisa dimulai.
 *
 * Test suite lama tak mungkin menangkapnya: semuanya masuk lewat ->withQueryParams(['war_claim'
 * => ...]) langsung ke TypingEngine, melewati satu-satunya lapisan yang rusak. Karena itu berkas
 * ini menguji ClanWar (komponen yang memegang tombolnya), bukan TypingEngine.
 */
it('redirects the claimer into the typing engine for their own reserved claim', function () {
    [$player, $claim] = warAttemptScenario('words', '25');

    Livewire::actingAs($player)->test(ClanWar::class)
        ->call('startAttempt', $claim->id)
        ->assertRedirect(route('typing', ['war_claim' => $claim->id]));
});

it('still redirects for a claim whose attempt is already running', function () {
    // Tombol "Lanjutkan" memakai aksi yang sama: percobaannya satu dan bisa dimasuki lagi.
    // Jamnya sudah ditambatkan, jadi masuk kembali tidak memberi apa pun yang baru.
    [$player, $claim] = warAttemptScenario('time', '30');

    remountWarAttempt($player, $claim);

    Livewire::actingAs($player)->test(ClanWar::class)
        ->call('startAttempt', $claim->id)
        ->assertRedirect(route('typing', ['war_claim' => $claim->id]));
});

it('refuses to open a claim that already scored', function () {
    // Slot yang sudah disubmit itu selesai; membukanya lagi berarti percobaan kedua.
    [$player, $claim] = warAttemptScenario('words', '25');

    remountWarAttempt($player, $claim);

    // Baris hasil yang SUNGGUHAN: typing_result_id punya foreign key, jadi id karangan ditolak
    // constraint sebelum test sempat menguji apa pun.
    $result = TypingResult::create([
        'user_id' => $player->id, 'mode' => 'words', 'mode_config' => '25',
        'net_wpm' => 60, 'raw_wpm' => 65, 'accuracy' => 95,
        'correct_chars' => 290, 'incorrect_chars' => 10, 'duration_seconds' => 30, 'xp_earned' => 10,
    ]);

    $claim->forceFill(['typing_result_id' => $result->id])->save();

    Livewire::actingAs($player)->test(ClanWar::class)
        ->call('startAttempt', $claim->id)
        ->assertNoRedirect();
});

it('refuses to open a claim belonging to somebody else', function () {
    // Grid hanya MENYEMBUNYIKAN tombol milik orang lain di balik @if. Penjagaan yang sebenarnya
    // ada di TypingEngine::resolveWarClaim(); ini kunci kedua di pintu yang sama, bukan
    // penggantinya -- id-nya bisa saja dipanggil langsung lewat Livewire.
    [, $claim] = warAttemptScenario('words', '25');
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)->test(ClanWar::class)
        ->call('startAttempt', $claim->id)
        ->assertNoRedirect();
});

it('does not start an attempt merely by rendering the grid', function () {
    // Menambatkan jam adalah langkah yang tak bisa dibatalkan, dan ia milik mesin ketik.
    // Membuka halaman war tidak boleh membakar slot siapa pun.
    [$player, $claim] = warAttemptScenario('survival', 'stamina');

    Livewire::actingAs($player)->test(ClanWar::class)->assertOk();

    expect(ClanWarModeClaim::find($claim->id)->attempt_started_at)->toBeNull();
});
