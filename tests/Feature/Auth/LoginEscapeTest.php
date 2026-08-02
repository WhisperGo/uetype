<?php

/*
|--------------------------------------------------------------------------
| Halaman tamu (/login dan pilih-username) memakai layouts.guest, yang TAK
| memuat layouts.navigation -- padahal nav itu punya cabang khusus tamu yang
| berfungsi. Akibatnya tamu yang sampai di /login hanya bisa keluar lewat
| tombol back browser: tak ada satu pun kontrol berlabel di halaman itu.
|--------------------------------------------------------------------------
*/

// guestBackHref() tinggal di tests/Pest.php: GoogleAuthFlowTest memakainya juga.

it('mengarahkan tautan kembali ke halaman tempat tamu datang', function (string $from) {
    $html = $this->get('/login', ['referer' => url($from)])->assertOk()->getContent();

    expect(guestBackHref($html))->toBe($from);
})->with([
    'typing' => '/typing',
    'clans' => '/clans',
    'leaderboard' => '/leaderboard',
    'about' => '/about',
]);

it('tetap menawarkan jalan keluar saat tamu membuka /login langsung', function () {
    $response = $this->get('/login')->assertOk();

    // Tak pernah null: tanpa referer pun tautannya harus tetap tampil, kalau tidak
    // halaman ini kembali jadi jebakan bagi siapa pun yang mengetik URL-nya.
    $response->assertSee(__('auth.back'));
    expect(guestBackHref($response->getContent()))->toBe(route('typing'));
});

it('tak menunjuk balik ke /login sendiri', function () {
    $html = $this->get('/login', ['referer' => url('/login')])->assertOk()->getContent();

    expect(guestBackHref($html))->toBe(route('typing'));
});

it('tak menunjuk balik ke langkah OAuth yang cuma masuk akal maju', function () {
    $html = $this->get('/login', ['referer' => url('/auth/google/callback')])->assertOk()->getContent();

    expect(guestBackHref($html))->toBe(route('typing'));
});

it('menolak referer Google alih-alih menautinya', function () {
    // Pada 302, browser mempertahankan referer aslinya -- jadi request ke /login setelah
    // callback gagal benar-benar membawa URL Google.
    $response = $this->get('/login', ['referer' => 'https://accounts.google.com/o/oauth2/v2/auth'])
        ->assertOk();

    $response->assertDontSee('accounts.google.com');
    expect(guestBackHref($response->getContent()))->toBe(route('typing'));
});

it('menolak referer lintas host alih-alih menautinya (open redirect)', function () {
    $response = $this->get('/login', ['referer' => 'https://evil.example.com/phish'])->assertOk();

    $response->assertDontSee('evil.example.com');
    expect(guestBackHref($response->getContent()))->toBe(route('typing'));
});

it('mengingat asal tamu melintasi perjalanan OAuth', function () {
    // Referer TAK selamat dari perjalanan ke Google maupun dari Cancel di halaman username,
    // jadi asalnya harus diingat di session -- kalau tidak, "kembali" setelah membatalkan
    // pendaftaran akan menjatuhkan tamu di /typing, bukan di halaman yang ia tinggalkan.
    $this->get('/login', ['referer' => url('/clans')])->assertOk();

    $html = $this->get('/login', ['referer' => url('/auth/google/username')])->assertOk()->getContent();

    expect(guestBackHref($html))->toBe('/clans');
});

it('tak memakai url.intended sebagai tujuan kembali', function () {
    // url.intended menyimpan ke mana tamu HENDAK pergi -- halaman ber-auth yang memantulkannya
    // ke sini. Mengirimnya "kembali" ke sana hanya akan memantulkannya balik ke /login.
    $this->get(route('clans.index'))->assertRedirect(route('login'));

    $html = $this->get('/login')->assertOk()->getContent();

    expect(guestBackHref($html))->not->toBe(route('clans.index'))
        ->and(guestBackHref($html))->toBe(route('typing'));
});
