<?php

use App\Support\BackLink;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Ke mana sebuah kontrol "kembali" menunjuk, diturunkan dari header Referer.
|
| Header ini dikendalikan client, jadi yang diuji di sini bukan cuma "apakah
| jalannya benar" melainkan tiga penjaganya: host yang sama, prefix yang
| dikecualikan pemanggil, dan bahwa yang keluar SELALU path -- tak pernah host.
| Unit, tanpa DB dan tanpa route(): fungsinya murni.
|--------------------------------------------------------------------------
*/

/** Request ke halaman kita sendiri, opsional membawa Referer. */
function backLinkRequest(?string $referer, string $on = 'http://uetype.test/users/x'): Request
{
    return Request::create(
        $on, 'GET', [], [], [],
        $referer !== null ? ['HTTP_REFERER' => $referer] : []
    );
}

it('memakai fallback saat tak ada Referer sama sekali', function () {
    expect(BackLink::from(backLinkRequest(null), '/friends'))->toBe('/friends');
});

it('mengembalikan path saja untuk Referer dari host yang sama', function () {
    // Host-nya dibuang: yang dirender adalah href relatif, jadi tak ada cara
    // menyelundupkan tujuan luar lewat nilai ini.
    expect(BackLink::from(backLinkRequest('http://uetype.test/clans'), '/friends'))
        ->toBe('/clans');
});

it('mempertahankan query string halaman asal', function () {
    expect(BackLink::from(backLinkRequest('http://uetype.test/chat?mode=dm&with=someone'), '/friends'))
        ->toBe('/chat?mode=dm&with=someone');
});

it('menolak Referer lintas host alih-alih menautinya (open redirect)', function () {
    $result = BackLink::from(backLinkRequest('https://evil.example.com/phish'), '/friends');

    expect($result)->toBe('/friends')
        ->and($result)->not->toContain('evil.example.com');
});

it('menolak URL protocol-relative yang menyamar sebagai path', function () {
    // `//evil.example.com/x` terlihat seperti path kalau host-nya tak diperiksa;
    // parse_url() membacanya sebagai host, dan di situlah penjaganya menangkap.
    $result = BackLink::from(backLinkRequest('//evil.example.com/x'), '/friends');

    expect($result)->toBe('/friends')
        ->and($result)->not->toContain('evil.example.com');
});

it('memakai fallback saat path-nya diawali prefix yang dikecualikan', function () {
    expect(BackLink::from(backLinkRequest('http://uetype.test/users/orang'), '/friends', ['/users/']))
        ->toBe('/friends');
});

it('membiarkan path yang tak cocok dengan prefix mana pun lolos', function () {
    expect(BackLink::from(backLinkRequest('http://uetype.test/clans'), '/friends', ['/users/', '/chat']))
        ->toBe('/clans');
});

it('memeriksa seluruh daftar pengecualian, bukan cuma yang pertama', function () {
    expect(BackLink::from(backLinkRequest('http://uetype.test/chat?mode=clan'), '/friends', ['/users/', '/chat']))
        ->toBe('/friends');
});

it('mengembalikan / untuk Referer tanpa path', function () {
    expect(BackLink::from(backLinkRequest('http://uetype.test'), '/friends'))->toBe('/');
});

it('mengembalikan fallback APA ADANYA, termasuk kalau berupa URL absolut', function () {
    // Pemanggil boleh menyerahkan route() penuh; helper ini tak berhak menyuntingnya.
    // Inilah yang menjaga assertion `toBe(route('friends.index'))` di PublicProfileTest.
    expect(BackLink::from(backLinkRequest(null), 'http://uetype.test/friends'))
        ->toBe('http://uetype.test/friends');
});
