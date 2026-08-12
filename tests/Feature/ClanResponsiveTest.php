<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

/**
 * ===== RESPONSIVITAS SECTION CLAN (KONTRAK MARKUP) =====
 *
 * Section clan sudah mostly mobile-first, tapi audit menemukan beberapa titik yang
 * MELANGGAR aturan responsive tim yang sudah dipakai di tempat lain, plus baris yang
 * sempit di HP ~360px. Berkas ini mengunci perbaikannya supaya tak menyelinap balik.
 *
 * CATATAN cakupan: sama seperti ViewportUnitTest/TouchTargetTest -- proyek ini tak
 * mengeksekusi CSS/JS di test, jadi ini mengunci KELAS-nya, bukan mengukur piksel di
 * perangkat. Bukti sesungguhnya ada di docs/mobile-test-checklist.md.
 */

/**
 * A1 -- Field form clan (Create + Edit leader) dulu `text-sm` (14px). iOS Safari mem-zoom
 * halaman saat field < 16px difokus dan tak mengembalikannya. Aturan tim: field difokus
 * wajib `text-base`. ViewportUnitTest melewatkan ini karena kelasnya di-compose dari
 * variabel PHP (`$field`) dan textarea tak ikut di-scan -- jadi kita render komponennya
 * betulan dan periksa hasilnya.
 */
it('menjaga field form clan di atas ambang zoom iOS (text-base)', function () {
    // Komponen memakai @error, yang butuh view bag `$errors` yang biasanya di-share oleh
    // middleware ShareErrorsFromSession. Di test tanpa request itu tak ada, jadi kita share
    // bag kosong ke semua view (termasuk komponen bersarang). Kita hanya memeriksa kelas
    // field, bukan alur validasi.
    View::share('errors', new ViewErrorBag);

    $html = Blade::render(
        '<x-clan.identity-fields name="n" tag="t" emblem="e" color="c" description="d" />'
    );

    preg_match_all('/<(input|textarea)\b[^>]*>/s', $html, $m, PREG_SET_ORDER);

    // Sanity: komponennya memang punya field yang bisa difokus untuk diketik.
    expect($m)->not->toBeEmpty('Field form clan tak ditemukan -- selektornya berubah?');

    foreach ($m as $tag) {
        expect($tag[0])->toContain('text-base')
            ->and($tag[0])->not->toMatch('/\btext-(xs|sm|small|x-small)\b/');
    }
});

/**
 * A2 -- Tombol kebab aksi member di roster ditulis tangan `p-1.5` + svg (~28px), di bawah
 * 44px WCAG. Tim sudah punya <x-icon-button> (44px) persis untuk ini; TouchTargetTest
 * hanya menjaring `p-1` jadi `p-1.5` lolos. Kunci: trigger member-actions adalah komponen
 * 44px, bukan tombol mentah.
 */
it('memberi kebab aksi member kotak klik 44px lewat icon-button', function () {
    $clans = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/clans.blade.php')));

    // Trigger member-actions kini <x-icon-button> (yang kontraknya 44px, dijaga TouchTargetTest).
    expect($clans)->toMatch('/<x-icon-button\b[^>]*clan\.aria\.member_actions/s')
        // Pola lama (tombol mentah p-1.5 hanya-ikon) tak boleh tersisa di trigger itu.
        ->and($clans)->not->toContain('class="p-1.5 rounded-lg text-muted hover:text-foreground hover:bg-white/5 transition"');
});

/**
 * A3 -- Blok lawan di war yang sedang berjalan adalah satu-satunya blok nama di clan-war
 * tanpa guard menyusut: pembungkusnya tak `min-w-0` dan namanya tak bisa pecah, jadi nama
 * clan panjang tanpa spasi mendorong lebar di HP. Samakan dengan blok incoming/waiting.
 */
it('menjaga nama lawan clan war tetap bisa menyusut di layar sempit', function () {
    $war = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/clan-war.blade.php')));

    // Jendela di sekitar nama lawan yang sedang berjalan (`vs_prefix`), dengan sedikit
    // lihat-ke-belakang untuk memuat div pembungkusnya. Blok incoming/waiting ada jauh di
    // atas (>1000 karakter), jadi jendela sempit ini tak salah tuduh ke sana.
    $anchor = strpos($war, 'clan.war.vs_prefix');
    expect($anchor)->not->toBeFalse();
    $block = substr($war, max(0, $anchor - 600), 750);

    expect($block)->toContain('min-w-0')
        ->and($block)->toContain('break-words');
});

/**
 * B1 -- Toolbar hero 4-tombol dulu `shrink-0`, jadi di HP ia berdesakan di samping
 * identitas. Full-width di HP lalu auto di desktop.
 */
it('membuat toolbar aksi hero clan penuh selebar layar di HP', function () {
    $clans = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/clans.blade.php')));

    expect($clans)->toContain('w-full sm:w-auto');
});

/**
 * B2 -- Baris join-request menaruh avatar + teks + dua tombol dalam satu baris tak
 * membungkus; di 360px terlalu sempit. Baris harus boleh wrap (grup aksi turun ke bawah).
 */
it('membiarkan baris join-request membungkus aksinya di HP', function () {
    $clans = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/clans.blade.php')));

    $start = strpos($clans, 'clan.my_clan.join_requests');
    $end = strpos($clans, 'clan.my_clan.reject');
    expect($start)->not->toBeFalse()->and($end)->not->toBeFalse();
    $block = substr($clans, $start, $end - $start);

    expect($block)->toContain('flex-wrap');
});

/**
 * B3 -- Baris "pending" di browse menaruh label status (tak ter-truncate) + tombol Cancel
 * dalam grup `shrink-0` yang menekan kolom nama clan. Sembunyikan label di HP terkecil
 * (< xs 400px), sisakan tombol Cancel.
 */
it('menyembunyikan label status pending di HP terkecil demi ruang nama clan', function () {
    $clans = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/clans.blade.php')));

    expect($clans)->toContain('hidden xs:inline');
});
