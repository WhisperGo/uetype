<?php

use App\Models\User;

/**
 * Aksesibilitas yang bisa ditegakkan otomatis. Bukan pengganti uji pembaca layar
 * sungguhan -- ini hanya menangkap regresi mekanis (atribut hilang) yang paling
 * sering terjadi saat markup diedit.
 */
it('menyertakan alt pada setiap gambar di lobby multiplayer', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('multiplayer.lobby'))->assertOk()->getContent();

    expect(imgTanpaAlt($html))->toBeEmpty();
});

it('menyertakan alt pada setiap gambar di halaman profil', function () {
    $user = User::factory()->create(['avatar' => 'https://lh3.googleusercontent.com/a/foto']);

    $html = $this->actingAs($user)->get(route('profile.me'))->assertOk()->getContent();

    expect(imgTanpaAlt($html))->toBeEmpty();
});

/**
 * Focus trap saja tak cukup: tanpa role/aria-modal, pembaca layar tidak
 * mengumumkan perpindahan konteks ke dialog.
 */
it('memberi semantik dialog pada komponen modal', function () {
    $modal = file_get_contents(resource_path('views/components/modal.blade.php'));

    expect($modal)->toContain('role="dialog"')
        ->and($modal)->toContain('aria-modal="true"');
});

/**
 * Aplikasi ini padat gerak (countdown, toast, caret, pulse stamina). Tanpa blok
 * ini, pengguna dengan sensitivitas vestibular tak punya jalan keluar.
 */
it('menghormati preferensi sistem kurangi gerakan', function () {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)->toContain('prefers-reduced-motion');
});

/** Definisi font hanya boleh hidup di satu tempat. */
it('memusatkan definisi font ke satu partial', function () {
    $pemakaiLangsung = [];

    foreach (glob(resource_path('views/layouts/*.blade.php')) as $path) {
        if (basename($path) === '_fonts.blade.php') {
            continue;
        }

        if (str_contains(file_get_contents($path), 'fonts.googleapis.com')) {
            $pemakaiLangsung[] = basename($path);
        }
    }

    expect($pemakaiLangsung)->toBeEmpty();
});

/** @return list<string> potongan tag <img> yang tak punya atribut alt */
function imgTanpaAlt(string $html): array
{
    preg_match_all('/<img\b[^>]*>/i', $html, $matches);

    return array_values(array_filter(
        $matches[0],
        fn (string $tag) => ! preg_match('/\balt\s*=/i', $tag)
    ));
}
