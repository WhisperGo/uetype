# Fitur 2 — Anti-Cheat & Perhitungan WPM

**Service utama:** [`App\Services\AntiCheatService`](../../app/Services/AntiCheatService.php)
**Dipakai oleh:** [`TypingEngine::saveResult()`](../../app/Livewire/TypingEngine.php) (solo)
dan [`MultiplayerLobby::updateRaceProgress()`](../../app/Livewire/MultiplayerLobby.php) (multiplayer)

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

### 4.4 Service murni & stateless — dipakai bersama solo & multiplayer

**Justifikasi:** satu fungsi `check()` tanpa state/DB dipanggil dari dua tempat berbeda. Ini
menjamin **mode solo dan multiplayer memakai aturan yang persis sama** — tidak ada dua definisi
"WPM valid" yang bisa menyimpang. Fungsi murni juga mudah diuji unit.

## 5. Penerapan di Multiplayer (nuansa penting)

Di [`updateRaceProgress()`](../../app/Livewire/MultiplayerLobby.php), Net WPM diturunkan dari
**progress% × panjang teks** (karena `room_members` tak menyimpan jumlah karakter benar). Karena
progres hanya naik dari karakter benar, `totalChars = correctChars`, sehingga WPM otomatis "net"
dan tak bisa dipompa dengan ketik ngasal.

Perbedaan penting: di multiplayer **hanya sinyal mustahil** (`wpm_too_high`,
`char_count_inconsistent`) yang membuat WPM di-nol-kan. `throughput_too_low` / durasi pendek
**tidak** menolak, karena di awal race atau untuk pemain lambat, WPM kecil itu **wajar, bukan
curang**.

```php
$cheatReasons = array_intersect($wpmCheck['reasons'], ['wpm_too_high', 'char_count_inconsistent']);
$netWpm = empty($cheatReasons) ? (int) round($wpmCheck['net_wpm']) : 0;
```

## 6. Referensi Lanjutan

Analisis mendalam soal "WPM tinggi + akurasi rendah apakah valid" dan penyelarasan solo vs
multiplayer ada di [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).
