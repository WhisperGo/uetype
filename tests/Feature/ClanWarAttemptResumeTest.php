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
 *
 * JAM DIBEKUKAN UNTUK SELURUH BERKAS INI, bukan per test.
 *
 * Semua yang diuji di sini adalah jam attempt: sisa waktu, jangkar yang tak boleh bergeser,
 * plafon karakter yang tumbuh mengikuti waktu tunggu. Setiap detik NYATA yang lewat antara
 * penyiapan dan assertion masuk ke angka yang sedang diperiksa, dan sebagian marginnya cuma
 * dua detik -- di bawah beban paralel test-nya merah tanpa ada kode yang berubah.
 *
 * freezeSecond, bukan freezeTime: attempt_started_at menempuh kolom berpresisi detik, jadi
 * "now" berpecahan terpotong saat ditulis dan terbaca sampai satu detik lebih tua.
 *
 * Di beforeEach karena sifat itu milik BERKAS ini, bukan satu-dua test. Tiga test sudah
 * memanggilnya sendiri-sendiri, dan yang gagal justru yang TIDAK -- persis cara pengaman
 * per-test membiarkan test berikutnya lahir tanpa perlindungan yang sama.
 */
beforeEach(fn () => test()->freezeSecond());
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

    // A 30-second slot opened 25 seconds ago resumes at 5, not at 30: 25 seconds really passed.
    //
    // No page-load allowance is left to soften it. COUNTDOWN_GRACE_SECONDS was spent by the
    // FIRST mount above -- it pays for one page load, and one is what has happened. It used to
    // be re-added on every call, so this assertion read 15 and ten refreshes on a 60-second
    // slot handed back 100 seconds of clock.
    expect($lock['remaining'])->toBe(5)
        ->and($lock['resume'])->toBeTrue()
        ->and($lock['expired'])->toBeFalse();
});

it('spends the countdown grace once, however many times the page is reloaded', function () {
    [$player, $claim] = warAttemptScenario('time', '30');

    $this->freezeSecond();

    // Sepuluh kali reload beruntun. Dulu tiap mount menambah 10 detik kelonggaran, jadi jam yang
    // seharusnya mengikat justru tumbuh setiap kali halaman dibuka ulang -- reroll yang persis
    // ingin dicegah oleh seluruh sistem attempt ini.
    foreach (range(1, 10) as $ignored) {
        remountWarAttempt($player, $claim);
    }

    $claim->update(['attempt_started_at' => now()->subSeconds(25)]);

    expect(remountWarAttempt($player, $claim)->get('warLock')['remaining'])->toBe(5);
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

/**
 * Attempt kedaluwarsa yang PUNYA hasil dibukukan oleh SERVER, bukan oleh klien.
 *
 * Dulu halaman ketik tetap dirender dengan `expired: true`, lalu `x-init` memanggil finish().
 * Tapi finish() melaporkan apa yang diketik SESI INI, dan sesi yang baru saja dimuat tidak
 * mengetik apa pun -- kiriman nol keystroke atas nol detik itu ditolak anti-cheat sebagai
 * `no_input`. Yang menyelamatkannya cuma kebetulan: buku besar mengisi ulang angkanya di sisi
 * server. Klien tak pernah punya informasi untuk disumbangkan di titik ini.
 */
it('settles an expired attempt with banked work without rendering a typing page', function () {
    [$player, $claim] = warAttemptScenario('time', '15');

    remountWarAttempt($player, $claim);

    // Satu sesi penuh sudah dibukukan: 120 karakter dalam 30 detik mengetik.
    $claim->update([
        'attempt_started_at' => now()->subMinutes(5),
        'attempt_chars' => 120,
        'attempt_carried_ms' => 30_000,
        'attempt_carried_correct_chars' => 118,
        'attempt_carried_total_chars' => 120,
    ]);

    remountWarAttempt($player, $claim)->assertRedirect(route('typing.result'));

    // Slot terisi: kerjanya masuk, bukan hangus dan bukan memantul.
    expect($claim->refresh()->typing_result_id)->not->toBeNull()
        ->and((float) $claim->points)->toBeGreaterThan(0.0);
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
    // Marginnya hanya 2 detik (28 dari 30), dan itu diukur terhadap jam NYATA: di bawah beban
    // paralel attempt-nya kedaluwarsa sebelum submit, TypingEngine menyelesaikannya, dan test
    // gagal pada redirect alih-alih pada plafon yang sedang diuji. Membekukan jam membuat 28
    // benar-benar berarti 28 -- melebarkan marginnya hanya akan memindahkan titik gagalnya.
    $this->freezeSecond();

    [$player, $claim] = warAttemptScenario('time', '30');

    $component = remountWarAttempt($player, $claim);

    // Menunggu lebih lama tak boleh jadi lisensi mengklaim apa pun: window-nya dibatasi
    // panjang slot + grace, jadi plafonnya berhenti tumbuh mengikuti waktu tunggu.
    //
    // 28 detik, bukan 600 dan bukan lagi 35: attempt harus tetap HIDUP saat submit, atau
    // kirimannya diselesaikan sebagai attempt kedaluwarsa dan test ini lulus karena alasan yang
    // salah -- tak pernah menyentuh plafon yang justru sedang diuji. Ambangnya turun setelah
    // COUNTDOWN_GRACE_SECONDS jadi jatah sekali per attempt: mount pertama di atas sudah
    // memakainya, jadi slot time/30 ini kedaluwarsa pada 30 detik, bukan 40.
    app(SoloSessionGuard::class)->backdate(28);
    $claim->update(['attempt_started_at' => now()->subSeconds(28)]);

    $component->call('saveResult', ['durationMs' => 30000, 'totalKeystrokes' => 4000, 'correctKeystrokes' => 4000])
        ->assertRedirect(route('typing', ['war_claim' => $claim->id]));

    expect($claim->refresh()->typing_result_id)->toBeNull();
});

it('counts every session of a resumed words attempt, and only the sessions', function () {
    [$player, $claim] = warAttemptScenario('words', '50');

    remountWarAttempt($player, $claim);

    // Sesi 1: 60 detik mengetik, lalu ditinggalkan.
    $claim->update(['attempt_started_at' => now()->subSeconds(62)]);
    pingWarAttempt($player, $claim, chars: 150, typedMs: 60_000, totalKeystrokes: 150, correctKeystrokes: 148);

    $resumed = remountWarAttempt($player, $claim);

    // Lalu jangkarnya dibiarkan berjalan sampai 200 detik: 100+ detik terakhir tak ada yang
    // mengetik sama sekali (tab terbuka, pemain pergi), dan 40 detik terakhir barulah sesi 2.
    app(SoloSessionGuard::class)->backdate(40);
    $claim->update(['attempt_started_at' => now()->subSeconds(200)]);

    $resumed->call('saveResult', ['durationMs' => 40_000, 'totalKeystrokes' => 150, 'correctKeystrokes' => 147])
        ->assertRedirect(route('typing.result'));

    // 100 detik: 60 dari sesi pertama + 40 dari sesi ini. BUKAN 200.
    //
    // Aturan ini SENGAJA diganti. Dulu durasinya adalah jam jangkar dikurangi grace 30 detik,
    // jadi angka di atas akan terbaca 170 -- seluruh menit yang tak seorang pun mengetik ikut
    // ditagihkan. Itu memang menghukum refresh, tapi ia menghukum tiap jeda lain dengan cara
    // yang sama, dan grace 30 detiknya justru MENGHAPUS waktu mengetik sungguhan saat pemain
    // benar-benar me-refresh (lihat ClanWarResumeLedgerTest). Menjumlahkan sesi menutup
    // kebocoran itu tanpa menagih waktu yang tak pernah dipakai siapa pun.
    //
    // Yang hilang bersamanya: menunggu kini gratis. Itu tak membeli apa pun -- teks yang sama
    // masih harus diketik dengan kecepatan yang sama, dan slotnya tetap terkunci selama itu.
    expect((float) TypingResult::latest('id')->first()->duration_seconds)
        ->toBeGreaterThanOrEqual(99.0)
        ->toBeLessThanOrEqual(101.0);
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
