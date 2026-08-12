<?php

use App\Models\User;

/**
 * Kerangka halaman harus menyingkir saat pemain mengetik -- tapi tak pernah sampai hilang.
 *
 * Perilaku visualnya CSS (`body.is-typing .focus-fade`, lihat resources/css/app.css) dan
 * pemicunya JS (`focus-mode.js`, diuji di focus-mode.test.js). Yang bisa dijaga dari sisi
 * PHP adalah KAITANNYA: apakah navbar & footer benar-benar membawa penanda yang dibaca CSS
 * itu. Tanpa test ini, seseorang bisa merapikan kelas di layout dan mematikan seluruh fitur
 * tanpa satu pun test menyala -- sebab tak ada lagi yang menghubungkan keduanya.
 */
function halamanKetik(): string
{
    return tanpaKomentarBlade(test()->actingAs(User::factory()->create())->get(route('typing'))->getContent());
}

it('marks the navbar so focus mode can fade it', function () {
    expect(halamanKetik())->toContain('focus-fade');
});

it('fades the navbar and the footer, and nothing else in the frame', function () {
    $html = halamanKetik();

    // Dua penanda, tak lebih. Menandai sesuatu yang ketiga -- area ketik, panel hasil --
    // akan meredupkan justru yang sedang dipandangi pemain.
    expect(substr_count($html, 'focus-fade'))->toBe(3); // navbar + footer(+full)
});

it('keeps the footer fully faded even where nothing can hover it back', function () {
    // `focus-fade-full` mengecualikan footer dari perlakuan layar sentuh (yang menyisakan
    // 25% opacity karena tak ada hover di sana). Footer boleh hilang sepenuhnya: isinya baris
    // copyright dan dua tautan legal, jadi tak ada yang hilang dari pemain yang tak bisa
    // memanggilnya kembali. Navbar TIDAK boleh -- itu satu-satunya jalan keluar yang terlihat.
    expect(halamanKetik())->toContain('focus-fade focus-fade-full');
});

it('never removes the navbar from the page', function () {
    $html = halamanKetik();

    // Focus mode meredupkan, tak pernah menghapus. Kalau navbar benar-benar dilepas dari DOM,
    // kolomnya ter-center ulang dan teks melompat di bawah jari pemain -- gangguan yang lebih
    // besar daripada bar yang dihilangkannya. Tautan-tautannya harus tetap ada di markup.
    expect($html)->toContain(route('clans.index'))
        ->toContain(route('multiplayer.lobby'));
});

it('leaves pages that are not a typing session untouched', function () {
    $html = tanpaKomentarBlade(
        test()->actingAs(User::factory()->create())->get(route('friends.index'))->getContent()
    );

    // Penandanya ada di layout, jadi ia ikut ke SEMUA halaman -- dan itu justru benar: kelas
    // `is-typing` tak pernah menyala di sana, sehingga penanda ini tak berpengaruh. Yang
    // diperiksa di sini adalah bahwa halaman biasa tak butuh perlakuan khusus apa pun.
    expect($html)->toContain('focus-fade')
        ->not->toContain('is-typing');
});
