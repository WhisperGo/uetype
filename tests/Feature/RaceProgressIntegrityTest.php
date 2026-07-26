<?php

/**
 * Integritas progress balapan: progress HANYA boleh naik dari karakter yang benar.
 *
 * Seluruh WPM multiplayer bersandar pada invarian ini. Server menurunkan
 * `correctChars = progress% x panjang teks` (MultiplayerLobby::updateRaceProgress),
 * jadi ia tak pernah melihat teks yang diketik dan tak bisa memverifikasi sendiri
 * apakah progress itu jujur. Dulu invarian ini cuma ASUMSI: handleSpace() menambah
 * progress sepanjang kata target tanpa memeriksa kecocokan, sehingga "ketik sebagian
 * lalu spasi" menyelesaikan balapan dengan usaha jauh lebih sedikit -- dan karena juara
 * ditentukan waktu selesai, itu jadi strategi optimal.
 *
 * Word-lock menegakkannya: kata tidak pernah lewat sampai diketik persis benar.
 *
 * CATATAN: proyek ini tidak punya runner JavaScript (lihat RaceAssetTest), jadi test di
 * sini membaca SUMBER, bukan menjalankan perilakunya. Fungsinya tripwire regresi terhadap
 * orang yang kelak menghapus gerbangnya -- bukan bukti perilaku. Verifikasi perilaku tetap
 * manual di browser.
 */

/** Isi handleSpace() saja, supaya urutan penjaga di dalamnya bisa diperiksa. */
function handleSpaceSource(): string
{
    $arena = file_get_contents(resource_path('js/race-arena.js'));
    $start = strpos($arena, 'handleSpace(');

    expect($start)->not->toBeFalse('handleSpace() tidak ditemukan di race-arena.js');

    return substr($arena, $start);
}

it('refuses to advance a word that was not typed exactly', function () {
    $handleSpace = handleSpaceSource();

    // Gerbangnya harus membandingkan ketikan dengan kata target, lalu keluar.
    expect($handleSpace)->toContain('this.typedText !== targetWord');
});

it('never credits progress before the exact-match gate has passed', function () {
    $handleSpace = handleSpaceSource();

    $gate = strpos($handleSpace, 'this.typedText !== targetWord');
    $credit = strpos($handleSpace, 'this.correctCharsFromPastWords +=');

    expect($gate)->not->toBeFalse()
        ->and($credit)->not->toBeFalse()
        // Kalau akumulator naik lebih dulu, gerbangnya tak menjaga apa pun.
        ->and($gate)->toBeLessThan($credit);
});

it('no longer inflates keystrokes for characters that were never typed', function () {
    // `missingCount` dulu menambah keystroke DAN mistake sekaligus untuk sisa kata yang
    // dilewati. Dengan word-lock kata tak bisa dilewati, jadi blok itu tak terjangkau --
    // dan akurasi pemain jujur ikut membaik karena penalti itu hilang.
    expect(arenaSourceAll())->not->toContain('missingCount');
});

it('drops the per-word error marker that word-lock made impossible', function () {
    // Tak ada kata yang bisa lewat dalam keadaan salah, jadi penanda ini selalu false.
    expect(arenaSourceAll())->not->toContain('wordHadError');
});

it('still signals a rejected space, including when the word is a correct prefix', function () {
    $arena = file_get_contents(resource_path('js/race-arena.js'));
    $markup = tanpaKomentarBlade(
        file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'))
    );

    // Kasus yang paling mudah terlewat: ketik "the" untuk "then". Itu prefiks yang BENAR,
    // jadi hasError bernilai false dan layar tidak merah -- tapi spasinya tetap ditolak.
    // Tanpa sinyal terpisah, pemain hanya merasa tombol spasinya rusak.
    expect($arena)->toContain('justBlocked')
        ->and($markup)->toContain('justBlocked');
});

it('reuses the existing typo shake instead of adding another animation', function () {
    $markup = tanpaKomentarBlade(
        file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'))
    );

    expect($markup)->toContain("'race-typo': hasError || justBlocked")
        ->and(file_get_contents(resource_path('css/app.css')))->toContain('.race-typo');
});

it('teaches the rule instead of leaving the player to guess it', function () {
    foreach (['en', 'id'] as $locale) {
        expect(require base_path("lang/{$locale}/multiplayer.php"))
            ->toHaveKey('word_must_match');
    }
});
