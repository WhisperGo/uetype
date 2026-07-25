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
Client hanya mengirim **jumlah karakter & durasi** (fakta sulit dipalsukan tanpa benar-benar
mengetik dengan konsisten), bukan hasil akhir WPM.

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

Ketikan ditangkap lewat listener global `@keydown.window` (bukan input tersembunyi) supaya
pemain bisa langsung mengetik tanpa harus klik area teks dulu. Konsekuensinya: listener ini
menyala untuk **setiap** keystroke di halaman — termasuk saat fokus ada di field lain seperti
**input chat overlay**.

**Justifikasi guard:** sebelum memanggil `handleInput()`, dicek `document.activeElement`. Kalau
fokus sedang di `INPUT` / `TEXTAREA` / elemen `contenteditable`, keystroke **dilewati** — biar
masuk ke field itu saja dan tak ikut men-trigger tes ketik di belakang. Tanpa guard ini,
mengetik pesan di overlay chat akan sekaligus memulai & mengisi paragraf typing. Lihat
[chat.md](chat.md#47-sembunyi-saat-sesi-testbalapan-aktif).

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

## 4. Integrasi dengan Fitur Lain

- **Ghost Mode** (`?ghost=...`): deep-link dari leaderboard memasang lawan ghost. Lihat
  [ghost-mode.md](ghost-mode.md).
- **Clan War** (`?war_claim=...`): sesi ini bisa "dikunci" jadi war attempt. Mode dipaksa ke
  klaim, restart/reroll diblokir. Lihat [clan-war.md](clan-war.md).
- **EXP**: `saveResult` memanggil `User::addExp()`. Lihat [level-exp.md](level-exp.md).
