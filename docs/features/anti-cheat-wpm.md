# Fitur 2 — Anti-Cheat & Perhitungan WPM

**Service utama:** [`App\Services\AntiCheatService`](../../app/Services/AntiCheatService.php)
**Penjaga sesi solo:** [`App\Services\SoloSessionGuard`](../../app/Services/SoloSessionGuard.php)
**Dipakai oleh:** [`TypingEngine::saveResult()`](../../app/Livewire/TypingEngine.php) (solo),
[`MultiplayerLobby::updateRaceProgress()`](../../app/Livewire/MultiplayerLobby.php) (WPM live race),
dan [`FinalizesRace::isValidRaceResult()`](../../app/Livewire/Concerns/FinalizesRace.php) (validasi hasil akhir race)
**Audit data lama:** `php artisan typing:audit` (read-only)

---

## 1. Apa Ini

Lapisan yang memastikan angka WPM/akurasi yang tersimpan **benar-benar dihitung server** dan
**masuk akal secara manusiawi**. Ini fondasi integritas seluruh sistem skor: leaderboard, PB,
EXP, dan poin Clan War semuanya bergantung padanya.

> **Penting:** menghitung ulang di server **bukan** berarti memverifikasi. Selama input
> mentahnya (durasi & jumlah karakter) berasal dari client, hasil hitung ulang hanya
> mengulang angka palsu yang dikirim. Lihat §7 — celah ini pernah terbuka dan kini ditutup
> oleh `SoloSessionGuard`.

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
dibuang.

**Feedback ke pemain (alasan spesifik).** Pemain yang hasilnya ditolak melihat **banner berisi
alasan konkret** — akurasi (`reject_accuracy`), WPM mustahil (`reject_wpm`), char inkonsisten
(`reject_inconsistent`), atau sesi kosong (`reject_empty`) — bukan sekadar "tidak valid". Alasan
**diturunkan saat render** oleh `ReadsRoomState::getMyRejectReasonProperty()` dari data snapshot
(wpm/akurasi/progress/durasi) lewat `AntiCheatService::raceResultReasons()` — **tanpa kolom/migrasi
baru**, dan tak akan pernah menyimpang dari aturan penolakan sesungguhnya. Alasan detail **hanya
terlihat oleh pemain itu sendiri**; peserta lain hanya melihat **badge "Tidak dihitung"** (tanpa
alasan) di tabel hasil — menjaga agar tak mempermalukan.

Aturan penolakan hidup di `AntiCheatService` (bukan disalin ke trait) — **satu definisi "hasil race
invalid"** untuk semua titik finalisasi. Lihat detail alur di
[`multiplayer-race.md`](multiplayer-race.md) §3.6.

## 6. Referensi Lanjutan

Analisis mendalam soal "WPM tinggi + akurasi rendah apakah valid" dan penyelarasan solo vs
multiplayer ada di [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).

## 7. Penjaga Sesi Solo (`SoloSessionGuard`)

### 7.1 Celah yang ditutup

`saveResult()` menerima `durationMs`, `totalKeystrokes`, dan `correctKeystrokes` **dari
client**. Server memang menghitung ulang WPM dari ketiganya — tapi menghitung ulang dari
angka palsu tetap menghasilkan skor palsu. Payload berikut lolos **seluruh** sanity check
di §3 dan langsung jadi `highest_wpm`:

```
saveResult(durationMs: 60000, total: 1495, correct: 1495)
  -> netWpm = (1495/5) / 1 menit = 299 WPM   (di bawah ambang 300)
  -> reasons = []  -> TERSIMPAN, jadi rekor pribadi & masuk leaderboard
```

Properti Livewire pun tak bisa jadi acuan: `textToType` bisa ditimpa client lewat payload,
jadi panjang teks yang "diketahui server" ikut bisa dipalsukan.

### 7.2 Cara kerja penjaga

Acuan disimpan di **session server** (client tak bisa menulisinya), dicatat setiap kali
server menyerahkan teks baru lewat `generateText()`: mode, sub-mode, dan panjang teks.

Saat hasil dikirim, empat pemeriksaan berjalan:

| Pemeriksaan | Aturan |
|---|---|
| **Kecocokan sesi** | mode+sub-mode saat submit harus sama dengan yang diterbitkan; kalau tidak → tolak |
| **Durasi mode `time`** | diambil dari **sub-mode** (30 = 30 detik), payload diabaikan |
| **Plafon karakter** | melebihi batas fisik (durasi × 15 cps) atau panjang teks × 3 → **tolak** |
| **Anti-replay** | satu teks terbit = satu kiriman; sesi dihapus setelah dipakai |

`textToType` juga diberi `#[Locked]` supaya client tak bisa menukarnya dengan teks panjang.
`mainMode`/`subMode` **tak** bisa di-`Locked` (view memakai `@entangle`), jadi penukaran mode
ditangkap oleh pemeriksaan kecocokan sesi.

### 7.3 Kenapa menolak, bukan memotong diam-diam

Versi awal memotong (`clamp`) angka berlebih ke batas wajar. Itu keliru: pengirim payload
palsu tetap pulang membawa hasil yang sah. Sekarang kiriman yang melewati plafon fisik
**ditolak** seperti halnya WPM mustahil di §3.

### 7.4 Kenapa durasi mode `words`/`survival` tidak diambil dari jam server

Sempat dicoba `min(klaim, waktu server berjalan)` — dan itu **salah arah**. Waktu server
adalah batas **atas** sesi jujur, bukan batas bawah; memakainya justru memangkas durasi
(mis. 30 detik → 3 detik) sehingga WPM **melonjak** ke 557 dan hasil jujur ikut tertolak.
Karena arah yang menguntungkan pemalsu adalah **mengecilkan** durasi, dan itu sudah dijaga
plafon karakter, durasi kedua mode ini cukup diterima apa adanya (durasi lebih panjang
hanya menurunkan WPM sendiri).

### 7.5 Toleransi untuk pemain jujur

Mengetik bukan satu tombol satu karakter: salah ketik, backspace, dan mengulang kata
menambah keystroke nyata. Teks 25 kata (~130 karakter) wajar menghasilkan 150+ keystroke,
jadi plafon tekstual memakai **faktor 3× + 50** — longgar terhadap pengetik berantakan,
tetap rapat terhadap angka fabrikasi (ribuan karakter atas teks 130 karakter).

### 7.6 Data lama

`php artisan typing:audit` mendaftar hasil dengan WPM mencurigakan beserta pemilik dan
`highest_wpm`-nya. **Read-only** — tak mengubah apa pun; keputusan ada di tangan admin.

```bash
php artisan typing:audit                 # ambang default 150 WPM
php artisan typing:audit --wpm=120       # ambang lebih ketat
```

## 8. Integritas Balapan Multiplayer

Berbeda dari solo, server **sudah** memegang durasi (`race_starts_at`) dan panjang teks
(`rooms.text_to_type`), jadi WPM race memang tak pernah dipercaya dari client. Yang **tidak**
terjaga adalah `progress_percent` — dan itulah yang menentukan **waktu selesai & juara**.

### 8.1 Empat celah yang ditutup

| # | Celah | Dampak | Perbaikan |
|---|---|---|---|
| 1 | **Teleport ke 100%** | dapat `place=1` walau WPM dinolkan | ditolak lewat `exceedsRaceSpeed()` |
| 2 | **Progress mundur** | 80% → kirim 10 → tersimpan 10 | progress dipaksa **monoton naik** |
| 3 | **Spectator ikut balapan** | non-pemain dapat waktu & peringkat | hanya `ROLE_PLAYER` boleh kirim progress |
| 4 | **Hasil ditolak tetap makan podium** | juara asli tercatat runner-up **permanen** | peringkat dihitung **setelah** validasi |

### 8.2 Celah #4 yang paling merusak

Anti-cheat lama sebenarnya **sudah benar** menolak si penipu: `result_recorded=false`, XP 0,
tak masuk riwayat. Tapi peringkat diberikan dari urutan baris (`$index + 1`) **sebelum**
validitas dihitung — jadi penipu tetap menempati place 1, dan **pemain jujur yang benar-benar
menang tercatat juara 2 di riwayat permanennya**. Kerusakannya menimpa korban, bukan pelaku.

Sekarang validitas dihitung lebih dulu untuk semua peserta, lalu nomor peringkat hanya naik
untuk hasil yang lolos. Hasil yang ditolak mendapat `place = null`, dan `player_count` hanya
menghitung peserta yang sah (supaya "juara 1 dari 2" tak jadi kemenangan semu).

### 8.3 Kenapa ada plafon WPM khusus race (250, bukan 300)

Teks race ~240 karakter. Teleport ke 100% pada detik ke-10 menghasilkan **290 WPM** — masih
di bawah plafon umum 300, jadi dulu **lolos sebagai kemenangan sah**. Ambang race dipisah ke
`MAX_RACE_WPM = 250`: cukup untuk menolak teleport, masih longgar untuk pengetik elite
(rekor dunia ~210–230).

Penolakan terjadi di **jalur live** (`updateRaceProgress`), bukan hanya saat finalisasi —
karena yang menentukan juara adalah **waktu selesai**, dan itu tercatat saat progress masuk.
Menilainya 0 WPM saja tak cukup; peringkat diurutkan berdasarkan waktu, bukan kecepatan.

## 9. Integritas Poin Clan War

Poin war **tidak punya jalur input sendiri** — semuanya diturunkan dari sebuah `TypingResult`
lewat [`ClanWarScorer`](../../app/Services/ClanWarScorer.php). Jadi apa pun yang bisa
memalsukan hasil solo, otomatis memalsukan poin war. Bedanya: poin war menggerakkan **clan
power yang permanen**, bukan sekadar satu baris leaderboard.

### 9.1 Kenapa war lebih berbahaya dari solo

Dua sifat scorer membuat serangan yang "biasa saja" di solo jadi maksimal di war:

| Mode war | Rumus poin | Kenapa jadi target |
|---|---|---|
| **survival hard** | `duration_seconds / 90` | ceiling tertinggi (**150**), dan **durasi panjang = hadiah** |
| **time / words** | `net_wpm / 150` | 150 WPM **persis** memberi rasio penuh |

Di solo, durasi panjang justru **menurunkan** WPM — jadi tak ada untungnya berbohong ke arah
itu. Di survival war, kebalikannya: klaim "bertahan 9999 detik" langsung membeli poin penuh.
Asumsi "over-claim durasi itu merugikan diri sendiri" **tidak berlaku** di sini.

### 9.2 Tiga celah yang ditutup

| Serangan | Poin sebelum | Sesudah |
|---|---|---|
| survival hard, klaim durasi 9999 detik | **150 / 150** (penuh) | ditolak, klaim kosong |
| time 120, klaim 1500 char (=150 WPM) | **120 / 120** (penuh) | ditolak, klaim kosong |
| words 10, klaim 4000 char | penuh | ditolak, klaim kosong |
| *(kontrol)* sesi jujur time 30 | — | **60.84 / 80** — tetap dinilai wajar |

Penjaga kepemilikan klaim (`resolveWarClaim`) sebenarnya **sudah benar**: klaim divalidasi
ulang di server, harus milik klan si pemain, war harus `Ongoing`, dan `whereNull` mencegah
klaim ganda. Yang bocor bukan kepemilikannya, melainkan **angka hasilnya**.

### 9.3 Perbaikan: plafon karakter diukur dari waktu NYATA

Sebelumnya plafon karakter dihitung dari durasi **nominal**. Slot war `time 120` karenanya
mengizinkan ~1800 karakter — walau kiriman datang **0 detik** setelah teks terbit. Artinya
1500 karakter "diketik" dalam waktu nyata nol, tersimpan sebagai 150 WPM, poin penuh.

Sekarang jendelanya diukur dari **berapa lama server benar-benar memegang sesi itu**
(`elapsedSeconds()`), ditambah slack 30 detik untuk latensi. Sesi jujur tetap lolos; sesi yang
tak pernah benar-benar berjalan tidak.

Ditambah `claimsMoreTimeThanElapsed()` untuk `words`/`survival`: durasi yang melebihi umur
sesi adalah waktu yang tak pernah berlalu, dan itulah yang menutup celah survival.

> **Catatan kalibrasi:** slack bersifat **proporsional** (`SLACK_FRACTION = 0.35`), dibatasi
> maksimal 30 detik. Slack datar tak cukup: 30 detik adalah hampir seluruh sesi `time 30`,
> sehingga masih menyisakan ~500 karakter (≈200 WPM palsu). Dengan 0.35, pemain nyata tetap
> lolos lega — saat mereka mengirim, jam server memang sudah berjalan — sementara kiriman
> instan mentok di ~83 WPM, di bawah 150 WPM yang memberi poin war penuh.

## 10. Tindak Lanjut Penetration Test Eksternal

Empat temuan (F-01…F-04) dari pentest pihak ketiga. Ringkasan status & apa yang berubah:

| ID | Temuan | Status |
|---|---|---|
| F-01 | Dashboard monitoring tanpa autentikasi | **Ditutup** — §3.6 + Gate `access-monitoring` |
| F-02 | Manipulasi skor/WPM/leaderboard | **Ditutup** — §7, diperketat lagi di §10.1 |
| F-03 | Manipulasi poin Clan War | **Ditutup** — §9 + batas klaim per anggota |
| F-04 | Paket monitoring insecure-by-default | **Ditutup** — §3.6 + audit dependensi di CI |

### 10.1 PoC pentester lolos separuh — apa yang kurang

PoC mereka (`740 char / 30 detik = 296 WPM`) memang sudah ditolak oleh §7. Tapi saat diuji
ulang **menyapu berbagai nilai**, ternyata masih ada yang lolos:

```
500 char -> 200 WPM   LOLOS
450 char -> 180 WPM   LOLOS
400 char -> 160 WPM   LOLOS
```

Ini **bukan** temuan sepele. `WPM_SCALE = 150`, jadi **160 WPM saja sudah memberi poin Clan
War penuh** — F-03 belum benar-benar tertutup meski F-02 tampak beres. Dua perbaikan:

1. **`MAX_HUMAN_WPM` 300 → 240.** Batas 300 hanya menyaring yang mustahil dan menyisakan
   pita lebar "tak masuk akal tapi diterima". Rekor dunia berkelanjutan ~210–230.
2. **Slack proporsional** (lihat catatan kalibrasi di atas) — inilah yang benar-benar
   mengikat; menurunkan ceiling saja tak cukup.

Setelah keduanya: **tak ada nilai yang bisa dipalsukan**, dan kiriman instan mentok ~83 WPM.

### 10.2 Rate limit pengiriman hasil

`saveResult` dibatasi **10 kiriman/menit** per user (`MAX_RESULTS_PER_MINUTE`). Tes terpendek
15 detik, jadi permainan jujur tak pernah mendekati batas; ini menutup pola "sapu payload
sampai ada yang lolos" seperti yang saya lakukan sendiri saat menguji.

### 10.3 Batas klaim slot war per anggota

Satu akun sebelumnya bisa mengklaim **ke-9 slot** dan menentukan hasil war sendirian.
Sekarang dibatasi **4 slot** (`ClanWar::MAX_CLAIMS_PER_MEMBER`), jadi butuh minimal 3 anggota
berbeda. Ini menutup rekomendasi pentester "batasi kontribusi per anggota" dan sekaligus
memperkecil dampak satu akun yang diretas.

### 10.4 Privasi IP (UU PDP / GDPR)

Pentester menandai penyimpanan IP penuh sebagai risiko PII. IP kini **dianonimkan sebelum
ditulis**: oktet terakhir IPv4 dinolkan (`192.168.1.77` → `192.168.1.0`), IPv6 disisakan
prefix /48. Log tetap berguna untuk mengenali pola per jaringan, tapi tak lagi menunjuk satu
perangkat.

Implementasinya di [`AnonymizeClientIp`](../../app/Http/Middleware/AnonymizeClientIp.php),
**bukan** di tiap titik tulis. Alasannya: paket menulis IP dari **tiga** tempat di `vendor/`
(middleware, listener login/logout, dan trait `Actionable`), dan dua di antaranya memakai
`DB::table()` sehingga event Eloquent tak menangkapnya. Ketiganya membaca `request()->ip()`,
jadi menyamarkan di lapisan request menutup semua jalur tanpa menyentuh `vendor/`.

> **Urutan middleware penting:** `AnonymizeClientIp` harus terdaftar **sebelum**
> `VisitMonitoringMiddleware` di [`bootstrap/app.php`](../../bootstrap/app.php).
