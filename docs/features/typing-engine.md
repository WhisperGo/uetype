# Fitur 1 — Mesin Ketik Solo

**Komponen utama:** [`App\Livewire\TypingEngine`](../../app/Livewire/TypingEngine.php)
**View:** [`resources/views/livewire/typing-engine.blade.php`](../../resources/views/livewire/typing-engine.blade.php)
**Route:** `/` , `/typing` (keduanya membuka komponen yang sama)

---

## 1. Apa Ini

Inti dari seluruh aplikasi: layar tempat pengguna mengetik teks dan diukur kecepatannya.
Tersedia untuk **tamu maupun user login** — tamu boleh mengetik, cuma tidak mendapat XP/rekor.

Mendukung **tiga mode utama**, masing-masing dengan sub-mode:

| Mode | Sub-mode | Metrik yang diukur | Cara berakhir |
|------|----------|--------------------|----------------|
| **Time** | 15 / 30 / 60 / 120 detik | WPM & akurasi | waktu habis |
| **Words** | 10 / 25 / 50 / 100 kata | WPM & akurasi | semua kata selesai |
| **Survival** | easy / medium / hard | **lama bertahan** (durasi) | pemain "mati" (salah/telat) |

## 2. Cara Kerja (alur singkat)

1. `mount()` memulihkan preferensi (mode, sub-mode, bahasa) dari session, lalu `generateText()`.
2. `generateText()` merakit teks acak dari **wordlist JSON** per bahasa (bukan dari DB).
3. Klien (Alpine) menangkap ketikan, menghitung WPM live untuk ditampilkan, dan saat sesi
   selesai memanggil `saveResult(...)` dengan **data mentah** (durasi ms, jumlah keystroke,
   keystroke benar, dsb).
4. Server (`saveResult`) **menghitung ulang** WPM/akurasi via anti-cheat, menyimpan hasil,
   memberi EXP, lalu redirect ke halaman hasil.

## 3. Keputusan Desain & Justifikasi

### 3.1 Teks dirakit acak dari wordlist JSON, bukan diambil dari tabel `texts`

```php
$this->textId = null; // selalu null untuk time/words/survival
$path = TypingLanguage::wordlistPath($this->contentLang);
// ... shuffle + slice sampai jumlah kata terpenuhi
```

**Justifikasi:**
- **Variasi tak terbatas & anti-hafalan.** Kalau teks diambil dari baris DB tetap, pemain bisa
  menghafal urutan kata dan memompa WPM. Perakitan acak membuat tiap sesi unik.
- **Ringan.** Tak perlu query DB per sesi; cukup baca satu file JSON dan `shuffle`.
- **Loop `while` sampai `$limit` terpenuhi** menangani kasus wordlist lebih pendek dari jumlah
  kata yang diminta (mis. words=100 tapi kamus hanya 60 kata) — kata diulang, layar tak pernah
  kekurangan teks.

### 3.2 Whitelist mode di server (`ALLOWED_SUBMODES` + `normalizeMode()`)

```php
private const ALLOWED_SUBMODES = [
    'time' => ['15', '30', '60', '120'],
    'words' => ['10', '25', '50', '100'],
    'survival' => ['easy', 'medium', 'hard'],
];
```

**Justifikasi:**
`mainMode`/`subMode` dikendalikan client dan ikut masuk `mode_config` yang jadi **kunci filter
leaderboard**. Kalau nilai liar (mis. `time=1`) lolos ke DB, ia mencemari leaderboard dengan
kategori palsu. `normalizeMode()` adalah **satu sumber kebenaran** yang dipakai baik saat ganti
mode maupun saat menyimpan hasil — nilai di luar whitelist di-*fallback* ke default aman, bukan
ditolak keras (tidak bikin user stuck).

### 3.3 WPM/akurasi tidak dipercaya dari client

```php
// WPM/akurasi dari client TIDAK diterima — server selalu hitung ulang sendiri.
$check = app(AntiCheatService::class)->check($correctKeystrokes, $totalKeystrokes, $duration);
$finalNetWpm = $check['net_wpm'];
```

**Justifikasi:** trust boundary. Detail lengkap di [anti-cheat-wpm.md](anti-cheat-wpm.md).
Client hanya mengirim **jumlah karakter & durasi**, bukan hasil akhir WPM. Data mentah tersebut
tetap dianggap tidak tepercaya: `SoloSessionGuard`, bukti timing, batas throughput, dan evaluator
longitudinal membatasi apakah klaim boleh disimpan atau dipakai sebagai angka publik.

### 3.4 Rekor (PB) hanya dari mode Time/Words — Survival dikecualikan

```php
if ($this->mainMode !== 'survival' && $finalNetWpm > (float) $user->highest_wpm) {
    $user->highest_wpm = $finalNetWpm;
}
```

**Justifikasi:** Survival dimainkan **di bawah tekanan stamina** (harus terus benar atau mati),
jadi WPM-nya bukan perbandingan *apple-to-apple* dengan Time/Words. Rekor Survival diukur dari
**lama bertahan** (`duration_seconds`), bukan WPM. Ini menjaga arti "rekor WPM" tetap konsisten.

### 3.4.a Dua angka "terbaik" yang berbeda: rekor karier vs rekor per mode

| Angka | Sumber | Cakupan | Dipakai di |
|---|---|---|---|
| **Rekor karier** | kolom `users.highest_wpm` | lintas mode (time **dan** words, semua config) | kartu profil, daftar teman, achievement 100/150/200 WPM |
| **Rekor mode** | `MAX(net_wpm)` dari `typing_results` per `mode`+`mode_config` | satu konfigurasi saja | penentu **PB di layar hasil**, dan pace **Ghost Mode** |

**Justifikasi (masalah yang diperbaiki):** dulu layar hasil membandingkan sesi dengan
`highest_wpm` — satu angka global. Tes pendek selalu menghasilkan WPM lebih tinggi, jadi hasil
`time 120` diukur terhadap rekor yang mungkin dibuat di `time 15`, dan selisihnya **nyaris selalu
negatif** (layar menampilkan hal seperti `-39 vs record 70.2` hampir tiap sesi). Survival sejak
awal sudah benar — rekornya diturunkan per `mode`+`mode_config` — jadi mode standard kini
mengikuti pola yang sama, sekaligus selaras dengan cara **leaderboard** mengelompokkan hasil
(`mode_config` memang kunci filternya).

Konsekuensi yang disengaja: ada **8 kantong rekor** (time 15/30/60/120 + words 10/25/50/100),
dan karena `MAX()` atas kantong kosong bernilai `null`, **hasil pertama di tiap kantong otomatis
jadi PB** — aturan yang sama persis dengan survival.

`highest_wpm` **tidak dibuang**: ia menjawab pertanyaan berbeda ("secepat apa pemain ini pernah
mengetik") dan tetap jadi angka headline sosial. Dua pertanyaan, dua angka.

**Rekor hanya disebut saat dipecahkan.** Baris "selisih vs rekor" untuk sesi yang *tidak*
memecahkan rekor sudah dihapus: karena dasar perbandingannya timpang, baris itu berfungsi sebagai
pengingat kekalahan tiap sesi, bukan informasi. Sekarang hanya pil emas
`result.new_personal_best` yang muncul, dan hanya ketika rekornya benar-benar pecah.

### 3.4.b Sesi yang ditinggalkan (AFK) tidak dicatat

```php
$isAfk = $this->isAfkSession((float) $maxIdleMs / 1000, $duration);
// ambang = max(AFK_MIN_IDLE_SECONDS 10 dtk, durasi × AFK_IDLE_FRACTION 0.25)
```

Di mode **time**, timer berjalan sendiri sampai habis lalu **mengirim hasilnya** — jadi "ketik
dua huruf lalu pergi" mendarat di riwayat sebagai baris 1 WPM dan **menyeret turun rata-rata**
pemain (`AVG(net_wpm)` di halaman Stats). Mode **words** tak pernah submit kalau ditinggal
(sesi hanya berakhir saat semua kata selesai), tapi AFK **di tengah** menggelembungkan durasi.
**Survival** sudah aman dengan sendirinya: stamina habis → mati dalam hitungan detik.

**Justifikasi tiga keputusan yang mudah salah:**

1. **Sinyalnya JEDA, bukan throughput.** Godaannya adalah memakai ulang `throughput_too_low`
   yang sudah ada. Itu keliru: throughput adalah **rata-rata**, dan pemula hunt-and-peck 5 WPM
   = 25 karakter/60 detik = **0,42 cps — di bawah ambang 0,5**, jadi hasil pemula jujur ikut
   dibuang. Justru itu *false positive* yang sengaja dihindari (lihat
   [anti-cheat-wpm.md](anti-cheat-wpm.md) §4 dan test "keeps a slow time-mode session"). Pengetik
   lambat menyebar ketikannya **merata**; sesi AFK punya **satu sunyi panjang**. Hanya jeda yang
   bisa membedakan keduanya.

2. **Ambang proporsional + lantai.** Jeda 12 detik adalah hampir seluruh sesi `time 15`, tapi
   sekadar jeda berpikir di `time 120`. Ambang tetap akan berperilaku sangat berbeda antar
   sub-mode, jadi ambangnya 25% durasi dengan lantai 10 detik (time 15 → 10 dtk, time 60 → 15
   dtk, time 120 → 30 dtk).

3. **Data jeda datang dari client — dan di sini itu aman.** Server tak pernah melihat keystroke
   individual, jadi timing hanya ada di klien. Ini **tidak** melanggar prinsip "jangan percaya
   client" karena **insentifnya terbalik**: menyembunyikan jeda hanya membeli hasil yang *lebih
   buruk*, dan pemain yang ingin sesi buruknya dibuang sudah bisa sekadar **meninggalkan halaman**
   sebelum timer habis. Berbeda dengan WPM, tak ada yang bisa dimenangkan dengan memalsukannya.

**Clan War dikecualikan (`warLock !== null`) — ini menutup celah, bukan sekadar batas cakupan.**
Hasil yang ditolak **tak pernah mengisi claim** (penolakan terjadi sebelum transaksi yang
memanggil `attachToWarClaim()`), sehingga claim tetap terbuka. Kalau AFK ikut menolak hasil war,
pemain yang sedang jelek tinggal **berhenti mengetik** untuk membuang percobaannya lalu mengulang
slot itu — dan di war mode Words teksnya **tetap**, jadi ia mengulang dengan teks yang sudah
dilihatnya. Itu mementahkan aturan "satu claim, satu teks, satu kesempatan" yang dijaga
[ClanWarRerollTest](../../tests/Feature/ClanWarRerollTest.php). AFK saat war cukup jadi skor jelek
untuk clan sendiri — merugikan pelakunya, bukan eksploit.

**Ditampilkan, bukan ditelan.** Berbeda dari jalur reject anti-cheat (flash + redirect balik ke
`/typing` tanpa pemain melihat apa pun), sesi AFK **tetap membuka layar hasil lengkap** dengan
banner `result.afk_not_recorded` — hanya saja tak ada yang ditulis ke DB (tanpa XP, PB, achievement,
maupun baris `typing_results`). Ini gratis karena halaman hasil membaca dari **session**, bukan DB.
Sejajar dengan multiplayer yang menandai hasil DNF/ditolak di layar alih-alih menyembunyikannya
([multiplayer-race.md](multiplayer-race.md) §3.6).

### 3.5 Consistency sebagai metrik presentasi (bukan anti-cheat)

`computeConsistency()` menghitung **koefisien variasi** dari histori WPM per-detik
(`1 - stddev/mean`). 100% = kecepatan sangat rata.

**Justifikasi:** ini murni informasi untuk pemain ("apakah tempo saya stabil?"), **bukan**
gerbang validasi. Butuh minimal 2 sampel & mean > 0 agar tidak membagi nol / bias pada sesi
sangat pendek.

### 3.6 Hasil disimpan di **session**, lalu redirect ke halaman result

**Justifikasi:** halaman hasil ([`TypingResult`](../../app/Livewire/TypingResult.php)) adalah
route terpisah (`/result`) yang membaca dari session. Ini membuat halaman ketik dan halaman
hasil **decoupled** — hasil bisa di-refresh tanpa menyimpan ulang, dan tamu pun bisa melihat
hasilnya tanpa menulis ke DB.

### 3.7 Input ditangkap global, tapi tak "bocor" dari field lain

Ketikan **tuts fisik** ditangkap lewat listener global `@keydown.window` supaya pemain desktop
bisa langsung mengetik tanpa harus klik area teks dulu. Konsekuensinya: listener ini menyala
untuk **setiap** keystroke di halaman — termasuk saat fokus ada di field lain seperti **input
chat overlay**.

**Justifikasi guard:** sebelum memanggil `handleInput()`, dicek `document.activeElement`. Kalau
fokus sedang di `INPUT` / `TEXTAREA` / elemen `contenteditable`, keystroke **dilewati** — biar
masuk ke field itu saja dan tak ikut men-trigger tes ketik di belakang. Tanpa guard ini,
mengetik pesan di overlay chat akan sekaligus memulai & mengisi paragraf typing. Lihat
[chat.md](chat.md#47-sembunyi-saat-sesi-testbalapan-aktif).

### 3.7.a Perangkat sentuh: keyboard layar butuh elemen fokusable

Dokumen ini dulu menyatakan input ditangkap global **"bukan input tersembunyi"**. Itu benar
untuk desktop, tapi berarti halaman ini **tak punya satu pun elemen fokusable** — dan setiap
tombol mode malah memanggil `$el.blur()` agar tak ada yang memegang fokus. Di HP akibatnya
bukan sekadar kurang nyaman: tak ada yang bisa disentuh untuk memunculkan keyboard layar dan
tak ada keyboard fisik yang mengirim `keydown`, jadi **tes mengetik sama sekali tak bisa
dimainkan**. Sekarang ada input tersembunyi (`x-ref="typingInput"`), khusus untuk itu.

**Semua input sentuh disintesis ke `handleInput()` yang sama.** Ini batasan terpentingnya:
`handleInput()` adalah satu-satunya penulis `missedChars`, `errorEvents`, penghitung keystroke,
dan `trackIdle()`. Jalur kedua yang menulis state sendiri akan memecah invarian
`Σ titik grafik === Σ missedChars` (§3.x) dan mencemari plafon karakter
[`SoloSessionGuard`](anti-cheat-wpm.md). Karena itu `feedKey()` membentuk objek yang persis
dibaca `handleInput()` — `key` + `ctrlKey`/`metaKey` + `preventDefault()` — dan tak menyentuh
apa pun selain memanggilnya.

**Kenapa `keydown` BUKAN jalur mobile.** Dua alasan yang berdiri sendiri:

1. **Gboard Android tak mengirim karakternya.** Selagi menyusun kata ia melaporkan
   `key: 'Unidentified'` (`keyCode` 229), jadi `handleInput()` tak pernah menerima huruf apa
   pun. Karakter harus diambil dari `beforeinput`/`input`.
2. **Kalau keduanya jalan, tiap keystroke dihitung dua kali.** Dengan input yang difokus,
   satu tuts fisik memicu `keydown` **dan** `beforeinput`. Yang menyelamatkan ini justru guard
   di §3.7: ia melewati listener global setiap kali sebuah `INPUT` memegang fokus, sehingga
   kendali **berpindah otomatis** ke jalur input. Guard itu tak perlu diubah sama sekali —
   dan **jangan** dilonggarkan untuk mengecualikan input ini, karena itulah yang mencegah
   penghitungan ganda.

**Backspace bisa dilaporkan dua kali** (sebagai `keydown` dan sebagai `beforeinput` bertipe
`deleteContentBackward`). Flag `_softDeleteHandled` membuat yang datang belakangan berhenti.
Keduanya tetap dipasang karena Backspace adalah satu-satunya tuts yang dilaporkan andal oleh
keyboard layar, sementara `beforeinput` menutup kasus keyboard yang tak mengirim `keydown`.
Penghapusan satu kata mengikuti shortcut platform: `Ctrl + Backspace` pada Windows/Linux dan
`Option + Backspace` (`altKey`) pada macOS. Modifier ikut diteruskan oleh input fokusable;
`deleteWordBackward` dari `beforeinput` dipetakan ke operasi yang sama.

**`beforeinput` dibatalkan bila bisa; kalau tidak, `input` yang menangani.** Membatalkannya
menjaga field tetap kosong sehingga tak ada nilai yang perlu di-diff maupun fragmen basi yang
terkirim ulang. Tapi event composition **tak selalu cancelable** — di situ teksnya tetap
mendarat, lalu `onTypingInput()` menguras field dan memainkannya. Tepat satu dari keduanya
yang memproses, jadi tak ada yang ganda dan tak ada yang tertelan.

**Teks masuk dimainkan per karakter.** Swipe-typing dan autocorrect mengirim satu kata utuh;
`feedText()` memecahnya supaya mesin melihat persis seperti diketik. Plafon `MAX_CHARS_PER_SECOND`
dan batas WPM tetap berlaku, jadi ini tak membuka celah skor.

### Input adalah target sentuhnya sendiri (percobaan pertama gagal di HP)

Versi pertama memberi input `pointer-events-none` dan menyerahkan tap ke kontainer teks yang
memanggil `focusTypingInput()`. **Di HP nyata itu tak berhasil sama sekali** — disentuh, tak
ada apa pun yang terjadi. Dua sebabnya, dan keduanya berdiri sendiri:

1. **Keyboard jadi bergantung pada satu panggilan JS.** Kalau bundle-nya gagal, masih basi di
   perangkat, atau `focusTypingInput()` tak terjangkau dari scope-nya, hasilnya diam total —
   tanpa gejala apa pun selain "tak bisa diklik".
2. **`opacity-0` membuat sebagian browser mobile menganggapnya tak terlihat** dan menolak
   membuka keyboard untuk elemen itu.

Sekarang input **dibentangkan menutupi area teks dan menerima pointer event**, jadi sentuhan
mendarat langsung di sebuah `<input>` dan browser membuka keyboard **secara native — tanpa
JavaScript sama sekali**. Ini sekaligus menghapus urusan "focus() harus di dalam gestur" milik
iOS, karena tak ada `focus()` terprogram yang terlibat di jalur utama. `focusTypingInput()`
tetap ada, tapi hanya untuk tombol petunjuk di bawah paragraf.

**Empat jebakan platform yang ditutup di markup:**

| Hal | Kenapa |
|---|---|
| Input menerima pointer event (**bukan** `pointer-events-none`) | sentuhan mendarat di `<input>` → keyboard native, tak bergantung JS |
| **Transparan** (`text-transparent`/`bg-transparent`/`caret-transparent`), bukan `opacity-0` | elemen ber-opacity 0 dianggap tak terlihat oleh sebagian browser mobile, yang lalu menolak membuka keyboard. `hidden`/`display:none` lebih buruk lagi: tak bisa difokus sama sekali |
| `text-base` (16px) | iOS Safari **memperbesar halaman** saat input ber-`font-size` < 16px difokus; zoom itu menggeser area teks dan caretnya |
| Petunjuk tap adalah `<button>`, bukan `div` ber-`@click` | iOS Safari tak selalu memicu `click` pada elemen yang tak interaktif |

Ditambah empat atribut koreksi teks (`autocomplete`/`autocorrect`/`autocapitalize`/`spellcheck`)
dimatikan: di tes mengetik, autocorrect menyunting justru hal yang sedang diukur.

**Petunjuk `typing.tap_to_type`** muncul lewat `.touch-only`, yang memakai media query
`pointer: coarse` — **bukan lebar layar**, karena jendela desktop yang sempit tetap punya
keyboard fisik. Tanpa petunjuk itu tak ada apa pun di layar yang memberi tahu bahwa teksnya
harus disentuh dulu: caret berkedip seolah sudah siap menerima ketikan.

> **Belum tertutup:** setelah restart, keyboard tertutup dan pemain harus menyentuh teksnya
> lagi (tombol restart memanggil `$el.blur()`, dan `wire:key` me-mount ulang komponen Alpine
> sehingga ref-nya dibuat baru). Petunjuk tap muncul kembali, jadi jalannya jelas — tapi ini
> tetap satu sentuhan ekstra yang idealnya hilang.

Perilaku ini **tak tertutup test otomatis**: tak ada satu pun test yang menjalankan halaman ini di
browser (lihat `TypingEngineAssetTest`). `MobileTypingInputTest` mengunci **kontrak markup &
modulnya** — keberadaan input, atribut platformnya, ketiga pengait event, dan bahwa jalurnya
lewat `handleInput()`. Bahwa keyboardnya benar-benar terbuka dan karakternya benar tetap harus
diverifikasi manual di Android dan iOS.

> Kalimat ini dulu berbunyi "proyek tak menjalankan JavaScript di test sama sekali". Itu **sudah
> tidak benar**: Vitest berjalan atas `resources/js/*.test.js` dan dijalankan CI sebelum build. Yang
> tetap benar adalah alasan sesungguhnya di sini — logika ini hidup di dalam komponen Alpine yang
> menyentuh DOM, jadi ia tak bisa diuji tanpa browser. Logika yang **bisa** dipisahkan jadi fungsi
> murni memang sebaiknya dipisahkan lalu diuji (lihat `word-mechanic.js`, `war-resume.js`).

## 3.x Stream Error (penanda error di grafik hasil)

**Service:** [`App\Services\TypingErrorInspector`](../../app/Services/TypingErrorInspector.php)

Selain `missedChars` (peta `huruf target => jumlah`, sumber heatmap), engine juga mengirim
`errorEvents`: satu entri `{second, index, actual}` per karakter target yang gagal diketik
benar. `actual` = tuts yang **benar-benar ditekan** (`missedChars` cuma tahu huruf yang
*seharusnya* diketik), atau `null` kalau karakternya **dilewati** — user menekan spasi di
tengah kata, jadi tak pernah ada keypress untuk karakter itu.

Halaman hasil memakainya untuk menandai error di grafik dan, saat titiknya diklik,
menampilkan kata tempat error terjadi. Kata **tidak dikirim** dari klien — direkonstruksi
server dari `textToType` + `index`, supaya tak ada duplikasi sumber kebenaran.

Tier **presentasi**: session-only, tak pernah masuk DB, tak menyentuh skor/XP/PB/leaderboard
— jadi bukan urusan anti-cheat. Tetap disanitasi di `saveResult` (bentuk, cast, cap 500,
`actual` dipotong 1 karakter) karena `actual` benar-benar dirender.

### Dua keputusan yang jangan "diperbaiki"

**1. Detik terakhir di-clamp, bukan dibuang.** Tick yang memicu `finish()` men-set
`isFinished` sebelum baris push `wpmHistory` dievaluasi, jadi tes `time` 30 detik cuma
menghasilkan ~29 sampel. Error di detik yang tak ter-push di-clamp ke sampel terakhir.
Membuangnya akan merusak invarian di bawah, dan detik terakhir justru tempat error
kelelahan menumpuk. Biayanya ≤1 detik pergeseran di sumbu yang resolusinya memang 1 detik.

**2. Titik grafik TIDAK akan sama dengan tile "characters" — itu disengaja.**

```
incorrectKeystrokes (tile) = keypress salah + overtype extra + spasi tengah kata
Σ missedChars (titik+heatmap) = keypress salah + karakter dilewati
```

Melenceng ke dua arah. Contoh: target `"the quick"`, ketik `"t"` lalu spasi → `h` dan `e`
ditandai terlewat (2 titik) padahal cuma **1** keystroke salah → tile 1, titik 2. Ketik
`"thex"` lalu spasi → `x` masuk `extraChars`, tak menyentuh `missedChars` → tile 1, titik 0.

Keduanya benar karena mengukur hal berbeda: **tile menghitung keystroke** (numerator akurasi,
memberi makan `AntiCheatService` — jangan disentuh); **titik & heatmap menghitung karakter
target yang gagal diproduksi** (satu-satunya definisi yang bisa menjawab "tombol mana yang
saya kesulitan" — karakter yang dilewati tetap kegagalan pada tuts itu meski tak ada tuts
ditekan). Caption `result.error_scope` di halaman hasil menyatakan definisi ini ke user.

**Yang WAJIB tetap cocok:** `Σ titik grafik === Σ missedChars === Σ tooltip heatmap`.
Berlaku secara konstruksi karena `recordError()` dipanggil dari **dalam guard yang sama**
dengan yang menaikkan `missedChars`. Kalau memindahkan panggilan itu ke luar guard, kedua
angka akan melenceng dan grafik jadi berbohong terhadap heatmap 8px di bawahnya.
Invarian ini diuji di `tests/Unit/TypingErrorInspectorTest.php`.

Pengecualian terdokumentasi: di atas cap 500 event titik akan undercount sementara heatmap
tidak; dan heatmap membuang karakter di luar 3 baris QWERTY-nya (praktis tak terjangkau —
wordlist en/id murni `a-z`; hanya `ClanWarFixedText` yang bisa membawa karakter lain).

### 3.8 Tab di layar hasil menuju `[data-result-primary]`, bukan `#restartButton`

Handler-nya dulu berbunyi `preventDefault()` **lalu**
`document.getElementById('restartButton').focus()` — tanpa guard, tanpa `?.`. Di hasil **Clan War**
aksi utamanya `<x-result-back-to-war />` yang tak ber-`id`, jadi tiap tekan Tab melempar
`TypeError` **setelah** tombolnya sudah ditelan: fokus tak ke mana-mana, tak ada satu pun elemen
yang bisa dicapai keyboard, dan console kotor tiap penekanan.

Dua hal yang mudah salah diperbaiki di sini:

1. **Urutannya, bukan cuma `?.`.** Menambal optional chaining saja akan menyisakan Tab yang
   tertelan diam-diam — bug yang sama berbaju lain. Target dicari **dulu**, dan tombolnya hanya
   ditelan kalau target itu ada.
2. **Atribut, bukan `id`.** Aksi utamanya "kembali ke war" di satu cabang dan "tes berikutnya" di
   cabang lain; sebuah id bernama `restartButton` tak bisa jujur menamai keduanya.

Karena `querySelector` mengambil yang **pertama**, dua target di satu layar berarti Tab diam-diam
memfokuskan yang salah — `ResultTabTargetTest` karena itu menguntut **tepat satu** per cabang
(dihitung sebagai atribut, bukan substring: selektor di dalam handler-nya sendiri juga memuat
kata itu).

### 3.9 `typingGame(text, warAttempt)` — argumen kedua & jam yang bukan milik client

Komponen Alpine dulu menerima satu argumen. Sekarang ada yang kedua: `warAttempt`, isi
`TypingEngine::$warLock` (`mode`, `config`, `resume`, `progress`, `remaining`, `budget`, `expired`).

Tiga konsekuensi yang mudah salah diperbaiki:

1. **Countdown membaca `timerStart`, bukan `parseInt(currentSub)`.** Pada war attempt yang di-resume
   keduanya **berbeda**, dan `resetProgress()` sudah memendekkan jamnya — membaca sub-mode di dalam
   `setInterval` akan diam-diam memulihkan panjang tes penuh satu detik kemudian.
2. **`warLock` diberi `#[Locked]`.** `remaining` menggerakkan countdown, jadi client yang bisa
   menaikkannya akan mengetik 45 detik nyata sementara server menyimpan durasi 30 detik — WPM gratis
   1,5×. Atribut ini juga menjaga `isAfkSession()`, yang melewati cek AFK setiap kali war lock ada.
3. **Posisi resume dipulihkan per KATA UTUH**, lewat
   [`resumePosition()`](../../resources/js/war-resume.js) — pola yang sama dengan `restoreProgress()`
   di `race-arena.js`. Persennya diturunkan dari kata yang sudah di-commit, jadi kata separuh memang
   tak pernah jadi bagiannya; mengarangnya akan menaruh karakter di layar yang tak pernah diketik.

Berbeda dari sisa mesin ini, aritmetika resume-nya **bisa** diuji: ia diekstrak ke modulnya sendiri
dan dikunci `war-resume.test.js` (Vitest, dijalankan CI). Itu disengaja — salah satu karakter di
sini gagal **diam-diam**, tak ada exception, cuma caret di tempat yang keliru.

> Catatan: §3.7.a dulu menyatakan "proyek tak menjalankan JavaScript di test sama sekali". Itu tak
> lagi benar sejak Vitest masuk (`resources/js/*.test.js`, dijalankan di CI sebelum build).

## 4. Integrasi dengan Fitur Lain

- **Ghost Mode** (`?ghost=...`): deep-link dari leaderboard memasang lawan ghost. Lihat
  [ghost-mode.md](ghost-mode.md).
- **Clan War** (`?war_claim=...`): sesi ini bisa "dikunci" jadi war attempt. Mode dipaksa ke
  klaim, restart/reroll diblokir. Payload session `typing_result` membawa `war.score` — rincian
  poin yang benar-benar diterima war, atau `null` kalau attempt itu tak mengisi claim mana pun.
  Lihat [clan-war.md](clan-war.md) §3.8.
  **Membuka halaman ini dengan `?war_claim=` adalah tindakan MEMULAI percobaan** — jangkar jam dan
  teksnya ditulis ke baris klaim saat itu juga, sehingga refresh/Back melanjutkan attempt yang sama
  alih-alih memulai yang baru. Semantik jam per mode ada di [clan-war.md](clan-war.md) §3.9.
- **EXP**: `saveResult` memanggil `User::addExp()`. Lihat [level-exp.md](level-exp.md).
