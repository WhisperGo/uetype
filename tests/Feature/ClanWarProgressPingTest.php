<?php

use App\Livewire\TypingEngine;
use App\Models\ClanWarModeClaim;
use App\Models\User;
use Livewire\Livewire;

/**
 * Resume hanya berguna kalau server tahu sejauh mana pemain sudah sampai -- dan posisi itu
 * datang dari klien, jadi ia harus diikat.
 *
 * Yang penting dipahami: ini INPUT baru, tapi bukan permukaan serangan baru. Server tak pernah
 * memberi kredit atas posisi resume; karakter yang dipulihkan cuma hidup di browser, dan angka
 * yang dinilai tetap jumlah keystroke saat submit -- dibatasi plafon karakter seperti biasa.
 * Yang dijaga di sini adalah agar posisi itu tak bisa dipakai untuk melompati waktu.
 */
function warProgressClaim(string $mode = 'words', string $config = '50'): array
{
    [$player, $claim] = warAttemptScenario($mode, $config);

    // Mount sekali supaya attempt-nya terbuka (jangkar + teks beku tertulis).
    remountWarAttempt($player, $claim);

    return [$player, $claim->refresh()];
}

function pingWarProgress(User $player, ClanWarModeClaim $claim, int $percent): void
{
    Livewire::actingAs($player)
        ->withQueryParams(['war_claim' => $claim->id])
        ->test(TypingEngine::class)
        ->call('reportWarProgress', $percent);
}

it('persists coarse progress for a war attempt', function () {
    [$player, $claim] = warProgressClaim();

    // Jangkar dimundurkan supaya batas fisik (detik x 20 cps) memuat posisi ini.
    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    pingWarProgress($player, $claim, 40);

    expect($claim->refresh()->attempt_progress)->toBe(40);
});

it('refuses to move progress backwards', function () {
    [$player, $claim] = warProgressClaim();

    $claim->update(['attempt_started_at' => now()->subSeconds(60), 'attempt_progress' => 60]);

    // Mundur berarti bisa mengulang bagian teks yang sudah dilewati.
    pingWarProgress($player, $claim, 10);

    expect($claim->refresh()->attempt_progress)->toBe(60);
});

it('refuses progress that outruns the physical character ceiling', function () {
    [$player, $claim] = warProgressClaim();

    // Jangkar disetel ke DETIK INI: tak ada waktu nyata untuk mengetik apa pun, jadi klaim
    // "sudah 100%" tak punya jam yang mendukungnya. Dibekukan supaya yang diuji aturannya,
    // bukan berapa milidetik yang lewat selama mount.
    $this->freezeSecond();
    $claim->update(['attempt_started_at' => now()]);

    pingWarProgress($player, $claim, 100);

    expect($claim->refresh()->attempt_progress)->toBe(0);
});

it('ignores progress pings for a survival attempt', function () {
    [$player, $claim] = warProgressClaim('survival', 'hard');

    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    // Survival tidak resume: kurva stamina tak bisa dipulihkan, jadi menyimpan posisinya
    // hanya akan jadi angka yang tak pernah dibaca.
    pingWarProgress($player, $claim, 50);

    expect($claim->refresh()->attempt_progress)->toBe(0);
});

it('ignores progress pings from outside the claiming clan', function () {
    [, $claim] = warProgressClaim();

    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    $outsider = User::factory()->create();

    pingWarProgress($outsider, $claim, 50);

    expect($claim->refresh()->attempt_progress)->toBe(0);
});

it('feeds the persisted progress back into the war lock on re-mount', function () {
    [$player, $claim] = warProgressClaim();

    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    pingWarProgress($player, $claim, 35);

    $lock = remountWarAttempt($player, $claim->refresh())->get('warLock');

    expect($lock['progress'])->toBe(35)
        ->and($lock['resume'])->toBeTrue();
});
