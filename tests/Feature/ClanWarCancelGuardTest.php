<?php

use App\Livewire\ClanWar;
use App\Models\ClanWarModeClaim;
use Livewire\Livewire;

/**
 * Sebuah klaim punya TIGA umur, bukan dua: dipesan (belum dibuka, masih bisa dikembalikan),
 * sedang berjalan (percobaan satu-satunya sudah dibuka, jadi terpakai apa pun hasilnya), dan
 * selesai.
 *
 * Tanpa keadaan tengah itu, cancel + klaim ulang adalah jalan KETIGA untuk restart -- di
 * samping refresh dan tombol Back. Dan di mode Words teksnya beku serta identik untuk semua
 * pemain pada config itu, jadi mengulang berarti latihan tanpa batas pada soal yang sudah
 * dibaca. Batasnya karena itu "pernah dibuka", bukan "sudah disubmit".
 */
it('lets the claimer hand back a slot that was never opened', function () {
    [$player, $claim] = warAttemptScenario('words', '25');

    Livewire::actingAs($player)->test(ClanWar::class)->call('cancelClaim', $claim->id);

    expect(ClanWarModeClaim::find($claim->id))->toBeNull();
});

it('refuses to cancel a claim whose attempt has started', function () {
    [$player, $claim] = warAttemptScenario('words', '25');

    // Membuka halaman ketik ADALAH memulai percobaan.
    remountWarAttempt($player, $claim);

    Livewire::actingAs($player)->test(ClanWar::class)->call('cancelClaim', $claim->id);

    expect(ClanWarModeClaim::find($claim->id))->not->toBeNull();
});

it('refuses a leader cancelling a started attempt too', function () {
    // Pemain di skenario ini ADALAH leader clan-nya, jadi ini menutup jalur "kekuasaan roster"
    // sekaligus: bukan soal siapa yang menekan, melainkan bahwa percobaannya sudah terpakai.
    [$leader, $claim] = warAttemptScenario('time', '30');

    remountWarAttempt($leader, $claim);

    Livewire::actingAs($leader)->test(ClanWar::class)->call('cancelClaim', $claim->id);

    expect(ClanWarModeClaim::find($claim->id))->not->toBeNull();
});

it('does not drop the player into the typing engine on claim', function () {
    [$player] = warAttemptScenario('words', '10');

    // Klaim dulu me-redirect langsung ke mesin ketik, yang berarti klaim DAN percobaan adalah
    // satu tindakan. Begitu membuka halaman menjadi saat percobaan dimulai, satu salah-klik
    // akan membakar slot -- dan cancelClaim() jadi kode yang tak pernah terjangkau.
    Livewire::actingAs($player)->test(ClanWar::class)
        ->call('claimMode', 'time', '15')
        ->assertNoRedirect();
});
