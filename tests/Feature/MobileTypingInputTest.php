<?php

use App\Models\User;

/**
 * ===== MENGETIK DI PERANGKAT SENTUH =====
 *
 * Mesin ketik dulu menangkap SELURUH input lewat `@keydown.window` dan sengaja tak punya
 * elemen fokusable sama sekali -- keputusan yang didokumentasikan di typing-engine.md §3.7
 * ("bukan input tersembunyi") supaya pemain desktop bisa langsung mengetik tanpa mengklik
 * area teks lebih dulu. Setiap tombol mode bahkan memanggil `$el.blur()` agar tak ada yang
 * memegang fokus.
 *
 * Konsekuensinya di HP: tak ada yang bisa disentuh untuk memunculkan keyboard layar, dan tak
 * ada keyboard fisik yang mengirim `keydown`. Jadi tesnya bukan cuma sulit -- benar-benar tak
 * bisa dimainkan.
 *
 * CATATAN PENTING soal cakupan test ini: proyek tak punya test yang mengeksekusi JavaScript
 * (lihat TypingEngineAssetTest), jadi ini KONTRAK MARKUP & MODUL -- ia mengunci keberadaan
 * jalurnya, bukan membuktikan keyboard benar-benar muncul. Perilakunya wajib diverifikasi
 * manual di perangkat Android dan iOS sungguhan.
 */
it('exposes a focusable input in the typing area so a soft keyboard can open', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('typing'))->assertOk()->getContent();

    expect(typingInputTag($html))->not->toBe('', 'Area mengetik tak punya <input x-ref="typingInput">.');
});

/**
 * Autocorrect, kapitalisasi otomatis, dan pemeriksa ejaan akan menyunting apa yang diketik
 * pemain -- di tes mengetik itu berarti mengubah hasil yang diukur. Semuanya harus mati.
 */
it('disables every text-correcting feature on the typing input', function () {
    $user = User::factory()->create();

    $tag = typingInputTag($this->actingAs($user)->get(route('typing'))->getContent());

    expect($tag)->toContain('autocomplete="off"')
        ->and($tag)->toContain('autocorrect="off"')
        ->and($tag)->toContain('autocapitalize="off"')
        ->and($tag)->toContain('spellcheck="false"');
});

/**
 * iOS Safari MEMPERBESAR halaman saat sebuah input difokus kalau font-size efektifnya di
 * bawah 16px -- meski inputnya tak terlihat. Zoom itu menggeser seluruh area teks dan
 * memindahkan caret dari tempatnya. `text-base` = 16px menahannya.
 */
it('keeps the typing input at 16px so iOS does not zoom on focus', function () {
    $user = User::factory()->create();

    expect(typingInputTag($this->actingAs($user)->get(route('typing'))->getContent()))
        ->toContain('text-base');
});

/**
 * Inputnya tak boleh menelan sentuhan (ia menutupi seluruh area teks), jadi yang memegang
 * tap adalah kontainer teksnya lalu memanggil focus() -- dan itu HARUS terjadi di dalam
 * handler gestur: iOS hanya membuka keyboard untuk focus() yang dipicu gestur pengguna.
 */
it('focuses the input from a tap on the text area', function () {
    $user = User::factory()->create();

    $html = $this->actingAs($user)->get(route('typing'))->getContent();

    expect($html)->toContain('focusTypingInput()')
        ->and(typingInputTag($html))->toContain('pointer-events-none');
});

/**
 * Tiga pengait yang membuat keyboard layar bisa dipakai:
 *
 *  - `beforeinput` -> jalur utama; `e.data` dipakai karena Gboard melaporkan `keydown`
 *    sebagai 'Unidentified'/229 selagi menyusun kata;
 *  - `input`       -> jaring pengaman untuk composition yang TAK cancelable, sehingga teks
 *                     tetap mendarat di field dan harus dikuras;
 *  - `keydown`     -> hanya Backspace, satu-satunya tuts yang dilaporkan andal.
 */
it('wires the soft-keyboard event path on the typing input', function () {
    $tag = typingInputTag(
        $this->actingAs(User::factory()->create())->get(route('typing'))->getContent()
    );

    expect($tag)->toContain('beforeinput')
        ->and($tag)->toContain('input')
        ->and($tag)->toContain('keydown');
});

/**
 * Semua input mobile disintesis ke `handleInput()` YANG SAMA, tidak menulis state sendiri.
 *
 * Ini batasan terpenting dari perubahan ini: `handleInput()` yang memberi makan
 * `missedChars`, `errorEvents`, penghitung keystroke, dan `trackIdle()` (deteksi AFK). Jalur
 * kedua yang menulis langsung akan memecah invarian
 * `Σ titik grafik === Σ missedChars` yang dijaga TypingErrorInspectorTest, dan mencemari
 * plafon karakter SoloSessionGuard.
 */
it('feeds mobile input through the same handleInput as a physical key', function () {
    $js = file_get_contents(resource_path('js/typing-game.js'));

    expect($js)->toContain('feedKey(')
        // Bentuknya harus persis yang dibaca handleInput: key + dua flag modifier +
        // preventDefault(). Kurang satu, handleInput akan meledak atau salah cabang.
        ->and($js)->toMatch('/this\.handleInput\(\{\s*key/');
});

it('offers a tap hint on touch devices in both languages', function () {
    foreach (['en', 'id'] as $locale) {
        expect(__('typing.tap_to_type', [], $locale))->not->toBe('typing.tap_to_type');
    }

    expect(file_get_contents(resource_path('css/app.css')))->toContain('.touch-only');
});

/**
 * Tag <input> milik area mengetik, atau string kosong kalau tak ada.
 *
 * Sengaja BUKAN null: `expect(null)->toContain(...)` membuat Pest memformat diff atas
 * seluruh HTML halaman dan menghabiskan memori PHP sebelum pesan gagalnya sempat tampil.
 * String kosong gagal dengan pesan yang bisa dibaca.
 */
function typingInputTag(string $html): string
{
    return preg_match('/<input\b[^>]*x-ref="typingInput"[^>]*>/s', $html, $m) ? $m[0] : '';
}
