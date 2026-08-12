<?php

use App\Models\User;

/**
 * Notifikasi teman, clan, dan chat dulu punya container toast SENDIRI-SENDIRI --
 * ketiganya di `fixed bottom-5 right-5 z-[60]` yang sama persis. Dua notifikasi
 * yang datang bersamaan saling menimpa alih-alih menumpuk. Test ini mengunci
 * bahwa hanya ada SATU tumpukan, supaya bug itu tak kembali lewat penambahan
 * jenis notifikasi baru.
 */
it('merender tepat satu tumpukan toast untuk user login', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('friends.index'))->assertOk()->getContent();

    expect(substr_count($html, 'toastStack('))->toBe(1);
});

it('tidak merender tumpukan toast untuk tamu', function () {
    $html = $this->get(route('typing'))->assertOk()->getContent();

    expect($html)->not->toContain('toastStack(');
});

/**
 * Toast harus hidup di luar $slot supaya bertahan lintas wire:navigate --
 * pemain bisa sedang mengetik atau balapan saat notifikasinya masuk.
 */
it('menyediakan tumpukan toast di semua halaman, bukan hanya halaman sosial', function () {
    $user = User::factory()->create();

    foreach ([route('typing'), route('stats'), route('clans.index')] as $url) {
        $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

        expect($html)->toContain('toastStack(');
    }
});

/** Logikanya harus di modul, bukan tersalin ulang sebagai script inline. */
it('menaruh logika toast di modul js, bukan di dalam blade', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->not->toContain('friendToasts')
        ->and($layout)->not->toContain('clanToasts')
        ->and($layout)->not->toContain("Alpine.data('chatToasts'");

    expect(file_exists(resource_path('js/toasts.js')))->toBeTrue();
});

/**
 * ===== JALUR NOTIFIKASI (notif-lane) =====
 *
 * Dulu toast dan undangan room sama-sama menempel sendiri ke pojok KANAN bawah --
 * pojok yang sama dengan tombol chat (FAB). Secara geometri FAB (56px, right-5)
 * selalu berada di dalam rentang toast (320px, right-5), dan z toast lebih tinggi,
 * jadi setiap toast menutupi tombol chat sekaligus memblokirnya dari klik.
 *
 * Undangan room sempat menambal ini sendiri dengan `bottom-24` hardcoded, tapi
 * mengukurnya ke TOAST, bukan ke FAB -- lahirlah dua konvensi yang saling tak tahu.
 *
 * Sekarang keduanya jadi anak dari SATU `.notif-lane` di kiri bawah, dan tak satu pun
 * boleh membawa positioning sendiri. Test ini yang menjaga konvensi itu tetap satu.
 */
it('menaruh toast dan undangan room di dalam satu jalur notifikasi', function () {
    $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

    expect($layout)->toContain('notif-lane');

    // Keduanya harus berada DI DALAM jalur yang sama, bukan dipasang terpisah.
    $lane = substr($layout, strpos($layout, 'notif-lane'));
    $lane = substr($lane, 0, strpos($lane, '</div>'));

    expect($lane)->toContain('<x-toast-stack />')
        ->and($lane)->toContain('<x-room-invite-overlay />');
});

it('mendefinisikan jalur notifikasi sekali saja di css, bukan disalin per komponen', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('.notif-lane');
});

/**
 * Jalur notifikasi kini di kanan ATAS, dan harus turun melewati navbar.
 *
 * Sisi kanan navbar memuat dropdown akun beserta titik badge friend-request. Menaikkan
 * lane ke `top: 0` hanya akan MEMINDAHKAN bug aslinya -- dari menutupi tombol chat jadi
 * menutupi menu akun. Navbar setinggi `h-16` (4rem), jadi offset lane tak boleh kurang
 * dari itu. Diuji sebagai angka, bukan pencocokan string, supaya nilai berapa pun yang
 * aman tetap lolos.
 */
it('menurunkan jalur notifikasi di bawah navbar', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    preg_match('/\.notif-lane\s*\{(.*?)\}/s', $css, $block);
    expect($block)->not->toBeEmpty();

    preg_match('/top:\s*([\d.]+)rem/', $block[1], $top);
    expect($top)->not->toBeEmpty('Jalur notifikasi wajib punya offset `top` dalam rem.');

    // h-16 = 4rem.
    expect((float) $top[1])->toBeGreaterThanOrEqual(4.0);
});

/**
 * Inti perbaikannya: tak boleh ada notifikasi yang menambatkan dirinya sendiri ke
 * pojok kanan bawah lagi. Pojok itu milik FAB chat sepenuhnya.
 */
it('tidak membiarkan notifikasi menambat sendiri ke pojok kanan bawah', function () {
    foreach (['toast-stack', 'room-invite-overlay'] as $component) {
        // Komentar Blade dibuang dulu: keduanya MENJELASKAN penambatan lama (itu justru
        // dokumentasi yang kita mau), jadi mencocokkan teks mentah akan menuduh prosanya
        // sendiri. Yang diuji adalah markup aktif.
        $markup = tanpaKomentarBlade(
            file_get_contents(resource_path("views/components/{$component}.blade.php"))
        );

        expect($markup)->not->toContain('bottom-5 right-5')
            ->and($markup)->not->toContain('bottom-24')
            ->and($markup)->not->toContain('fixed z-[');
    }
});

/**
 * Tanpa aria-live, pembaca layar tak pernah mengumumkan achievement yang terbuka
 * maupun pesan masuk -- toast muncul & hilang tanpa jejak sama sekali bagi mereka.
 * (Undangan room sudah punya role="alertdialog" sendiri.)
 */
it('mengumumkan toast ke pembaca layar', function () {
    $markup = file_get_contents(resource_path('views/components/toast-stack.blade.php'));

    expect($markup)->toContain('role="status"')
        ->and($markup)->toContain('aria-live="polite"');
});

/**
 * FAB chat disembunyikan selama sesi ketik/balapan karena tombol DIAM pun dianggap
 * mengganggu. Toast lebih mengganggu lagi -- ia menyelinap masuk dengan animasi tepat
 * saat WPM sedang diukur -- tapi dulu tak mendengarkan `test-activity` sama sekali.
 * Ditahan, bukan dibuang: muncul setelah sesi selesai.
 */
it('menahan toast selama sesi ketik atau balapan berjalan', function () {
    $js = file_get_contents(resource_path('js/toasts.js'));

    expect($js)->toContain('test-activity');
});

it('menahan undangan room selama sesi ketik atau balapan berjalan', function () {
    $markup = file_get_contents(resource_path('views/components/room-invite-overlay.blade.php'));

    expect($markup)->toContain('test-activity');
});
