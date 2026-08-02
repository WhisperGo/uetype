<?php

use App\Models\TypingResult;
use App\Services\SoloSessionGuard;

/**
 * Sebuah klaim war harus jadi SATU percobaan, bukan tiket yang bisa diputar ulang.
 *
 * Aturannya sudah lama tertulis ("satu klaim, satu teks, satu kesempatan") dan restart()
 * memang diblokir -- tapi penegakannya cuma ada di satu tempat, dan tempat itu adalah
 * AKHIR sesi: conditional update `whereNull('typing_result_id')`. Di antara klaim dan
 * submit, baris klaim tak menyimpan state apa pun, jadi refresh / tombol Back / tombol
 * Play di grid sama-sama me-mount ulang komponen dan memulai sesi yang benar-benar baru.
 *
 * Di mode Words teksnya beku, jadi tiap pengulangan adalah latihan pada soal yang persis
 * akan dinilai. Di time/survival justru sebaliknya: tak ada teks beku sama sekali, jadi
 * tiap mount me-reroll teks acak -- reroll yang sama yang sudah dilarang restart().
 */
it('freezes the issued text for time across a re-mount', function () {
    [$player, $claim] = warAttemptScenario('time', '60');

    $first = remountWarAttempt($player, $claim)->get('textToType');
    $second = remountWarAttempt($player, $claim)->get('textToType');

    expect($second)->toBe($first);
});

it('freezes the issued text for survival across a re-mount', function () {
    [$player, $claim] = warAttemptScenario('survival', 'hard');

    $first = remountWarAttempt($player, $claim)->get('textToType');
    $second = remountWarAttempt($player, $claim)->get('textToType');

    expect($second)->toBe($first);
});

it('keeps a single clock anchor across re-mounts', function () {
    [$player, $claim] = warAttemptScenario('time', '30');

    remountWarAttempt($player, $claim);
    $anchor = $claim->refresh()->attempt_started_at;

    // Backdate, then re-enter: a refresh must not hand back the seconds already burned.
    $claim->update(['attempt_started_at' => now()->subSeconds(20)]);
    remountWarAttempt($player, $claim);

    expect($anchor)->not->toBeNull()
        ->and($claim->refresh()->attempt_started_at->timestamp)->toBe(now()->subSeconds(20)->timestamp);
});

it('shrinks the remaining time on a resumed time attempt', function () {
    [$player, $claim] = warAttemptScenario('time', '30');

    remountWarAttempt($player, $claim);

    // Frozen to a whole second so the assertion is on the RULE, not on sub-second drift:
    // attempt_started_at is a second-precision column, so a fractional "now" is truncated on
    // write and reads back as up to a second older than it was set.
    $this->freezeSecond();
    $claim->update(['attempt_started_at' => now()->subSeconds(25)]);

    $lock = remountWarAttempt($player, $claim)->get('warLock');

    // A 30-second slot opened 25 seconds ago resumes at 15, not at 30: 25 seconds really
    // passed, less the 10-second allowance for page load (COUNTDOWN_GRACE_SECONDS).
    expect($lock['remaining'])->toBe(15)
        ->and($lock['resume'])->toBeTrue()
        ->and($lock['expired'])->toBeFalse();
});

/**
 * REGRESI: attempt kedaluwarsa yang tak punya apa pun untuk dibukukan adalah LOOP, bukan sesi.
 *
 * Dulu halaman ketik tetap dirender lalu meminta klien `finish()`. Sesi kosong itu (0 keystroke,
 * 0 detik) ditolak anti-cheat sebagai `no_input`, penolakannya me-redirect balik ke klaim yang
 * sama, klaimnya masih kedaluwarsa, dan `finish()` dipanggil lagi -- tanpa henti. Pemain melihat
 * "ditolak validasi server" dan tak bisa mengetik sama sekali.
 *
 * Survival kena SETIAP kali, karena survival tak pernah resume sehingga tak pernah punya
 * progress untuk dibukukan.
 */
it('sends an expired attempt with nothing to bank back to the war page', function () {
    [$player, $claim] = warAttemptScenario('survival', 'hard');

    remountWarAttempt($player, $claim);

    // Anggaran survival (120 dtk) jauh terlampaui.
    $claim->update(['attempt_started_at' => now()->subMinutes(10)]);

    remountWarAttempt($player, $claim)->assertRedirect(route('clan-war.index'));
});

it('refuses to re-lock a rejected submission into an expired attempt', function () {
    [$player, $claim] = warAttemptScenario('time', '15');

    $component = remountWarAttempt($player, $claim);

    $claim->update(['attempt_started_at' => now()->subMinutes(10)]);

    // Jaring pengaman lapis kedua: apa pun yang menyubmit ke attempt yang sudah habis, ia
    // memantul KELUAR -- tak pernah kembali ke halaman yang cuma bisa menolaknya lagi.
    $component->call('saveResult', ['durationMs' => 0, 'totalKeystrokes' => 0, 'correctKeystrokes' => 0])
        ->assertRedirect(route('clan-war.index'));
});

/**
 * REGRESI: satu-satunya jalan keluar berlabel dari sebuah war attempt pernah mati saat mengetik.
 *
 * Tautannya duduk di dalam pembungkus yang ber-`:class="isStarted ? 'opacity-0
 * pointer-events-none' : ...'"`, jadi sejak keystroke PERTAMA ia tak bisa diklik sama sekali --
 * menyisakan tombol Back browser sebagai satu-satunya cara keluar. Badge-nya boleh memudar
 * (itu chrome); tautannya tidak.
 */
it('keeps the war exit link clickable once typing has started', function () {
    [$player, $claim] = warAttemptScenario('time', '30');

    $html = tanpaKomentarBlade(remountWarAttempt($player, $claim)->html());

    $linkPos = strpos($html, route('clan-war.index'));

    expect($linkPos)->not->toBeFalse();

    // Tak ada pointer-events-none di antara pembuka <a> dan href-nya: itu justru atribut yang
    // dulu ikut terwarisi dan mematikan tautannya.
    $tagStart = strrpos(substr($html, 0, $linkPos), '<a ');

    expect(substr($html, $tagStart, $linkPos - $tagStart))->not->toContain('pointer-events-none');
});

it('does not promise that leaving cancels the attempt', function () {
    [$player, $claim] = warAttemptScenario('time', '30');

    // Keluar tak membatalkan apa pun -- jamnya sudah ditambatkan dan terus berjalan. Label
    // yang menjanjikan sebaliknya membujuk pemain melepas slot yang ia kira dikembalikan.
    remountWarAttempt($player, $claim)->assertSee(__('typing.war_lock_leave'));
});

it('still renders an expired attempt that has progress to bank', function () {
    [$player, $claim] = warAttemptScenario('time', '15');

    remountWarAttempt($player, $claim);
    $claim->update(['attempt_started_at' => now()->subMinutes(5), 'attempt_progress' => 40]);

    $lock = remountWarAttempt($player, $claim)->get('warLock');

    // Tetap war-locked: melepas lock akan menjatuhkan pemain ke sesi solo gratis di URL
    // ?war_claim=. Halaman ini dirender justru supaya 40% yang sudah diketik bisa dibukukan --
    // yang TAK punya apa pun untuk dibukukan dipulangkan ke halaman war (test di atas).
    expect($lock['remaining'])->toBe(0)
        ->and($lock['expired'])->toBeTrue()
        ->and($lock['progress'])->toBe(40)
        ->and($lock['mode'])->toBe('time');
});

it('does not reset the character ceiling on a refresh', function () {
    [$player, $claim] = warAttemptScenario('time', '30');

    $component = remountWarAttempt($player, $claim);

    // 350 characters is more than a just-opened session could physically have produced, and
    // a fresh mount rightly refuses it. On a resumed attempt the same 350 characters are the
    // honest total of a player who has been typing for half a minute -- refusing THEM is the
    // false positive this override exists to prevent.
    app(SoloSessionGuard::class)->backdate(30);
    $claim->update(['attempt_started_at' => now()->subSeconds(30)]);

    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 350, 'correctKeystrokes' => 345])
        ->assertRedirect(route('typing.result'));

    expect($claim->refresh()->typing_result_id)->not->toBeNull();
});

it('never lets a refresh buy characters beyond the attempt wall budget', function () {
    [$player, $claim] = warAttemptScenario('time', '30');

    $component = remountWarAttempt($player, $claim);

    // Menunggu lebih lama tak boleh jadi lisensi mengklaim apa pun: window-nya dibatasi
    // panjang slot + grace, jadi plafonnya berhenti tumbuh mengikuti waktu tunggu.
    //
    // 35 detik, bukan 600: di atas ~40 detik slot time/30 sudah kedaluwarsa dan memantul ke
    // halaman war, yang berarti test ini akan lulus karena alasan yang salah -- tak pernah
    // menyentuh plafonnya sama sekali.
    app(SoloSessionGuard::class)->backdate(30);
    $claim->update(['attempt_started_at' => now()->subSeconds(35)]);

    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 4000, 'correctKeystrokes' => 4000])
        ->assertRedirect(route('typing', ['war_claim' => $claim->id]));

    expect($claim->refresh()->typing_result_id)->toBeNull();
});

it('counts wasted wall time in a resumed words duration', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    $component = remountWarAttempt($player, $claim);

    app(SoloSessionGuard::class)->backdate(40);
    $claim->update(['attempt_started_at' => now()->subSeconds(200)]);

    $component->call('saveResult', ['durationMs' => 40000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 295])
        ->assertRedirect(route('typing.result'));

    // Claimed 40 seconds, but the attempt has held the clock for 200. Minus the 30-second
    // grace that covers reading and page load, 170 seconds really passed -- and a WPM
    // measured over 40 would price a refresh at nothing.
    expect((float) TypingResult::latest('id')->first()->duration_seconds)->toBeGreaterThan(160.0);
});

it('leaves an honest single-session words duration exactly as claimed', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    $component = remountWarAttempt($player, $claim);

    // A clean run: the player read for a few seconds, then typed for 60. The grace absorbs
    // the reading, so the claim survives untouched -- no honest player pays for the fix above.
    app(SoloSessionGuard::class)->backdate(60);
    $claim->update(['attempt_started_at' => now()->subSeconds(70)]);

    $component->call('saveResult', ['durationMs' => 60000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 295])
        ->assertRedirect(route('typing.result'));

    expect((float) TypingResult::latest('id')->first()->duration_seconds)->toBe(60.0);
});

it('caps a survival war credit by the wall budget already spent', function () {
    [$player, $claim] = warAttemptScenario('survival', 'hard');

    $component = remountWarAttempt($player, $claim);

    // 200 seconds on the anchor for a 90-second run: 110 of them were burned on attempts
    // this player walked away from. Survival is the one mode where a longer clock is the
    // REWARD, so those seconds have to come out of what the war credits.
    app(SoloSessionGuard::class)->backdate(90);
    $claim->update(['attempt_started_at' => now()->subSeconds(200)]);

    $component->call('saveResult', ['durationMs' => 90000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 300])
        ->assertRedirect(route('typing.result'));

    $claim->refresh();

    // Well under the 150-point ceiling a clean 90-second run would have earned.
    expect((float) $claim->points)->toBeGreaterThan(0.0)
        ->and((float) $claim->points)->toBeLessThan(40.0);
});

it('does not shrink the stored survival duration, only the war credit', function () {
    [$player, $claim] = warAttemptScenario('survival', 'hard');

    $component = remountWarAttempt($player, $claim);

    app(SoloSessionGuard::class)->backdate(90);
    $claim->update(['attempt_started_at' => now()->subSeconds(200)]);

    $component->call('saveResult', ['durationMs' => 90000, 'totalKeystrokes' => 300, 'correctKeystrokes' => 300])
        ->assertRedirect(route('typing.result'));

    // The solo record stays truthful: they really did survive 90 seconds. Capping the STORED
    // duration would raise net_wpm (AntiCheatService reads this column) and could get an
    // honest run refused as impossible.
    expect((float) TypingResult::latest('id')->first()->duration_seconds)->toBe(90.0);
});
