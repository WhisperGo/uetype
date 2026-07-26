<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * ===== MENGETIK DI BALAPAN LEWAT KEYBOARD LAYAR =====
 *
 * Mode solo sudah digarap untuk perangkat sentuh (typing-engine.md §3.7.a, dikunci
 * MobileTypingInputTest). Pelajarannya tak pernah ikut ke multiplayer, dan akibatnya balapan
 * praktis tak bisa dimainkan di HP karena DUA sebab yang berdiri sendiri:
 *
 *  1. Input balapan tak mematikan autocapitalize. Huruf pertama tiap kata dikapitalisasi
 *     otomatis, gagal cek prefiks, lalu DIBUANG oleh word-lock di checkInput(). Pemain
 *     menekan huruf dan tak terjadi apa-apa.
 *
 *  2. Kata hanya bisa maju lewat `@keydown.space`. Gboard melaporkan `keydown` sebagai
 *     'Unidentified'/keyCode 229 selagi menyusun kata, jadi handler itu tak menyala; spasinya
 *     lalu mendarat sebagai karakter biasa, dan karena "the " bukan prefiks dari "the" ia ikut
 *     dibuang. Pemain terkunci selamanya di kata pertama, tanpa satu pun pesan error.
 *
 * Keduanya gagal TANPA SUARA -- word-lock memang menolak input diam-diam -- sehingga bagi
 * pemain ini tak terbaca sebagai kerusakan, melainkan "HP-ku lemot".
 *
 * CATATAN PENTING soal cakupan: proyek ini tak punya test yang mengeksekusi JavaScript (lihat
 * RaceAssetTest). Jadi berkas ini KONTRAK MARKUP & MODUL -- ia mengunci keberadaan jalurnya,
 * bukan membuktikan keyboardnya benar-benar bekerja. Perilaku wajib diverifikasi manual di
 * Android dan iOS; checklistnya di docs/mobile-test-checklist.md.
 */

/**
 * Tag <input> milik arena balapan, atau string kosong kalau tak ada.
 *
 * Sengaja BUKAN null: `expect(null)->toContain(...)` membuat Pest memformat diff atas seluruh
 * HTML halaman dan menghabiskan memori PHP sebelum pesan gagalnya sempat tampil. Alasan yang
 * sama didokumentasikan di MobileTypingInputTest.
 */
function raceInputTag(string $html): string
{
    return preg_match('/<input\b[^>]*x-model="typedText"[^>]*>/s', $html, $m) ? $m[0] : '';
}

/** Arena balapan yang benar-benar terender, dengan satu pemain (bukan penonton). */
function raceArenaHtml(): string
{
    $user = User::factory()->create();

    $room = Room::create([
        'code' => 'MOB123',
        'host_id' => $user->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox',
        'race_starts_at' => now()->subSeconds(5),
    ]);

    RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'is_ready' => true,
        'progress_percent' => 0,
    ]);

    return Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', 'MOB123')
        ->set('step', 'racing')
        ->html();
}

/** Tag <input> yang membawa atribut `wire:model` tertentu, dari sebuah berkas Blade. */
function inputTagWithWireModel(string $view, string $model): string
{
    $markup = tanpaKomentarBlade(file_get_contents(resource_path("views/livewire/{$view}.blade.php")));

    return preg_match('/<input\b[^>]*wire:model="'.preg_quote($model, '/').'"[^>]*>/s', $markup, $m)
        ? $m[0]
        : '';
}

// ===== 1. Atribut platform =====

/**
 * Autocorrect, kapitalisasi otomatis, dan pemeriksa ejaan menyunting justru apa yang sedang
 * diukur. Di balapan akibatnya lebih keras daripada di solo: word-lock menolak karakter yang
 * tak persis cocok, jadi satu huruf kapital dari keyboard membuat kata tak pernah bisa dimulai.
 */
it('mematikan seluruh fitur koreksi teks di input balapan', function () {
    $tag = raceInputTag(raceArenaHtml());

    expect($tag)->not->toBe('', 'Input balapan (x-model="typedText") tak ditemukan di arena.')
        ->and($tag)->toContain('autocomplete="off"')
        ->and($tag)->toContain('autocorrect="off"')
        ->and($tag)->toContain('autocapitalize="off"')
        ->and($tag)->toContain('spellcheck="false"')
        ->and($tag)->toContain('inputmode="text"');
});

/**
 * iOS Safari MEMPERBESAR halaman saat input ber-font-size < 16px difokus. Di tengah balapan
 * zoom itu menggeser paragraf dan lintasan sekaligus. `text-base` = 16px menahannya.
 *
 * Sudah benar sebelum perubahan ini -- dipasang sebagai kunci supaya tak hilang saat kelak
 * seseorang merapikan kelas input.
 */
it('menahan input balapan di 16px agar iOS tak zoom saat difokus', function () {
    expect(raceInputTag(raceArenaHtml()))->toContain('text-base');
});

/**
 * `:placeholder` bukan label yang sah bagi pembaca layar -- ia hilang begitu ada isinya.
 * Input solo sudah punya aria-label; balapan tertinggal.
 */
it('memberi input balapan label yang terbaca pembaca layar', function () {
    expect(raceInputTag(raceArenaHtml()))->toContain('aria-label=');
});

/**
 * Tuts aksi keyboard layar (pojok kanan bawah) diberi label "next" DAN benar-benar memajukan
 * kata. Solo memakai "done" -- di tengah balapan itu keliru, karena "done" menutup keyboard
 * dan pemain harus menyentuh layar lagi untuk melanjutkan.
 *
 * Ini juga jaring pengaman kedua: kalau jalur spasi bermasalah di keyboard tertentu, Enter
 * tetap memajukan kata lewat handler yang sama.
 */
it('menjadikan tuts Enter jalur maju kata yang sah, bukan label kosong', function () {
    $tag = raceInputTag(raceArenaHtml());

    expect($tag)->toContain('enterkeyhint="next"')
        ->and($tag)->toContain('handleSpace($event)');
});

// ===== 2. Jalur spasi =====

/**
 * Inti perbaikannya: spasi harus diterima meski datang sebagai TEKS, bukan cuma sebagai
 * keydown. Solo tak bisa ditiru mentah-mentah -- ia membatalkan `beforeinput` dan selalu
 * mengosongkan field, sedangkan di sini `x-model="typedText"` berarti field ITU state kata
 * yang sedang diketik.
 */
it('menerima spasi yang datang sebagai teks, bukan cuma sebagai keydown', function () {
    $checkInput = raceMethodSource('checkInput()');

    expect($checkInput)->toContain("indexOf(' ')")
        ->and($checkInput)->toContain('this.advanceWord()');
});

/**
 * Satu definisi "kata boleh lewat", dipakai kedua jalur. Kalau logikanya disalin ke
 * checkInput(), aturan word-lock akan punya dua salinan yang bisa menyimpang -- dan yang
 * menyimpang diam-diam di sini adalah gerbang anti-cheat.
 */
it('menyalurkan spasi keydown dan spasi ketikan ke advanceWord yang sama', function () {
    $handleSpace = raceMethodSource('handleSpace(e)');

    expect($handleSpace)->toContain('this.advanceWord()')
        // Aturannya pindah ke advanceWord(); handleSpace tak boleh menyimpan salinannya.
        ->and($handleSpace)->not->toContain('correctCharsFromPastWords')
        ->and($handleSpace)->not->toContain('typedText !== targetWord');
});

/**
 * Yang membuat kedua jalur SALING EKSKLUSIF adalah preventDefault() pada fase keydown: ia
 * membatalkan default action, sehingga beforeinput/penyisipan/input tak pernah terjadi dan
 * spasi tak pernah masuk ke field.
 *
 * Karena itu ia tak boleh bisa dilewati. Sebelumnya sebuah `return` mendahuluinya: kalau
 * guard menyala, spasi bocor ke field dan KEDUA jalur jalan. Itu tak berbahaya hanya karena
 * input kebetulan di-`:disabled` dengan kondisi yang sama persis -- kebetulan yang tak dijaga
 * siapa pun.
 */
it('membatalkan spasi desktop sebelum return mana pun bisa membocorkannya', function () {
    // Komentar dibuang dulu: prosa di metode ini menyebut kata "return" saat menjelaskan
    // justru larangan yang sedang diuji, dan strpos() akan menemukannya lebih dulu.
    $handleSpace = tanpaKomentarJs(raceMethodSource('handleSpace(e)'));

    $cancel = strpos($handleSpace, 'e.preventDefault()');
    $return = strpos($handleSpace, 'return');

    expect($cancel)->not->toBeFalse('handleSpace tak memanggil e.preventDefault().')
        ->and($return)->not->toBeFalse()
        ->and($cancel)->toBeLessThan($return);
});

/**
 * Satu event input = MAKSIMUM satu kata maju.
 *
 * Swipe-typing dan paste mengirim beberapa kata sekaligus. Kalau sisanya disimpan atau
 * di-loop, pemain bisa menyelesaikan balapan dengan segelintir gestur -- dan karena juara
 * ditentukan WAKTU SELESAI, itu langsung jadi strategi optimal. Persis kelas bug yang
 * word-lock diciptakan untuk membunuh (multiplayer-race.md §3.2).
 *
 * `return` setelah advance juga wajib demi alasan kedua: checkInput() memegang targetWord
 * dari SEBELUM kata maju, jadi jatuh terus akan menilai kata terakhir dengan target basi.
 */
it('tak pernah memajukan lebih dari satu kata per event input', function () {
    // Tanpa komentar: `return` yang diuji di sini didahului prosa yang menjelaskan kenapa ia
    // wajib ada, dan prosa itu memisahkan kedua baris yang harus bersebelahan.
    $checkInput = tanpaKomentarJs(raceMethodSource('checkInput()'));

    expect($checkInput)->toMatch('/slice\(0,\s*spaceAt\)/')
        ->and($checkInput)->toMatch('/this\.advanceWord\(\);\s*return;/');
});

/**
 * Paste ditutup SECARA SENGAJA. Hari ini sebuah paragraf yang di-paste tertolak hanya karena
 * ia bukan prefiks kata target -- perlindungan yang tak disengaja, dan jalur nilai yang baru
 * melemahkannya (kata pertamanya kini valid). Efek sampingnya di desktop: paste satu kata yang
 * benar berhenti bekerja. Itu memang yang diinginkan.
 */
it('menolak paste agar satu paragraf tak bisa dijatuhkan ke field', function () {
    $tag = raceInputTag(raceArenaHtml());

    expect($tag)->toContain('@paste.prevent')
        ->and($tag)->toContain('@drop.prevent');
});

/**
 * Kata yang mendarat utuh dalam satu event (swipe/autocorrect) tak pernah melewati penghitung
 * per-karakter. Tanpa credit ini pemain swipe selalu tampil akurasi 100% sementara pemain
 * desktop membayar tiap typo.
 */
it('menghitung kata hasil swipe agar akurasi bukan 100% gratis', function () {
    expect(raceMethodSource('checkInput()'))
        ->toMatch('/totalKeystrokes \+= Math\.max\(0, candidate\.length/');
});

/**
 * Seluruh rancangan bersandar pada `typedText` yang selalu mutakhir saat checkInput() membacanya.
 * Modifier `.lazy`/`.debounce`/`.throttle` akan membuatnya membaca nilai basi -- spasi bisa
 * terlewat atau terbaca dua kali.
 */
it('menjaga x-model bebas modifier yang membuat nilai ketikan basi', function () {
    $tag = raceInputTag(raceArenaHtml());

    expect($tag)->toContain('x-model="typedText"')
        ->and($tag)->not->toMatch('/x-model\.(lazy|debounce|throttle)/');
});

// ===== 3. Layout arena di layar sempit =====

/**
 * Lintasan balapan MENGHILANG di HP.
 *
 * Baris lane menaruh tiga kolom lebar-tetap dalam satu baris: badge peringkat, nama (`w-40`),
 * dan WPM (`w-16`) -- bersama gap dan padding butuh ~324px. Layar 360px hanya menyisakan ~250px
 * untuk lane, jadi lintasan yang `flex-1` menyusut ke lebar NOL dan barisnya tetap meluber
 * (angka WPM terpotong di tepi kanan). Maskot lalu duduk persis di atas bendera finis tanpa
 * lintasan di antaranya -- terbaca sebagai "balapannya tidak jalan", padahal cuma tak muat.
 *
 * Perbaikannya: di bawah `sm`, lintasan turun ke barisnya sendiri selebar penuh.
 */
it('memberi lintasan balapan satu baris penuh di layar sempit', function () {
    $markup = tanpaKomentarBlade(
        file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'))
    );

    expect($markup)
        // Barisnya boleh membungkus di bawah sm, dan kembali satu baris dari sm ke atas.
        ->toContain('flex flex-wrap items-center rounded-xl')
        ->toContain('sm:flex-nowrap')
        // Lintasan turun ke bawah dan mengambil lebar penuh -- bukan sisa ruang (yang nol).
        ->toContain('order-last basis-full sm:order-none sm:basis-auto sm:flex-1');
});

/**
 * Nama pemain tak boleh lagi mengunci lebar tetap di HP: begitu lintasan pindah baris,
 * alignment antar-lane tak lagi bergantung padanya, dan lebar tetap hanya menyisakan ruang
 * lebih sedikit untuk nama yang panjang.
 */
it('melepas lebar tetap kolom nama di layar sempit', function () {
    $markup = tanpaKomentarBlade(
        file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'))
    );

    expect($markup)->toContain('flex-1 min-w-0 sm:flex-none flex items-center gap-2 font-mono')
        // Lebar tetapnya kini hanya berlaku dari sm ke atas.
        ->toContain('sm:w-40')
        ->toContain('sm:w-32');
});

/**
 * Paragraf balapan dulu dirender setinggi seluruh teks -- di HP sekitar 14 baris, sehingga
 * field ketik berada jauh di bawah layar. Tiap ketikan membuat browser menggulir field yang
 * difokus kembali ke tampilan, jadi pemain bisa melihat KATA-nya atau FIELD-nya, tak pernah
 * keduanya: baca ke depan, gulir turun, ketik, tertarik turun lagi, gulir naik lagi.
 *
 * Sekarang paragrafnya dipotong tiga baris dan menggeser dirinya sendiri mengikuti kata yang
 * sedang diketik -- pola yang sama dengan mesin ketik solo.
 */
it('membatasi paragraf balapan jadi jendela tiga baris yang menggeser sendiri', function () {
    $markup = tanpaKomentarBlade(
        file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php'))
    );

    expect($markup)
        // Dipotong, dan TIDAK boleh jadi area gulir kedua yang harus digeser tangan.
        ->toContain('class="overflow-hidden" style="max-height: 4.875em;"')
        ->toContain('x-ref="wordsTrack"')
        ->toContain('translateY(-${wordScrollOffset}px)')
        // Penanda yang dipakai syncWordScroll() untuk menemukan kata aktif.
        ->toContain(':data-word-index="wIdx"');

    // Kartu paragraf WAJIB `wire:ignore`. checkInput() memanggil $wire.updateRaceProgress() tiap
    // ketikan (dithrottle ~120ms), jadi tiap respons Livewire me-morph subtree ini ~8x/detik. HTML
    // server untuk track TAK punya atribut `style` -- transform-nya ada semata karena Alpine
    // mengevaluasi :style di klien -- jadi morphdom MENGHAPUSNYA dan paragraf melompat ke
    // translateY(0), sambil dianimasikan 85ms oleh transition-transform. Alpine tak memperbaikinya:
    // :style hanya dievaluasi ulang saat wordScrollOffset BERUBAH, dan di antara dua kata maju ia
    // konstan. Inilah bug "kotaknya terkadang berpindah tiba-tiba"; enam upaya sebelumnya semuanya
    // mengubah RUMUS di syncWordScroll(), padahal tak ada rumus yang selamat kalau atributnya
    // dihapus dari elemen yang ditulisinya. Perbaikan dua lapis yang sama sudah dipakai caret solo
    // (typing-engine.blade.php): wire:ignore + transform reaktif.
    expect($markup)->toMatch('/wire:ignore\s+class="font-mono text-xl leading-relaxed/');

    // Track WAJIB `relative`: itu yang membuatnya jadi offsetParent, sehingga offsetTop kata
    // aktif diukur dari atas track (bukan kartu jauh di atas -> paragraf tergeser keluar layar).
    expect($markup)->toMatch('/x-ref="wordsTrack"\s+class="relative /');

    // Metrik kotak tiap kata WAJIB konstan (padding pada SEMUA kata), supaya kata aktif tak
    // berubah lebar saat aktif -> tak me-reflow baris -> scroll tak meloncat. Base-nya
    // `outline-none`: highlight aktif pakai OUTLINE (di luar box, tanpa biaya layout), bukan
    // ring/border yang tergores separuh di tepi jendela klip lalu berkedip.
    expect($markup)->toContain('class="px-1 rounded outline-none"');

    // Isolasi <span> kata (dari :data-word-index sampai x-text) untuk cek dua hal pada kata aktif:
    // (1) TIDAK font-bold (glyph melebar -> rewrap baris seperti padding), dan (2) highlight-nya
    // pakai `outline`, BUKAN `ring`/`border` yang berkedip di tepi jendela.
    preg_match('/:data-word-index="wIdx".*?x-text="word"/s', $markup, $wordSpan);
    expect($wordSpan[0] ?? '')
        ->not->toContain('font-bold')
        ->not->toContain('ring-')
        ->toContain('outline outline-1');

    // Track TIDAK boleh punya row-gap (`gap-y-*`): tiga baris harus muat persis 4.875em (3 x
    // leading-relaxed) sesuai jendela klip & stride gapless mesin solo. Gap baris akan mendorong
    // baris ketiga keluar jendela DAN membuat stride tak sama dengan line-height yang diasumsikan
    // logika scroll. Spasi antar kata horizontal saja (`gap-x-2`).
    preg_match('/x-ref="wordsTrack"\s+class="([^"]*)"/', $markup, $trackClass);
    expect($trackClass[1] ?? '')->toContain('gap-x-2')
        ->not->toMatch('/\bgap-y-/');
});

/**
 * Aturan scroll HARUS meniru mesin solo (typing-game.js): kata aktif ditahan di baris TENGAH
 * dari tiga baris tampak, dan jendela baru bergeser saat kata aktif mencapai baris KETIGA --
 * bukan saat baru meninggalkan baris satu. Inilah yang menghilangkan jitter "turun kepagian /
 * naik lagi di huruf pertama baris berikutnya": window dikuantisasi ke kelipatan line-height dan
 * baru bereaksi satu baris lebih lambat, jadi baris 1 dan 2 sama-sama diam.
 *
 * Diukur lewat offsetTop -- posisi LAYOUT murni (kebal transform & timing animasi), dari track
 * `position: relative`. Metrik kotak tiap kata konstan (diuji di test markup) supaya baris tak
 * pernah ter-rewrap saat cursor pindah.
 *
 * Recompute-nya lewat requestAnimationFrame (scheduleWordScroll) SETELAH layout -- persis pola
 * schedulePositionUpdate mesin solo -- supaya offsetTop dibaca pasca-reflow, bukan sebelum. Itu
 * yang membuat gerak scroll-nya sama seperti solo, bukan telat satu frame.
 */
it('meniru aturan scroll solo: geser di baris ketiga, tahan kata aktif di baris tengah', function () {
    $sync = raceMethodSource('syncWordScroll()');

    expect($sync)->toContain('data-word-index')
        ->and($sync)->not->toContain('getBoundingClientRect')
        // Baris tiap kata di-SNAPSHOT sekali ke _lineOf[], lalu dibaca dari map itu -- BUKAN
        // re-measure live tiap panggil (yang goyang saat morph/transisi -> "turun lagi").
        ->and($sync)->toContain('_lineOf')
        // Snapshot: bulatkan offsetTop tiap kata jadi indeks baris.
        ->and($sync)->toContain('Math.round')
        ->and($sync)->toContain('el.offsetTop')
        // Rumus solo: geser hanya saat baris ketiga (index >= 2), turunkan satu baris.
        ->and($sync)->toContain('lineIndex >= 2')
        ->and($sync)->toContain('(lineIndex - 1) * lineHeight');

    // Recompute dijadwalkan lewat rAF (seperti schedulePositionUpdate solo), dibaca pasca-layout.
    $schedule = raceMethodSource('scheduleWordScroll()');
    expect($schedule)->toContain('requestAnimationFrame')
        ->and($schedule)->toContain('this.syncWordScroll()');

    $arena = tanpaKomentarJs(file_get_contents(resource_path('js/race-arena.js')));

    // syncWordScroll dipanggil TEPAT sekali di kode (dari dalam rAF scheduleWordScroll); semua
    // titik pemicu lewat scheduleWordScroll. Assertion ini yang mencegah perbaikan di masa depan
    // memanggilnya sinkron lalu membaca offsetTop pra-reflow.
    expect(substr_count($arena, 'this.syncWordScroll()'))->toBe(1);
    // Dipicu dari EMPAT tempat: init (reload di tengah balapan), _invalidateScroll (re-wrap:
    // resize / ResizeObserver / fonts.ready), advanceWord (kata maju), dan checkInput (SELF-HEAL
    // tiap ketikan). Yang terakhir itu yang dipunyai solo dan dulu tak dipunyai balapan: solo
    // recompute tiap karakter sehingga satu frame buruk terkoreksi sendiri, sementara balapan cuma
    // recompute saat kata maju -- jadi apa pun yang salah bertahan sampai spasi berikutnya.
    expect(substr_count($arena, 'this.scheduleWordScroll()'))->toBeGreaterThanOrEqual(4);
});

/**
 * Snapshot baris (_lineOf) hanya sah untuk LEBAR dan FONT saat ia diukur, jadi setiap penyebab
 * re-wrap wajib menggugurkannya. Kelewat satu, dan _lineOf diam-diam terus menggeser ke baris yang
 * kata aktifnya sudah tak di sana lagi -- tanpa jalur pulih, karena offset-nya fungsi murni dari
 * map yang kini salah. Gejalanya beda dari lompatan berulang: jendela mendarat di baris yang salah
 * lalu bertahan begitu.
 */
it('menggugurkan snapshot baris di setiap penyebab re-wrap, bukan cuma resize window', function () {
    $init = raceMethodSource('init()');

    expect($init)
        // Rotate / resize window. Tetap ada di samping observer: di sebagian browser mobile
        // keyboard lunak mengubah visual viewport tanpa mengubah ukuran track.
        ->toContain("addEventListener('resize'")
        // Perubahan pada box TRACK sendiri -- TAK ADA yang memicu `resize`: flip $arenaDense saat
        // racer ke-4 masuk (p-8 -> p-5), banner sudden-death yang muncul in-flow di atas kotak,
        // lane lawan yang membungkus dan mengubah lebar kartu.
        ->toContain('ResizeObserver')
        // Web font yang mendarat setelah paint pertama me-rewrap semua baris. Diukur sebelum itu,
        // snapshot-nya menggambarkan layout font FALLBACK -- salah untuk seluruh balapan, dan ini
        // bukan resize, jadi tak ada lagi yang akan mengoreksinya.
        ->toContain('document.fonts');

    // Observer memegang referensi ke elemen track, jadi WAJIB dilepas saat komponen mati --
    // paragrafnya dibuang saat selesai/menyerah.
    expect(raceMethodSource('destroy()'))->toContain('_scrollObserver');
});

// ===== 4. Konfirmasi exact-match =====

/**
 * Dua aksi paling tak bisa dibatalkan di aplikasi ini dijaga dengan "ketik ulang namanya",
 * dan keduanya dibandingkan KETAT di server (Clans::disbandClan, Settings::deleteAccount).
 *
 * Di HP, autocapitalize mengubah huruf pertama menjadi kapital -- sehingga nama yang benar
 * pun tak akan pernah cocok, dan pemilik akun tak bisa menghapus akunnya sendiri maupun
 * membubarkan clan-nya dari HP. Bukan ketidaknyamanan: fiturnya mustahil diselesaikan.
 */
it('membiarkan konfirmasi bubarkan clan dan hapus akun diketik dari HP', function () {
    $cases = [
        'konfirmasi bubarkan clan' => inputTagWithWireModel('clans', 'confirmDisbandName'),
        'konfirmasi hapus akun' => inputTagWithWireModel('settings', 'confirmUsername'),
    ];

    foreach ($cases as $label => $tag) {
        // toContain() menerima BANYAK needle sebagai varargs -- argumen kedua akan diperiksa
        // sebagai string yang harus ada, bukan sebagai pesan gagal. Label dibawa lewat toBe().
        expect($tag)->not->toBe('', "Input {$label} tak ditemukan.")
            ->and($tag)->toContain('autocapitalize="off"', 'autocorrect="off"', 'spellcheck="false"');
    }
});
