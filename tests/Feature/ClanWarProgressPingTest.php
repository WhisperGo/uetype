<?php

use App\Models\TypingResult;
use App\Models\User;

/**
 * Resume hanya berguna kalau server tahu sejauh mana pemain sudah sampai -- dan posisi itu
 * datang dari klien, jadi ia harus diikat.
 *
 * Dulu posisi adalah satu-satunya isi ping ini, dan ia memang tak bernilai kredit: karakter yang
 * dipulihkan cuma hidup di browser. Sekarang ping-nya juga membawa buku besar sesi berjalan
 * (waktu ketik & jumlah keystroke), yang JUSTRU dinilai -- lihat ClanWarResumeLedgerTest untuk
 * aritmetikanya. Berkas ini menjaga lapisan di bawahnya: batas dan otorisasi yang berlaku pada
 * tiap ping, apa pun isinya.
 *
 * Ping-nya juga pindah dari method Livewire ke sebuah endpoint. Itu bukan kerapian: sebuah
 * panggilan Livewire adalah XHR, dan browser membatalkan XHR saat halaman unload -- jadi ping
 * yang dikirim tepat saat pemain menekan refresh, satu-satunya yang menentukan di mana ia
 * kembali, tak pernah tiba.
 */
function warProgressClaim(string $mode = 'words', string $config = '50'): array
{
    [$player, $claim] = warAttemptScenario($mode, $config);

    // Mount sekali supaya attempt-nya terbuka (jangkar + teks beku tertulis).
    remountWarAttempt($player, $claim);

    return [$player, $claim->refresh()];
}

it('persists a resume position for a war attempt', function () {
    [$player, $claim] = warProgressClaim();

    // Jangkar dimundurkan supaya batas fisik (detik x 20 cps) memuat posisi ini.
    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    pingWarAttempt($player, $claim, chars: 120, typedMs: 40_000, totalKeystrokes: 120, correctKeystrokes: 118);

    expect($claim->refresh()->attempt_chars)->toBe(120);
});

it('refuses to move progress backwards', function () {
    [$player, $claim] = warProgressClaim();

    $claim->update(['attempt_started_at' => now()->subSeconds(60), 'attempt_chars' => 150]);

    // Mundur berarti bisa mengulang bagian teks yang sudah dilewati.
    pingWarAttempt($player, $claim, chars: 30, typedMs: 5_000, totalKeystrokes: 30, correctKeystrokes: 30);

    expect($claim->refresh()->attempt_chars)->toBe(150);
});

it('refuses progress that outruns the physical character ceiling', function () {
    [$player, $claim] = warProgressClaim();

    // Jangkar disetel ke DETIK INI: tak ada waktu nyata untuk mengetik apa pun, jadi klaim
    // "sudah di karakter ke-400" tak punya jam yang mendukungnya. Dibekukan supaya yang diuji
    // aturannya, bukan berapa milidetik yang lewat selama mount.
    $this->freezeSecond();
    $claim->update(['attempt_started_at' => now()]);

    pingWarAttempt($player, $claim, chars: 400, typedMs: 60_000, totalKeystrokes: 400, correctKeystrokes: 400);

    expect($claim->refresh()->attempt_chars)->toBe(0);
});

it('never records more typing time than the attempt has existed', function () {
    [$player, $claim] = warProgressClaim();

    $claim->update(['attempt_started_at' => now()->subSeconds(20)]);

    // Waktu yang dilebih-lebihkan sebetulnya arah yang AMAN (ia menurunkan WPM), tapi sebuah
    // attempt yang mengaku memegang lima menit padahal baru dua puluh detik tetap sedang
    // menggambarkan sesuatu yang tak terjadi -- dan setiap angka di sini diikat ke jam yang
    // sama, bukan hanya yang kebetulan menguntungkan.
    pingWarAttempt($player, $claim, chars: 100, typedMs: 300_000, totalKeystrokes: 100, correctKeystrokes: 100);

    expect((int) $claim->refresh()->attempt_live_ms)->toBeLessThanOrEqual(21_000);
});

it('ignores progress pings for a survival attempt', function () {
    [$player, $claim] = warProgressClaim('survival', 'hard');

    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    // Survival tidak resume: kurva stamina tak bisa dipulihkan, jadi menyimpan posisinya
    // hanya akan jadi angka yang tak pernah dibaca -- dan buku besarnya akan MENAIKKAN skor
    // sebuah percobaan yang ditinggalkan, satu-satunya hal yang survival tak boleh berikan.
    pingWarAttempt($player, $claim, chars: 150, typedMs: 40_000, totalKeystrokes: 150, correctKeystrokes: 150);

    expect($claim->refresh()->attempt_chars)->toBe(0)
        ->and((int) $claim->attempt_live_ms)->toBe(0);
});

it('ignores progress pings from outside the claiming clan', function () {
    [, $claim] = warProgressClaim();

    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    $outsider = User::factory()->create();

    pingWarAttempt($outsider, $claim, chars: 150, typedMs: 40_000, totalKeystrokes: 150, correctKeystrokes: 150)
        ->assertForbidden();

    expect($claim->refresh()->attempt_chars)->toBe(0);
});

it('refuses a ping for a claim that has already been submitted', function () {
    [$player, $claim] = warProgressClaim();

    $result = TypingResult::create([
        'user_id' => $player->id, 'mode' => 'words', 'mode_config' => '50',
        'net_wpm' => 60, 'raw_wpm' => 62, 'accuracy' => 96,
        'correct_chars' => 280, 'incorrect_chars' => 5, 'duration_seconds' => 60, 'xp_earned' => 27,
    ]);

    $claim->update(['attempt_started_at' => now()->subSeconds(60), 'typing_result_id' => $result->id]);

    // Slot yang sudah terisi tak punya sesi berjalan. Menerima ping untuknya berarti membiarkan
    // buku besar tumbuh setelah angkanya dinilai.
    pingWarAttempt($player, $claim, chars: 150, typedMs: 40_000, totalKeystrokes: 150, correctKeystrokes: 150)
        ->assertForbidden();

    expect($claim->refresh()->attempt_chars)->toBe(0);
});

it('feeds the persisted position back into the war lock on re-mount', function () {
    [$player, $claim] = warProgressClaim();

    $claim->update(['attempt_started_at' => now()->subSeconds(60)]);

    pingWarAttempt($player, $claim, chars: 90, typedMs: 40_000, totalKeystrokes: 90, correctKeystrokes: 88);

    $lock = remountWarAttempt($player, $claim->refresh())->get('warLock');

    expect($lock['chars'])->toBe(90)
        ->and($lock['resume'])->toBeTrue()
        // Buku besar sesi yang baru saja ditinggalkan ikut turun ke klien, supaya WPM live yang
        // dilihat pemain setelah resume adalah angka yang sama dengan yang akan dinilai server.
        ->and($lock['carriedMs'])->toBe(40_000)
        ->and($lock['carriedCorrect'])->toBe(88)
        ->and($lock['carriedTotal'])->toBe(90);
});

it('gives the client the endpoint and claim it needs to report', function () {
    [$player, $claim] = warProgressClaim();

    $lock = remountWarAttempt($player, $claim)->get('warLock');

    // Rutenya diberikan, bukan di-hardcode di JS: satu nama route, satu tempat.
    expect($lock['claim'])->toBe($claim->id)
        ->and($lock['report'])->toBe(route('clan-war.attempt-progress'));
});
