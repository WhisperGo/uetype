<?php

/**
 * ===== SKALA TIPE & BREAKPOINT =====
 *
 * Skala tipe mengikuti PROTOTYPE, termasuk `lineHeight: '1'` ("100%" di Figma) pada
 * kesembilan token. Nilainya bukan milik kode untuk diubah.
 *
 * Itu punya konsekuensi yang perlu diketahui: kotak baris jadi persis setinggi font, jadi
 * tinggi tombol yang digerakkan padding = font-size + padding saja. `py-[5px]` +
 * `text-small` (13.33px) keluar ~23px, di bawah target sentuh minimum WCAG 2.5.5. Jalan
 * keluarnya BUKAN mengubah token, melainkan memberi tombolnya ukuran eksplisit -- lihat
 * <x-icon-button> (`min-h-[44px]`) dan TouchTargetTest.
 *
 * Test ini menjaga dua hal:
 *
 *  1. Skala tipe tetap seperti prototype (lineHeight 1 di seluruh sembilan token).
 *  2. `xs` berada di urutan menaik yang benar. Kalau ia dideklarasikan di dalam `extend`,
 *     Tailwind menaruhnya SETELAH `2xl` dan media query-nya kalah dari semua breakpoint
 *     lain -- gagal diam-diam, tanpa satu pun kelas terlihat salah.
 */
it('mempertahankan skala tipe prototype, termasuk lineHeight 1', function () {
    $config = file_get_contents(base_path('tailwind.config.js'));

    preg_match('/fontSize:\s*\{(.*?)\n            \},/s', $config, $m);
    $blok = $m[1] ?? '';

    expect($blok)->not->toBeEmpty();

    // Kesembilan token skala modular tetap "100%". Kalau ada yang tergoda melonggarkannya
    // demi tinggi tombol: perbaiki tombolnya, bukan skalanya.
    foreach (['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body', 'small', "'x-small'"] as $token) {
        expect($blok)->toMatch('/'.preg_quote($token, '/').":\s*\['[^']+',\s*\{\s*lineHeight:\s*'1'\s*\}/");
    }
});

it('mempertahankan lineHeight 1 untuk angka display', function () {
    // `fluid-timer` dan `fluid-hero` adalah ANGKA besar (timer, WPM). Di sana ruang di
    // atas/bawah glyph adalah cacat, bukan keterbacaan.
    $config = file_get_contents(base_path('tailwind.config.js'));

    expect($config)->toMatch("/'fluid-timer':.*lineHeight: '1'/")
        ->and($config)->toMatch("/'fluid-hero':.*lineHeight: '1'/");
});

it('mendeklarasikan xs di urutan menaik, bukan di dalam extend', function () {
    $config = file_get_contents(base_path('tailwind.config.js'));

    // Tangga lengkap ditulis di level `theme`, bukan `theme.extend`.
    expect($config)->toMatch('/theme:\s*\{.*?screens:\s*\{/s');

    preg_match('/screens:\s*\{(.*?)\}/s', $config, $m);
    $screens = $m[1] ?? '';

    // Urutan menaik: xs sebelum sm, dan seluruh tangga ada.
    expect($screens)->toContain("xs: '400px'")
        ->and($screens)->toContain("sm: '640px'");

    expect(strpos($screens, 'xs:'))->toBeLessThan(strpos($screens, 'sm:'));

    // `screens` di dalam `extend` akan menaruh xs setelah 2xl -- itu bug yang tak terlihat.
    preg_match('/extend:\s*\{(.*)\n        \},/s', $config, $e);
    expect($e[1] ?? '')->not->toContain('screens:');
});

it('memberi halaman hasil padding yang sama dengan halaman lain', function () {
    // typing-result adalah SATU-SATUNYA halaman yang dulu memakai `px-4` datar tanpa
    // `sm:px-6 lg:px-8`, jadi dari 640px ke atas kontennya lebih dekat ke tepi layar
    // ketimbang halaman lain -- terlihat begitu berpindah halaman.
    $markup = tanpaKomentarBlade(
        file_get_contents(resource_path('views/livewire/typing-result.blade.php'))
    );

    expect($markup)->toContain('max-w-6xl w-full px-4 sm:px-6 lg:px-8 mx-auto');
});
