# Fitur 2 — Anti-Cheat & Perhitungan WPM

**Service utama:** [`App\Services\AntiCheatService`](../../app/Services/AntiCheatService.php)
**Dipakai oleh:** [`TypingEngine::saveResult()`](../../app/Livewire/TypingEngine.php) (solo),
[`MultiplayerLobby::updateRaceProgress()`](../../app/Livewire/MultiplayerLobby.php) (WPM live race),
dan [`FinalizesRace::isValidRaceResult()`](../../app/Livewire/Concerns/FinalizesRace.php) (validasi hasil akhir race)

---

## 1. Apa Ini

Lapisan yang memastikan angka WPM/akurasi yang tersimpan **benar-benar dihitung server** dan
**masuk akal secara manusiawi**. Ini fondasi integritas seluruh sistem skor: leaderboard, PB,
EXP, dan poin Clan War semuanya bergantung padanya.

## 2. Rumus Perhitungan

Standar industri mengetik: **1 kata = 5 karakter**.

```
netWpm = (correctChars / 5) / durationMinutes
rawWpm = (totalChars   / 5) / durationMinutes
accuracy = correctChars / totalChars × 100
```

- **Net WPM** — hanya karakter benar. **Inilah skor resmi** (PB, leaderboard).
- **Raw WPM** — seluruh karakter termasuk yang salah. Ditampilkan sebagai info tambahan.
- **Accuracy** — proporsi karakter benar.

## 3. Aturan Penolakan (sanity check)

Sesi ditolak (`valid = false`) kalau memenuhi salah satu:

| Alasan | Ambang | Kenapa |
|--------|--------|--------|
| `duration_too_short` | < 1 detik | sesi terlalu pendek untuk bermakna |
| `wpm_too_high` | net/raw > **300 WPM** | rekor dunia ~210–230; di atas ini hampir pasti palsu |
| `accuracy_impossible` | > 100% | mustahil secara matematis |
| `char_count_inconsistent` | benar > total | data korup/dipalsukan |
| `no_input` | total ≤ 0 | bukan sesi nyata |
| `throughput_too_low` | < 0.5 karakter/detik | durasi besar + input sepele = idle/palsu |
| `accuracy_progress_inconsistent` | progress ≥ 50% **dan** akurasi < 50% | **khusus race** — progress hanya naik dari char benar, jadi hampir-selesai + akurasi sangat rendah itu mustahil (fast-garbage). Lihat §5.2 |

## 4. Keputusan Desain & Justifikasi

### 4.1 Server menghitung ulang, tidak percaya WPM dari client

**Justifikasi:** angka WPM yang dikirim browser bisa dipalsukan dengan mudah (edit payload).
Yang **sulit** dipalsukan tanpa benar-benar mengetik adalah **jumlah karakter benar dan durasi**.
Dengan hanya menerima dua fakta itu lalu menghitung sendiri, celah "kirim WPM=999" tertutup.

### 4.2 Batas WPM manusiawi 300, bukan angka lebih ketat

**Justifikasi:** rekor dunia berada di kisaran 210–230 WPM. Ambang 300 memberi *headroom* agar
pengetik sangat cepat yang sah tidak salah-tolak (*false positive*), sambil tetap menangkap nilai
yang jelas mustahil. Ini pilihan konservatif: **lebih baik longgar tapi tak pernah menghukum
pemain jujur**.

### 4.3 Throughput minimum (0.5 cps) khusus penting untuk Survival

**Justifikasi:** di Survival, `duration_seconds` **adalah** metrik leaderboard. Tanpa cek
throughput, seseorang bisa mengetik satu karakter lalu diam lama untuk memompa durasi. Cek
"minimal 0.5 karakter/detik" memastikan durasi yang diklaim benar-benar diisi aktivitas mengetik.
Hanya dicek di atas durasi minimum, karena sesi pendek wajar punya rasio lebih bising.

### 4.4 Service murni & stateless — satu `check()`, tiga gerbang keputusan

**Justifikasi:** satu fungsi `check()` tanpa state/DB dipanggil dari tiga tempat berbeda. Ini
menjamin **solo dan multiplayer memakai aturan yang persis sama** — tidak ada dua definisi "WPM
valid" yang bisa menyimpang. Fungsi murni juga mudah diuji unit.

`check()` hanya mengembalikan daftar `reasons`; **keputusan terima/tolak** dipisah ke tiga
helper agar tiap pemanggil memakai kebijakan yang tepat untuk konteksnya, tanpa menyalin daftar
alasan:

| Helper | Dipakai | Menolak apa |
|--------|---------|-------------|
| `isCheating()` | live WPM race | hanya sinyal **mustahil** (`wpm_too_high`, `char_count_inconsistent`, `accuracy_impossible`) |
| `rejectsSoloResult()` | `TypingEngine::saveResult()` | mustahil **+ sesi kosong** (`no_input`/`duration_too_short`) + `throughput_too_low` **khusus Survival** |
| `rejectsRaceResult()` | `FinalizesRace::isValidRaceResult()` | mustahil (termasuk `accuracy_progress_inconsistent` dari `raceResultReasons()`) **+ sesi kosong** (`no_input`) |

## 5. Penerapan di Multiplayer (nuansa penting)

Multiplayer memakai `AntiCheatService` di **dua titik** yang berbeda tujuan:

### 5.1 WPM live saat race — `updateRaceProgress()`

Net WPM diturunkan dari **progress% × panjang teks** (karena `room_members` tak menyimpan jumlah
karakter benar). Karena progres hanya naik dari karakter benar, `totalChars = correctChars`,
sehingga WPM otomatis "net" dan tak bisa dipompa dengan ketik ngasal.

Di sini **hanya sinyal mustahil** (`isCheating()`) yang membuat WPM di-nol-kan. `throughput_too_low`
/ durasi pendek **tidak** menolak, karena di awal race atau untuk pemain lambat, WPM kecil itu
**wajar, bukan curang**:

```php
$netWpm = $antiCheat->isCheating($wpmCheck['reasons']) ? 0 : (int) round($wpmCheck['net_wpm']);
```

### 5.2 Validasi hasil akhir — `FinalizesRace::isValidRaceResult()`

Saat race difinalisasi, tiap hasil pemain divalidasi lewat `raceResultReasons()` →
`rejectsRaceResult()`. Hasil yang ditolak **tidak ditulis ke `multiplayer_match_history`, tidak
dapat EXP**, dan ditandai `result_recorded = false` (agar rata-rata WPM/stat pemain tak tercemar).
Ini **sejajar dengan solo** (`saveResult()`), termasuk menolak **sesi kosong** (`no_input`).

**Cek konsistensi progress vs akurasi (khusus race).** Ini nuansa terpenting: di race, WPM
diturunkan server dari **progress%** — server **tidak pernah melihat** pisahan char benar/salah
mentah, jadi ia **tak bisa menghitung ulang akurasi** dari karakter. Akurasi datang dari client.
Satu-satunya silang-cek yang server **bisa** lakukan adalah terhadap progress: menyelesaikan (atau
hampir menyelesaikan) teks menuntut mengetik yang **sebagian besar benar**, jadi **progress tinggi
berdampingan dengan akurasi sangat rendah itu kontradiktif** dan menandai manipulasi — inilah
serangan *fast-garbage* (mis. **200 WPM dengan akurasi 3%**). Ambangnya: progress ≥ 50% **dan**
akurasi < 50%. Di bawah 50% progress, akurasi rendah itu upaya lemah yang **wajar**, jadi lantai
akurasi tak diberlakukan.

**Yang tetap dicatat (sengaja):** finisher **lambat** (WPM rendah nyata), **DNF yang benar-benar
mengetik** (sentinel 999s), dan pemain **progress rendah** dengan akurasi rendah (upaya lemah) —
semua hasil sah. Hanya yang **mustahil** (termasuk fast-garbage) dan **benar-benar kosong** yang
dibuang. Pemain yang ditolak melihat **badge "Tidak dihitung"** di tabel hasil (terlihat semua
peserta) plus banner penjelasan untuk dirinya sendiri.

Aturan penolakan hidup di `AntiCheatService` (bukan disalin ke trait) — **satu definisi "hasil race
invalid"** untuk semua titik finalisasi. Lihat detail alur di
[`multiplayer-race.md`](multiplayer-race.md) §3.6.

## 6. Referensi Lanjutan

Analisis mendalam soal "WPM tinggi + akurasi rendah apakah valid" dan penyelarasan solo vs
multiplayer ada di [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).
