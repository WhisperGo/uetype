<?php

use Illuminate\Support\Facades\Blade;

/**
 * ===== TARGET SENTUH & AFFORDANCE HOVER =====
 *
 * User melaporkan "ada tombol yang tidak bisa dipencet" di HP. Ada DUA sebab, dan
 * keduanya bukan masalah layout:
 *
 *  1. UKURAN. WCAG 2.5.5 minta 44x44px. Tombol ikon di aplikasi ini menulis sendiri
 *     `p-1` + svg `w-4 h-4` (= 24x24px), dan dua di antaranya TANPA padding sama
 *     sekali (= 16x16px). Yang terburuk: `openClearModal` (menghapus riwayat chat,
 *     destruktif) duduk `gap-2` dari tombol tutup pada 24px -- jari yang bermaksud
 *     menutup drawer bisa menghapus riwayatnya.
 *
 *  2. HOVER. Tombol hapus-pertemanan dulu `opacity-0 group-hover:opacity-100`. Di
 *     layar sentuh tak ada hover, jadi tombolnya TAK PERNAH muncul -- satu aksi utuh
 *     hilang di mayoritas sesi, dan ia tampak berfungsi bagi siapa pun yang menguji
 *     dengan mouse.
 *
 * CATATAN soal cakupan: proyek ini tak mengeksekusi JavaScript maupun CSS di test
 * (lihat RaceAssetTest). Jadi berkas ini KONTRAK MARKUP -- ia mengunci kelas yang
 * menghasilkan ukurannya, bukan mengukur piksel yang benar-benar dirender. Ukuran
 * sesungguhnya wajib diverifikasi dengan ibu jari di perangkat nyata; checklistnya di
 * docs/mobile-test-checklist.md bagian E.
 *
 * Bergaya grep seperti SharedComponentsTest: ia menjaga agar pola lama tak menyelinap
 * kembali. Itulah sebabnya <x-icon-button> ada -- utility class tak bisa memberi
 * default, dan 18 salinan padding akan menyimpang seperti 8 blok empty-state dulu.
 */

/** Ukuran kotak klik minimum, sekali tulis supaya assertion tak berpencar. */
const TAP_MIN = 'min-w-[44px] min-h-[44px]';

it('memberi komponen icon-button kotak klik 44px dan memaksa nama aksesibel', function () {
    $html = Blade::render(
        '<x-icon-button label="Tutup" wire:click="x"><svg class="w-4 h-4"></svg></x-icon-button>'
    );

    expect($html)
        ->toContain(TAP_MIN)
        // Nama aksesibel WAJIB: tombol ini tak punya teks, jadi tanpa label ia terbaca
        // sebagai "button" saja oleh screen reader.
        ->toContain('aria-label="Tutup"')
        // `type="button"` supaya ia tak pernah mem-submit form induk secara tak sengaja.
        ->toContain('type="button"')
        // Atribut Livewire/Alpine harus lolos lewat $attributes, kalau tidak tiap lokasi
        // perlu prop baru dan komponennya jadi tak berguna.
        ->toContain('wire:click="x"')
        // Glyph-nya TIDAK diperbesar -- yang tumbuh hanya kotak kliknya.
        ->toContain('class="w-4 h-4"');
});

it('merender icon-button sebagai <a> tanpa kehilangan ukurannya', function () {
    // `:68`, `:137`, `:147` di chat-overlay adalah <a href wire:navigate>. Memaksanya
    // jadi <button> mengubah semantik HTML dan mematikan wire:navigate, jadi prop `as`
    // mengikuti pola yang sudah ada di btn-gold.blade.php.
    $html = Blade::render(
        '<x-icon-button as="a" href="/chat" wire:navigate label="Buka">i</x-icon-button>'
    );

    expect($html)
        ->toContain('<a ')
        ->toContain(TAP_MIN)
        ->toContain('href="/chat"')
        ->toContain('wire:navigate')
        // Sebuah <a> tak boleh punya type="button".
        ->not->toContain('type="button"');
});

it('memisahkan nada destruktif supaya aksi merusak tak berwarna netral', function () {
    // `danger` sengaja jadi nada tersendiri, bukan override lewat class=: kalau tidak,
    // aksi destruktif bisa diam-diam ter-ship memakai warna netral -- yang persis
    // dilakukan "clear chat" saat ia duduk di samping "close".
    $netral = Blade::render('<x-icon-button label="a">i</x-icon-button>');
    $bahaya = Blade::render('<x-icon-button tone="danger" label="a">i</x-icon-button>');

    expect($netral)->toContain('hover:text-foreground')
        ->and($bahaya)->toContain('hover:text-danger')
        ->and($bahaya)->not->toContain('hover:text-foreground');
});

/**
 * Elemen interaktif yang isinya HANYA sebuah ikon kecil -- tak ada teks yang memberi
 * kotak klik tambahan.
 *
 * Pembatasan "hanya ikon" itu load-bearing. Tautan seperti "< Leaderboard" (ikon + `<span>`
 * teks) mendapat lebar & tingginya dari teksnya dan sah tanpa padding; menjaringnya akan
 * membuat test ini gagal atas markup yang sudah benar. Yang dicari adalah `<svg>` yang
 * langsung ditutup oleh tag penutup elemennya.
 *
 * Mengembalikan potongan tag pembuka tiap pelanggar supaya pesan gagalnya bisa menyebut
 * kelas yang salah, bukan cuma nama berkas.
 */
function tombolHanyaIkon(string $markup): array
{
    // `.*?` di dalam ikon karena <svg> berisi <path>; `(?!<)` memastikan tak ada elemen
    // lain (mis. <span> teks) di antara </svg> dan penutup tombolnya.
    preg_match_all(
        '/<(button|a)\b([^>]*)>\s*<svg\b.*?<\/svg>\s*<\/\1>/s',
        $markup,
        $m,
        PREG_SET_ORDER
    );

    return array_map(fn ($set) => $set[2], $m);
}

it('tidak menyisakan tombol ikon 24px yang ditulis tangan', function () {
    // Ciri khas pola lama: `p-1` sebagai satu-satunya padding pada tombol hanya-ikon,
    // yang dengan svg 16px menghasilkan 24x24px. `[^.\d]` setelahnya supaya `p-1.5` dan
    // `p-10` tak ikut terjaring -- `\b` tak cukup, karena titik adalah batas kata.
    $pelanggar = [];

    foreach (bladeViews() as $path) {
        if (basename($path) === 'icon-button.blade.php') {
            continue;
        }

        foreach (tombolHanyaIkon(tanpaKomentarBlade(file_get_contents($path))) as $atribut) {
            if (preg_match('/class="[^"]*\bp-1(?![.\d])/', $atribut)) {
                $pelanggar[] = basename($path);
                break;
            }
        }
    }

    expect($pelanggar)->toBeEmpty(
        'Pakai <x-icon-button> (44px), jangan p-1 + svg (24px): '.implode(', ', $pelanggar)
    );
});

it('tidak menyisakan tombol ikon tanpa ukuran yang dinyatakan', function () {
    // Kasus terburuk yang pernah ada: <button class="text-muted ... shrink-0"> yang isinya
    // HANYA svg w-4 h-4 -- kotak kliknya 16x16px, kurang dari separuh minimum, dan tak ada
    // apa pun di kelasnya yang menyebut ukuran.
    //
    // Yang diterima: min-w/min-h (aturan komponen), w-/h- eksplisit (FAB chat w-14 h-14),
    // atau padding apa pun selain p-1 (sudah dijaga test di atas).
    $pelanggar = [];

    foreach (bladeViews() as $path) {
        if (basename($path) === 'icon-button.blade.php') {
            continue;
        }

        foreach (tombolHanyaIkon(tanpaKomentarBlade(file_get_contents($path))) as $atribut) {
            $menyatakanUkuran = preg_match(
                '/class="[^"]*\b(?:min-w-|min-h-|w-\d|h-\d|p-|px-|py-)/',
                $atribut
            ) === 1;

            if (! $menyatakanUkuran) {
                $pelanggar[] = basename($path);
                break;
            }
        }
    }

    expect($pelanggar)->toBeEmpty(
        'Tombol hanya-ikon tanpa ukuran = kotak klik seukuran glyph-nya: '.implode(', ', $pelanggar)
    );
});

it('tidak menyembunyikan aksi di balik hover', function () {
    // Sebuah AKSI yang hanya muncul saat hover tak pernah muncul di layar sentuh.
    // Dekorasi (tooltip ber-`pointer-events-none`) boleh -- ia tak mengklaim bisa
    // ditekan. Yang dilarang adalah elemen yang membawa wire:click/@click/href.
    $pelanggar = [];

    foreach (bladeViews() as $path) {
        $isi = tanpaKomentarBlade(file_get_contents($path));

        foreach (explode('>', $isi) as $tag) {
            $tersembunyi = str_contains($tag, 'opacity-0') && str_contains($tag, 'group-hover:opacity-100');
            $bisaDitekan = preg_match('/\bwire:click|\bx-on:click|@click|\bhref=/', $tag) === 1;
            $dekorasi = str_contains($tag, 'pointer-events-none');

            if ($tersembunyi && $bisaDitekan && ! $dekorasi) {
                $pelanggar[] = basename($path);
                break;
            }
        }
    }

    expect($pelanggar)->toBeEmpty(
        'Aksi yang di-reveal lewat hover tak terjangkau di HP: '.implode(', ', $pelanggar)
    );
});

it('memperbesar kontrol kick tanpa membesarkan lingkarannya', function () {
    // Kick adalah destruktif dan dulu 20x20px -- target terkecil di aplikasi. Ia TIDAK
    // dipindah ke <x-icon-button>: lingkaran-X-merahnya adalah affordance tersendiri
    // sementara komponennya bernada netral. Yang dipinjam adalah ATURANNYA: lingkaran
    // tetap kecil, kotak klik 44px.
    $isi = tanpaKomentarBlade(
        file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'))
    );

    // Dua kontrol kick: spectator dan member.
    expect(substr_count($isi, TAP_MIN))->toBeGreaterThanOrEqual(2);

    // Lingkaran yang terlihat tetap kecil -- kalau ia ikut jadi 44px, ia menutupi
    // seperempat kartu member di `grid-cols-2` pada HP.
    expect($isi)->toContain('w-5 h-5 rounded-full')
        ->and($isi)->toContain('w-6 h-6 rounded-full');
});
