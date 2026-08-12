<?php

use App\Models\User;

/**
 * ===== NAVBAR DI LAYAR SEMPIT =====
 *
 * User melaporkan "bug di desain ... mulai dari navbar". Tiga sebab, semuanya di berkas
 * yang sama:
 *
 *  1. AMBANG TUNGGAL. Nav hanya punya `sm:` (640px) dan nol `md:`. Jadi pada 640px --
 *     yang itu HP dalam posisi lanskap, bukan desktop -- seluruh bar desktop muncul
 *     sekaligus: logo + wordmark Press Start 2P + tiap link primer + trophy + divider +
 *     avatar + username + level + chevron. Band 640-768px mendapat bar yang meluber
 *     alih-alih panel hamburger yang justru muat.
 *
 *  2. USERNAME TAK BISA MENYUSUT. Item flex punya `min-width: auto` secara default, jadi
 *     tanpa `min-w-0` + `truncate` sebuah username panjang mendorong baris melebihi lebar
 *     viewport.
 *
 *  3. PANEL TAK BISA DITUTUP. Hanya tombol Sign Out yang menyetel `open = false`; link nav
 *     tidak. Jadi menyentuh "Solo" memuat halaman baru DI BAWAH panel yang masih terbuka --
 *     terbaca sebagai "menunya nyangkut".
 *
 * CATATAN cakupan: ini KONTRAK MARKUP & MODUL. Proyek ini tak mengeksekusi JavaScript
 * (lihat RaceAssetTest), jadi test ini mengunci keberadaan jalurnya -- bahwa panelnya
 * benar-benar tertutup wajib dibuktikan dengan jari di perangkat. Checklist di
 * docs/mobile-test-checklist.md bagian D.
 */

/** HTML navbar yang benar-benar dirender, untuk user yang login. */
function navHtml(): string
{
    return test()->actingAs(User::factory()->create())
        ->get(route('typing'))
        ->assertOk()
        ->getContent();
}

it('memakai md sebagai ambang desktop, bukan sm', function () {
    $html = navHtml();

    // Hamburger dan panel mobile hidup sampai `md`, bukan cuma sampai `sm`.
    expect($html)->toContain('md:hidden')
        // Grup link & sisi kanan muncul dari `md`.
        ->and($html)->toContain('md:flex')
        ->and($html)->toContain('md:items-center');

    // Tak boleh ada lagi `sm:hidden`/`sm:flex` di navbar: itu tanda ambang lama kembali.
    // Diperiksa pada SUMBER navbar saja -- halaman penuh punya banyak `sm:` yang sah.
    $nav = tanpaKomentarBlade(
        file_get_contents(resource_path('views/layouts/navigation.blade.php'))
    );

    expect($nav)->not->toContain('sm:hidden')
        ->and($nav)->not->toContain('sm:flex');
});

it('membuat username bisa menyusut, bukan mendorong bar melebihi layar', function () {
    $user = User::factory()->create(['username' => 'namayangsangatpanjangsekali']);

    $html = $this->actingAs($user)->get(route('typing'))->assertOk()->getContent();

    // `min-w-0` melepas `min-width: auto` bawaan flex; `truncate` yang benar-benar memotong.
    // Tanpa keduanya, username panjang melebarkan baris nav.
    expect($html)->toContain('min-w-0')
        ->and($html)->toContain('truncate');
});

it('memberi hamburger kontrak aksesibilitas dan target sentuh yang layak', function () {
    $html = navHtml();

    expect($html)
        // `type="button"`: sebuah <button> tanpa type di dalam <form> akan men-submit-nya.
        // Hari ini tak ada form induk, jadi markup lama aman hanya karena KEBETULAN.
        ->toContain('type="button"')
        // Bentuknya menyalin FAB chat overlay supaya kontraknya sama di kedua tempat.
        ->toContain('aria-controls="mobile-nav-panel"')
        ->toContain('id="mobile-nav-panel"')
        // Kotak klik 44px (WCAG 2.5.5) -- dulu `p-2` di sekitar ikon 24px = 40px.
        ->toContain('min-w-[44px] min-h-[44px]');

    // aria-expanded terikat reaktif, bukan hardcoded, supaya ia ikut berubah saat dibuka.
    expect($html)->toMatch('/:aria-expanded="open \? .true. : .false."/');
});

it('menutup panel mobile lewat setiap jalan keluar yang wajar', function () {
    $nav = tanpaKomentarBlade(
        file_get_contents(resource_path('views/layouts/navigation.blade.php'))
    );

    // Klik di luar ditangani di elemen <nav>, BUKAN di panel: panel tak memuat tombol
    // hamburger, jadi handler di panel akan menyala pada tap yang membukanya dan menutup
    // panel di frame yang sama. <nav> membungkus keduanya -- susunan yang sama dipakai
    // dropdown.blade.php.
    expect($nav)->toMatch('/<nav[^>]*@click\.outside="open = false"/');

    // `@click.stop` di hamburger supaya tap-nya tak ikut sampai ke handler di <nav>.
    expect($nav)->toContain('@click.stop="open = ! open"');

    // Escape & navigasi ditangani di JS, bukan Blade: RaceAssetTest melarang <script>
    // di Blade, dan nav-badges.js sudah memiliki state `open`.
    $js = tanpaKomentarJs(file_get_contents(resource_path('js/nav-badges.js')));

    expect($js)->toContain("'Escape'")
        // Guard `&& this.open` load-bearing: tanpanya handler ini menelan Escape milik
        // modal atau chat overlay yang sedang berada di atasnya.
        ->and($js)->toContain('this.open')
        // Satu listener navigate menutup panel untuk SEMUA link -- yang ada sekarang dan
        // yang ditambahkan nanti -- dan ia juga menyala pada tombol back.
        ->and($js)->toContain('livewire:navigating');

    // Listener dokumen wajib dilepas saat navigate, mengikuti pola yang sudah ada.
    expect($js)->toContain("removeEventListener('keydown'");
});

it('mencegah panel berkedip terbuka sebelum Alpine siap', function () {
    // Panel di-toggle lewat kelas `block`/`hidden`, jadi tanpa x-cloak ia bisa tampak
    // sekejap pada frame pertama sebelum Alpine mengevaluasi :class.
    $nav = tanpaKomentarBlade(
        file_get_contents(resource_path('views/layouts/navigation.blade.php'))
    );

    expect($nav)->toMatch('/x-cloak[^>]*id="mobile-nav-panel"|id="mobile-nav-panel"[^>]*x-cloak/');
});

it('mengunci lebar dropdown akun agar tak melewati tepi layar', function () {
    // Panel dropdown `absolute end-0` tanpa penanganan tabrakan viewport, jadi `w-56`
    // tetap bisa menjorok keluar di jendela sempit.
    $html = navHtml();

    expect($html)->toContain('max-w-[calc(100vw-2rem)]');
});
