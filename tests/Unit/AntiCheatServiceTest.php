<?php

use App\Services\AntiCheatService;

beforeEach(function () {
    $this->service = new AntiCheatService;
});

it('accepts a normal human session and recomputes wpm from chars and duration', function () {
    // 300 karakter benar dari 310 total dalam 60 detik → Net 60 WPM, Raw 62 WPM.
    $result = $this->service->check(correctChars: 300, totalChars: 310, durationSeconds: 60.0);

    expect($result['valid'])->toBeTrue()
        ->and($result['net_wpm'])->toBe(60.0)
        ->and($result['raw_wpm'])->toBe(62.0)
        ->and($result['accuracy'])->toBe(96.77)
        ->and($result['reasons'])->toBeEmpty();
});

it('rejects sessions shorter than the minimum duration', function () {
    $result = $this->service->check(correctChars: 50, totalChars: 50, durationSeconds: 0.5);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('duration_too_short');
});

it('rejects superhuman wpm', function () {
    // 2000 karakter dalam 5 detik → 4800 WPM.
    $result = $this->service->check(correctChars: 2000, totalChars: 2000, durationSeconds: 5.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('wpm_too_high');
});

it('rejects inconsistent char counts (correct greater than total)', function () {
    $result = $this->service->check(correctChars: 100, totalChars: 80, durationSeconds: 30.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('char_count_inconsistent');
});

it('rejects a session with no input', function () {
    $result = $this->service->check(correctChars: 0, totalChars: 0, durationSeconds: 30.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('no_input');
});

it('rejects huge-duration tiny-input sessions (survival leaderboard cheat vector)', function () {
    // Vektor cheat survival: klaim bertahan 600 detik dengan hanya 10 karakter
    // untuk menjuarai papan durasi. Throughput 0.017 cps << ambang 0.5 cps.
    $result = $this->service->check(correctChars: 10, totalChars: 10, durationSeconds: 600.0);

    expect($result['valid'])->toBeFalse()
        ->and($result['reasons'])->toContain('throughput_too_low');
});

it('does not flag throughput for a fast short burst', function () {
    // Sesi pendek wajar (5 detik, 40 karakter = 8 cps) tak boleh kena ambang throughput.
    $result = $this->service->check(correctChars: 40, totalChars: 40, durationSeconds: 5.0);

    expect($result['valid'])->toBeTrue()
        ->and($result['reasons'])->not->toContain('throughput_too_low');
});

/*
|--------------------------------------------------------------------------
| Gerbang penolakan (B5)
|--------------------------------------------------------------------------
| `valid` menjawab "apakah sesi ini sempurna secara sanity-check", BUKAN
| "apakah pemainnya curang". Dua hal itu dulu dicampur: TypingEngine menolak
| hasil apa pun yang `valid === false`, sehingga PENGETIK LAMBAT (throughput
| rendah) ikut dibuang hasilnya -- padahal lambat bukan curang.
|
| Throughput rendah hanya jadi sinyal curang di SURVIVAL, karena di sanalah
| durasi = metrik papan (diam saja -> durasi panjang -> juara). Di time durasi
| dikunci mode; di words durasi panjang justru MENURUNKAN wpm. Jadi di dua mode
| itu, throughput rendah = pemain lambat, dan hasilnya harus tetap disimpan.
*/

it('flags impossible signals as cheating regardless of mode', function () {
    $superhuman = $this->service->check(correctChars: 2000, totalChars: 2000, durationSeconds: 5.0);
    $inconsistent = $this->service->check(correctChars: 100, totalChars: 80, durationSeconds: 30.0);

    expect($this->service->isCheating($superhuman['reasons']))->toBeTrue()
        ->and($this->service->isCheating($inconsistent['reasons']))->toBeTrue();
});

it('does not call a slow typist a cheater', function () {
    // 25 karakter dalam 60 detik = 0.42 cps (~5 WPM). Lambat, tapi manusiawi.
    $result = $this->service->check(correctChars: 25, totalChars: 25, durationSeconds: 60.0);

    expect($result['reasons'])->toContain('throughput_too_low')
        ->and($this->service->isCheating($result['reasons']))->toBeFalse();
});

it('keeps a slow time-mode session instead of throwing it away', function () {
    // Pemula di mode time 60: hasilnya HARUS tersimpan (dulu dibuang mentah-mentah).
    $result = $this->service->check(correctChars: 25, totalChars: 25, durationSeconds: 60.0);

    expect($this->service->rejectsSoloResult($result['reasons'], 'time'))->toBeFalse();
});

it('keeps a slow words-mode session', function () {
    $result = $this->service->check(correctChars: 25, totalChars: 25, durationSeconds: 60.0);

    expect($this->service->rejectsSoloResult($result['reasons'], 'words'))->toBeFalse();
});

it('still rejects the survival idle-to-inflate-duration cheat', function () {
    // Klaim bertahan 600 detik dengan 10 karakter demi menjuarai papan durasi.
    $result = $this->service->check(correctChars: 10, totalChars: 10, durationSeconds: 600.0);

    expect($this->service->rejectsSoloResult($result['reasons'], 'survival'))->toBeTrue();
});

it('rejects superhuman wpm in every solo mode', function () {
    $result = $this->service->check(correctChars: 2000, totalChars: 2000, durationSeconds: 5.0);

    expect($this->service->rejectsSoloResult($result['reasons'], 'time'))->toBeTrue()
        ->and($this->service->rejectsSoloResult($result['reasons'], 'words'))->toBeTrue()
        ->and($this->service->rejectsSoloResult($result['reasons'], 'survival'))->toBeTrue();
});

it('rejects an empty session in every solo mode', function () {
    $result = $this->service->check(correctChars: 0, totalChars: 0, durationSeconds: 30.0);

    expect($this->service->rejectsSoloResult($result['reasons'], 'time'))->toBeTrue()
        ->and($this->service->rejectsSoloResult($result['reasons'], 'survival'))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Ceiling WPM khusus race (MAX_RACE_WPM = 240)
|--------------------------------------------------------------------------
| Lebih ketat dari MAX_HUMAN_WPM (300). Di race, progress% dilaporkan client,
| jadi "finish" cuma satu angka yang bisa dipacu client tampered. Rekor manusia
| berkelanjutan ~210-230 WPM, jadi 240 menolak pace palsu tapi tetap meloloskan
| run elit sungguhan.
*/
it('rejects a forged race pace that slips under the old 250 ceiling', function () {
    // 205 karakter benar dalam 10 detik = ~246 WPM: lolos di ambang 250 lama,
    // ditolak di ambang 240 baru (kemenangan-palsu client tampered).
    expect($this->service->exceedsRaceSpeed(correctChars: 205, durationSeconds: 10.0))->toBeTrue();
});

it('still accepts a genuine elite race pace below the ceiling', function () {
    // 195 karakter benar dalam 10 detik = ~234 WPM: pelari elite sungguhan,
    // tetap lolos supaya perbaikan tak menghukum yang jujur.
    expect($this->service->exceedsRaceSpeed(correctChars: 195, durationSeconds: 10.0))->toBeFalse();
});
