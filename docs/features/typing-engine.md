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

## 4. Integrasi dengan Fitur Lain

- **Ghost Mode** (`?ghost=...`): deep-link dari leaderboard memasang lawan ghost. Lihat
  [ghost-mode.md](ghost-mode.md).
- **Clan War** (`?war_claim=...`): sesi ini bisa "dikunci" jadi war attempt. Mode dipaksa ke
  klaim, restart/reroll diblokir. Lihat [clan-war.md](clan-war.md).
- **EXP**: `saveResult` memanggil `User::addExp()`. Lihat [level-exp.md](level-exp.md).
