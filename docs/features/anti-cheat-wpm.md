# Fitur 2 — Anti-Cheat & Perhitungan WPM

**Service utama:** [`App\Services\AntiCheatService`](../../app/Services/AntiCheatService.php)
**Penjaga sesi solo:** [`App\Services\SoloSessionGuard`](../../app/Services/SoloSessionGuard.php)
**Analisis timing keystroke:** [`App\Services\KeystrokeAnalyzer`](../../app/Services/KeystrokeAnalyzer.php)
**Baseline per-pemain:** [`App\Services\LongitudinalBaseline`](../../app/Services/LongitudinalBaseline.php)
**Antrean review admin:** [`App\Livewire\ReviewQueue`](../../app/Livewire/ReviewQueue.php) (`/review-queue`)
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

> **Jangan pakai ambang ini untuk mendeteksi AFK di time/words.** Throughput adalah *rata-rata*,
> dan pemula hunt-and-peck 5 WPM (25 karakter/60 detik = 0,42 cps) berada **di bawah** 0,5 —
> memakainya sebagai gerbang di time/words akan membuang hasil pemain jujur, persis yang dicegah
> §4 dan test "keeps a slow time-mode session". Sesi yang ditinggalkan ditangani terpisah lewat
> **jeda terpanjang antar-keystroke** di [`TypingEngine::isAfkSession()`](../../app/Livewire/TypingEngine.php),
> karena hanya jeda yang bisa membedakan "pergi" dari "lambat". Lihat
> [typing-engine.md](typing-engine.md) §3.4.b.

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

> **Invarian ini ditegakkan CLIENT, bukan server.** Server menurunkan `correctChars` dari progres
> dan **tak pernah melihat teks yang diketik**, jadi ia tak punya cara memverifikasinya. Yang
> menegakkannya adalah **word-lock** di `handleSpace()` ([`race-arena.js`](../../resources/js/race-arena.js)):
> kata tak pernah lewat sampai diketik persis benar.
>
> Sampai 2026-07-26 invarian ini cuma **asumsi** — spasi memajukan kata apa pun yang diketik,
> sehingga mengetik ~60% tiap kata menyelesaikan race **1,5x lebih cepat** dengan akurasi 60%,
> lolos setiap gerbang di halaman ini, dan tercatat permanen sebagai kemenangan sah. Bukan celah
> payload melainkan **cacat aturan main**: client-nya jujur, aturannya yang salah. Riwayatnya di
> [multiplayer-race.md §3.2](multiplayer-race.md).
>
> Karena penegakannya di client, word-lock **bukan** batas keamanan — payload palsu tetap
> mungkin, dan yang menahannya tetap gerbang di bawah ini.

Di sini **hanya sinyal mustahil** (`isCheating()`) yang membuat WPM di-nol-kan. `throughput_too_low`
/ durasi pendek **tidak** menolak, karena di awal race atau untuk pemain lambat, WPM kecil itu
**wajar, bukan curang**:

```php
$netWpm = $antiCheat->isCheating($wpmCheck['reasons']) ? 0 : (int) round($wpmCheck['net_wpm']);
```

**Dua penjaga tambahan di jalur ini** (`updateRaceProgress`), murni *hardening* tanpa memengaruhi
pemain jujur:

- **Guard countdown.** Room berstatus `racing` sejak host menekan mulai, tapi hitung mundur 3-2-1
  masih berjalan sampai `race_starts_at`. Client jujur baru mengirim progress **setelah** countdown
  (`beginRace()`), jadi progress yang datang lebih awal ditolak — mencegah client tampered mengunci
  **waktu finish** (dan karenanya `place`) selama jendela countdown.
- **Rate-limit server-side.** Maks `MAX_PROGRESS_UPDATES_PER_SECOND = 20` per pemain per detik
  (client jujur ~8/dtk, throttle 120ms). Tiap update yang diterima menyiarkan ke **seluruh room**,
  jadi batas ini memotong *flood* dari client yang di-script; tick di atas batas **di-drop
  diam-diam** (progress monotonik, jadi tick berikutnya tetap membawa posisi terbaru — tak ada yang
  hilang). Pola `RateLimiter` sama dengan `TypingEngine::saveResult()`.

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

**Kenapa lantainya tetap 50% setelah word-lock (dan jangan diperketat).** Menaikkannya terasa
menggoda — sekarang progres 100% memang berarti seluruh teks diketik benar, jadi akurasi tinggi
"seharusnya" mengikuti. Dua alasan untuk tidak:

1. **Pemula jujur tetap bisa berakurasi rendah.** Word-lock menuntut kata **akhirnya** benar,
   bukan benar di percobaan pertama. Pemain yang salah lalu memperbaiki berkali-kali punya
   akurasi keystroke rendah dengan progres 100% yang sepenuhnya sah. Lantai yang ketat menghukum
   persis orang yang paling butuh berlatih.
2. **Akurasi datang dari client dan tak bisa diverifikasi.** Semakin ia menentukan nasib hasil,
   semakin besar insentif memalsukannya. Perannya cukup sebagai gerbang **validitas**, bukan
   penentu kemenangan.

Catatan sampingan: word-lock justru **menaikkan** akurasi pemain jujur, karena penalti lama yang
menambah keystroke *dan* kesalahan sekaligus untuk sisa kata yang dilewati sudah tak terjangkau.
Jadi risiko *false positive* di lantai ini menurun, bukan naik.

**Yang tetap dicatat (sengaja):** finisher **lambat** (WPM rendah nyata tapi benar-benar selesai)
dan pemain **progress rendah** dengan akurasi rendah yang **menyelesaikan** (upaya lemah, bukan
curang). Yang **dibuang**: **mustahil** (termasuk fast-garbage), **benar-benar kosong**, dan
**setiap DNF** — baik menyerah maupun AFK-timeout (sentinel 999s). DNF berarti tak selesai, jadi
bukan hasil ketik sungguhan; mencatatnya akan menyeret rata-rata WPM pemain. (Perubahan dari aturan
lama yang masih mencatat DNF-yang-sempat-mengetik.)

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
| **Plafon karakter** | melebihi batas fisik (durasi × 20 cps = 240 WPM) atau panjang teks × 2.5 → **tolak** |
| **Anti-replay** | satu teks terbit = satu kiriman; sesi **tab itu** dihapus setelah dipakai |

`textToType` juga diberi `#[Locked]` supaya client tak bisa menukarnya dengan teks panjang.
`mainMode`/`subMode` **tak** bisa di-`Locked` (view memakai `@entangle`), jadi penukaran mode
ditangkap oleh pemeriksaan kecocokan sesi.

**Acuan disimpan per-tab, bukan per-browser.** Setiap metode guard menerima `$tabKey` dari
`TypingEngine::$tabKey` — UUID yang diberikan server di `mount()` dan diberi `#[Locked]`.

> **Diperbaiki 2026-07-27.** Sebelumnya acuan disimpan di **satu** kunci session global, jadi
> membuka **tab kedua** menimpa catatan tab pertama. Tab pertama lalu gagal di pemeriksaan
> kecocokan sesi, dan hasil ketik yang **sepenuhnya jujur** ditolak sebagai "tidak masuk akal".
> Membiarkan tes terbuka di tab lain lalu membuka tab baru adalah perilaku browsing biasa,
> bukan serangan. Terkonfirmasi lewat test, bukan dugaan: lihat `MultiTabSessionTest`.
>
> Cakupannya spesifik — tab kedua dengan mode **berbeda** yang merusak; restart mode sama
> tetap aman. Itu sebabnya bug ini terasa acak dan tak pernah tertangkap test.
>
> **`#[Locked]` pada `tabKey` itu wajib, bukan hiasan.** `tabKey` menentukan sesi terbit mana
> yang dipakai memvalidasi kiriman; client yang bisa memilihnya sendiri berarti bisa
> mengarahkan payload palsu ke sesi yang tak pernah ia terima — membuat guard ini sekadar
> formalitas. Ada test khusus yang gagal bila atribut itu hilang.
>
> Jumlah sesi dibatasi **8 tab** (`MAX_TRACKED_SESSIONS`, eviksi tertua-dulu) karena data ini
> hidup di payload session dan client bisa membuka tab tanpa henti. Batas ini **bukan** kontrol
> keamanan — anti-replay tetap berasal dari `clear()` yang menghapus sesi per-tab saat submit.

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

> **Koreksi 2026-08-03 — kelonggarannya dulu ada dua definisi.** Kalimat "durasi lebih panjang
> hanya menurunkan WPM sendiri" benar untuk solo, tapi **tidak** untuk Clan War survival, yang
> justru menilai dari durasi. Gerbang `claimsMoreTimeThanElapsed()` memakai kelonggaran **30
> detik datar**, sementara jalur karakter (`maxPlausibleChars()`) memakai
> `min(30, durasi × 0.35)` — sehingga sesi `words/10` yang baru berjalan 15 detik masih bisa
> mengklaim 45 detik, tiga kali panjang sesungguhnya. Ironisnya komentar konstanta itu sendiri
> sudah menyatakan ia "dibatasi `SLACK_FRACTION`", yang hanya benar untuk separuh kodenya.
> Sekarang keduanya melewati `SoloSessionGuard::slackFor()` — satu definisi, dikunci
> `SoloResultTamperingTest`.

### 7.5 Toleransi untuk pemain jujur

Mengetik bukan satu tombol satu karakter: salah ketik, backspace, dan mengulang kata
menambah keystroke nyata. Teks 25 kata (~130 karakter) wajar menghasilkan 150+ keystroke,
jadi plafon tekstual memakai **faktor 2.5× + 50** — longgar terhadap pengetik berantakan,
tetap rapat terhadap angka fabrikasi (ribuan karakter atas teks 130 karakter).

> **Riwayat plafon: 15 → 13 → 20 cps.**
>
> Diperketat ke 13 cps mengikuti laporan bot-Python §7.3/§7.4, dengan alasan tertulis "13 cps ≈
> 156 WPM berkelanjutan — masih di atas run jujur mana pun". **Alasan itu salah**, dan
> kesalahannya baru terlihat dari laporan pemain, bukan dari test: seorang pemain ~185 WPM
> dengan akurasi 98% ditolak berulang kali dengan pesan *"session was rejected by server
> validation (implausible)"*. 185 WPM = 15,4 cps — jujur, dan di atas plafon.
>
> **Dinaikkan ke 20 cps (240 WPM) pada 2026-07-27**, disamakan dengan
> `AntiCheatService::MAX_RACE_WPM` supaya ada **satu** definisi "di luar batas manusia": pemain
> yang diterima di balapan tak boleh ditolak untuk kecepatan identik di solo.

#### Kenapa bug ini sulit terlihat

`CHAR_TOLERANCE` (+50 karakter) menutupi tes **pendek**, jadi pemain yang sama lolos di 10 dan
25 kata lalu ditolak di tes 30 detik. Bukan kecepatannya yang menentukan, melainkan **panjang
tesnya** — sehingga gejalanya terbaca seperti undian, bukan seperti batas kecepatan. Regression
test-nya karena itu menguji **beberapa panjang tes**, bukan satu.

#### Konsekuensi yang dibayar, dan siapa yang menanggungnya

Payload §4.1 (500 karakter / 30 detik = 200 WPM) kini **lolos plafon**. Ia tidak lolos begitu
saja: ia jatuh ke lapisan kedua (§7.5) — run ≥150 WPM tanpa riwayat, atau >40% di atas rata-rata
pemain sendiri, **ditahan untuk review**. Baris `pending` tetap tersimpan tapi tak pernah masuk
leaderboard publik dan tak menaikkan `highest_wpm`.

Jadi yang berubah bukan "bot menang", melainkan **siapa yang menangkapnya**: dulu plafon menolak
di depan, sekarang review menahan di belakang. Trade ini disengaja — menolak pemain jujur lebih
mahal daripada menahan bot satu lapis lebih dalam, karena **pemain jujur tak punya jalan banding**
sementara bot tak mendapat apa-apa dari baris yang tak pernah publik.

> **Jangan perketat lagi berdasarkan intuisi** — itu persis yang melahirkan bug di atas. Nilai
> final harus diambil dari distribusi `net_wpm` install ini sendiri (p99.9). Caveat itu sudah ada
> sejak 13 cps dan tetap belum dikerjakan; sekarang ia sudah lewat tenggat.

### 7.6 Data lama

`php artisan typing:audit` mendaftar hasil dengan WPM mencurigakan beserta pemilik dan
`highest_wpm`-nya. **Read-only** — tak mengubah apa pun; keputusan ada di tangan admin.

```bash
php artisan typing:audit                 # ambang default 150 WPM
php artisan typing:audit --wpm=120       # ambang lebih ketat
```

### 7.7 Deteksi berlapis di luar plafon karakter

Plafon karakter (§7.2) menutup *besaran* — berapa banyak yang mungkin diketik dalam sekian
detik. Tapi laporan bot-Python menunjukkan itu belum cukup: "bot sabar" yang menunggu durasi
nyata lalu memalsukan payload penuh masih bisa mendarat di WPM tinggi yang mustahil-tapi-di-bawah
plafon. Tiga lapisan tambahan menilai **bentuk** sesi, bukan hanya angka akhirnya. Semua berjalan
di `saveResult()` **hanya untuk jalur solo**.

**a. Konsistensi sebagai sinyal** (`AntiCheatService::isImpossiblyConsistent()`)

UEType sudah menghitung konsistensi WPM untuk presentasi; sekarang ia juga jadi sinyal anti-cheat.
Kurva WPM yang **rata sempurna pada kecepatan tinggi** mustahil bagi manusia — bahkan juara dunia
berfluktuasi antar kata. Gerbang: konsistensi ≥ **99%** **dan** net WPM > **120**, **dan** minimal
**10 sampel** per-detik. Ketiganya perlu bersama — konsistensi tinggi pada WPM rendah itu **wajar**
bagi pemula yang mengetik pelan-pelan dan hati-hati, jadi lantai WPM mencegah salah-tolak mereka.
Ditambahkan sebagai alasan `consistency_impossible` di `IMPOSSIBLE_REASONS`.

> **Diperbaiki 2026-07-27 — dua salah-tolak, satu akar yang sama.** Gerbang ini dulu berbunyi
> "konsistensi ≥ 97%" tanpa syarat jumlah sampel, dan menolak pemain jujur lewat **dua** jalur:
>
> 1. **Sesi pendek.** `wpmHistory` diambil sekali per detik, jadi tes `words/10` pada ~174 WPM
>    selesai dalam <4 detik dan hanya menghasilkan **3 sampel**. Pada 3 sampel, `1 - sd/mean`
>    bukan mengukur kerataan melainkan ukuran sampel: kurva manusia biasa 170/174/176 bernilai
>    **99** dan langsung ditolak. Inilah kasus yang dilaporkan pemain.
> 2. **Sesi panjang.** Bahkan dengan 120 sampel, pengetik yang menjaga tempo dalam rentang
>    **±4 WPM** bernilai 97–99 — juga ditolak. "Stabil dalam 4 WPM" itu ciri pengetik bagus,
>    bukan skrip.
>
> Perbaikannya dua bagian, dan **keduanya perlu**: `TypingEngine::MIN_CONSISTENCY_SAMPLES = 10`
> menutup jalur (1), dan `IMPOSSIBLE_CONSISTENCY` 97 → **99** menutup jalur (2). Nilai 99 dipilih
> dari bentuk kecurangan yang sebenarnya — bot ber-WPM tetap menghasilkan kurva identik yang
> bernilai **100** dan tetap ditolak (lihat test 185-for-120s).
>
> Konsistensi **tampilan** tetap dihitung dari 2 sampel (`computeConsistency()`); yang dibatasi
> hanya sinyal penolakan (`consistencyForAntiCheat()`). Statistik berisik boleh ditampilkan,
> tidak boleh menuduh.
>
> Catatan yang sama seperti `MAX_CHARS_PER_SECOND`: setel ulang dari distribusi konsistensi
> install ini sendiri, **bukan** dari intuisi — intuisi yang menaruhnya di 97.

**b. Analisis timing keystroke** (`KeystrokeAnalyzer`)

Klien kini mengirim **array interval antar-keystroke** (`keyIntervals`) — sampel acak dibatasi
(reservoir sampling, maks 300) supaya payload kecil dan tak bisa "diketik jujur di awal saja".
`KeystrokeAnalyzer::analyze()` memeriksa distribusinya:

| Sinyal | Aturan | Kenapa |
|---|---|---|
| `keystroke_timing_uniform` | koefisien variasi < **0.15** | manusia tak pernah seragam |
| `keystroke_timing_impossible` | > **30%** interval di bawah **40 ms** | batas fisik jari |
| `keystroke_timing_identical` | satu nilai muncul > **60%** | tanda `time.sleep(k)` tetap |

Butuh minimal **20 sampel**; di bawah itu `has_data:false` dan **tak pernah menolak** —
*fail-safe* wajib agar bundle klien lama (yang tak mengirim `keyIntervals`) tak menjatuhkan
pemain jujur saat deploy.

> **Rollout log-only.** Saat ini `KEYSTROKE_TIMING_ENFORCED = false` di `TypingEngine` — sinyal
> yang menyala **dicatat, belum menolak**. Ini rilis pertama yang aman: kumpulkan data dulu,
> pastikan tak ada pemain jujur tertangkap, baru naikkan ke penolakan. Detektornya sudah hidup.

**c. Baseline longitudinal per-pemain** (`LongitudinalBaseline`)

Keunggulan struktural UEType atas situs typing murni: **riwayat pemain** sudah tersimpan di
`typing_results`. `reviewReasonFor()` membandingkan hasil baru dengan riwayat pemain di mode/config
yang sama:

- `longitudinal_spike` — lonjakan > **40%** di atas rata-rata (jendela **20** sesi terakhir, minimal **5** riwayat)
- `no_history_high` — pemain tanpa riwayat langsung mencetak ≥ **150 WPM**

**Ini tidak menolak** — pemain memang bisa membaik, dan menghukum peningkatan asli jauh lebih
merusak kepercayaan daripada meloloskan satu cheater. Ia hanya **menandai untuk review manusia**.
Hanya riwayat `clear`/`approved` yang jadi pembanding (hasil pending/rejected tak mencemari baseline).

### 7.7d Aturan durasi & plafon khusus Clan War

Sebuah war attempt bisa **sah-sah saja melintasi beberapa mount** (refresh, tombol Back). Itu
menabrak dua asumsi kelas ini secara langsung, dan keduanya harus ditangani ke arah yang berbeda.

**Plafon karakter diukur dari JANGKAR attempt, bukan dari mount ini.** `SoloSessionGuard` mengukur
elapsed **per tab**, dan refresh mencetak `tabKey` baru — jadi pemain yang resume sambil membawa
ratusan karakter jujur akan dinilai terhadap jam yang baru mulai, lalu **ditolak**. `maxPlausibleChars()`
karena itu menerima `$elapsedOverride` dan `$windowSeconds` opsional; jalur solo tak mengirim keduanya
dan perilakunya **byte-identical**. Elapsed berjangkar hanya bisa **naik**, dibatasi window sebesar
durasi nominal + grace, jadi ini **lebih ketat** sepanjang umur attempt — ia cuma berhenti menghukum
pemain yang jujur.

> **Kenapa ini BUKAN pengulangan kesalahan §7.4.** Yang terbukti salah di sana adalah
> `min(klaim, elapsed server)`: elapsed server adalah batas **atas** sesi jujur, jadi mengambil
> `min` memangkas 30 detik jadi 3 dan melambungkan WPM ke 557. Aturan durasi `words` di war adalah
> `max(klaim, elapsed berjangkar − 30)` — ia hanya bisa **memperpanjang**, dan durasi yang lebih
> panjang cuma menurunkan WPM. Arahnya berlawanan, justru karena alasan yang sama.

**Survival adalah inversinya, dan diperlakukan terpisah.** Poinnya `duration_seconds / 90`, jadi jam
berjangkar yang kontinu malah **menghadiahi** refresh, sementara jam per-sesi mengizinkan retry tanpa
batas. Survival karena itu **tidak resume**: ia restart di dalam anggaran wall-clock yang menyusut
(`wasted + credited ≤ 120 detik`). Pemotongannya diterapkan **hanya saat menilai klaim war**, tak
pernah ke `TypingResult` — memendekkan durasi **menaikkan** WPM, dan `check()` membaca kolom itu,
jadi hasil jujur bisa ikut ditolak sebagai mustahil.

Konstanta baru (`ClanWarAttempt::GRACE_SECONDS` 30, `COUNTDOWN_GRACE_SECONDS` 10,
`SURVIVAL_BUDGET_SECONDS` 120, `STALE_MINUTES` 15) tunduk pada aturan §10.2b yang sama: **setel ulang
dari log penolakan, bukan dari intuisi.**

Detail per mode dan alasannya ada di [`clan-war.md`](clan-war.md) §3.9.

### 7.7e Lantai fisik Survival (`SurvivalPlausibility`) — ditambahkan 2026-08-03

Tiga lapisan di atas semuanya menilai **kecepatan**. Survival tidak dinilai dari kecepatan
melainkan dari **durasi**, dan durasinya adalah satu-satunya besaran berskor yang tak pernah bisa
dihitung ulang server: simulasi staminanya berjalan **sepenuhnya di browser**
(`resources/js/typing-game.js`). Server hanya diberi tahu berapa lama pemain bertahan, tak pernah
menyaksikannya bertahan hidup — jadi klien yang menghapus kondisi matinya sendiri bebas melaporkan
durasi apa pun.

Di solo itu nyaris tak berarti (durasi lebih panjang justru menurunkan WPM). Di **Clan War** ia
hadiah utamanya: `survival/hard` berplafon poin tertinggi di grid (**150**) dan poinnya naik
seiring durasi sampai 90 detik. Poin termurah di seluruh permainan duduk persis di balik satu
angka yang tak bisa diperiksa server.

**Yang dihitung bukan simulasi, melainkan LANTAI FISIK.** Stamina terkuras oleh waktu dan terisi
per karakter benar, jadi bertahan lebih lama menuntut mengetik lebih banyak — dan batas bawahnya
bisa dihitung, apa pun yang terjadi di antaranya:

```
minChars = (0.35 × ∫drain − staminaAwal) / refillPerKarakter
```

Setiap suku sengaja condong ke pihak pemain: drain selama grace diabaikan **sepenuhnya**, sisanya
diambil pada laju terbaik yang mungkin diberikan burst shield (`SHIELD_DRAIN_FACTOR` 0.35 —
padahal menahan shield terus-menerus menuntut ~7,1 karakter/detik, sekitar 85 WPM berkelanjutan),
stamina awal dianggap habis terpakai, dan **cap** stamina diabaikan meski di permainan nyata ia
membuang refill di atas `sMax` sehingga kebutuhan sebenarnya lebih tinggi. Ditambah toleransi 10%
lagi sebelum apa pun ditandai.

**Ditahan, bukan ditolak** — sama seperti §7.7c. Barisnya tetap tersimpan dan terlihat di profil
pemain sendiri, hanya tak ikut papan publik sampai ada manusia yang meloloskannya
(`review_reason = 'survival_impossible'`). Lantai fisik yang salah tembak pada satu pemain jujur
lebih mahal daripada poin yang ia jaga.

> **Preset stamina disalin ke PHP, dan salinan bisa hanyut.** Alternatifnya lebih buruk: klien
> butuh angka itu tiap frame, server butuh untuk membatasi klaim, dan mengirimkannya dari klien
> berarti terdakwa yang menentukan hukumnya. Salinannya dipatok `SurvivalPlausibilityTest`, yang
> membaca berkas JS dan membandingkannya — berubah di sisi mana pun, suite yang merah, bukan
> lantai yang diam-diam melonggar.

### 7.8 Antrean review admin (`review_status`)

Sinyal §7.7c yang menandai membuat hasil disimpan sebagai **`pending`**, bukan ditolak. Kolom
`review_status` di `typing_results` punya empat nilai:

| Status | Arti | Boleh jadi angka publik? |
|---|---|---|
| `clear` | lolos otomatis (mayoritas hasil) | ✅ |
| `pending` | ditahan untuk ditinjau manusia | ❌ (sampai di-approve) |
| `approved` | admin menyetujui | ✅ |
| `rejected` | admin menolak | ❌ selamanya |

**Kolomnya menjaga tiga angka, bukan cuma leaderboard.** Gerbangnya hidup di satu tempat —
`TypingResult::scopeTrustworthy()` — dan dipakai papan, kedua helper rekor per-mode
(`bestNetWpmFor()` / `bestSurvivalDurationFor()`), serta `User::recordPersonalBest()`.

Dulu hanya papan dan `recordPersonalBest()` yang menanyakannya, dan itu meninggalkan celah yang
tak kasat mata: rekor **per-mode** diturunkan dengan `MAX(net_wpm)` polos. Sebuah run 200 WPM yang
ditahan memang tak masuk papan dan tak menaikkan `highest_wpm`, tapi ia tetap menjadi
`previousBest` di layar hasil dan pace yang dipakai Ghost — jadi angka yang ditahan tetap
dipertunjukkan, dan run jujur pemain berikutnya tak pernah lagi ditandai PB. Status `rejected`
sama saja: `ReviewQueue::reject()` memindahkan kolom dan **membiarkan barisnya**, jadi tanpa
gerbang ini angka yang sudah dibuang admin ikut dihitung selamanya.

Survival adalah sisi yang paling perlu: metrik papannya `duration_seconds`, dan stamina
disimulasikan di **klien** — jadi justru angka yang tak bisa dihitung ulang server itulah yang
dulu kembali sebagai rekor meski `SurvivalPlausibility` menahannya. Dikunci
`RecordExcludesFlaggedTest`.

Admin membuka **`/review-queue`** ([`ReviewQueue`](../../app/Livewire/ReviewQueue.php)) untuk
menilai tiap hasil pending (pemain, WPM, akurasi, alasan flag) lalu **Approve** (hasil masuk
leaderboard, PB pemain ikut naik bila mengalahkan rekor) atau **Reject** (tetap di luar). Ini
satu-satunya lapisan yang **tak bisa di-*pace*** — tak ada angka tetap untuk dibidik di bawahnya,
karena manusia yang memutuskan.

Akses admin-saja: route memikul `EnsureUserIsAdmin` sebagai **satu-satunya** gerbang (guest &
non-admin sama-sama dapat **404**, tak membocorkan keberadaan panel), dan tiap aksi memeriksa
ulang Gate `access-monitoring` di server. Filter leaderboard di
[`leaderboard.blade.php`](../../resources/views/livewire/leaderboard.blade.php) hanya menerima
`clear` + `approved` — diterapkan di **satu sumber `$scoped`** sehingga papan dan rank selalu setuju.

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

### 8.3 Kenapa ada plafon WPM khusus race (240, bukan 300)

Teks race ~240 karakter. Teleport ke 100% pada detik ke-10 menghasilkan **~288 WPM** — masih
di bawah plafon umum 300, jadi dulu **lolos sebagai kemenangan sah**. Ambang race dipisah ke
`MAX_RACE_WPM = 240`. Kuncinya: juara diperingkat berdasarkan **waktu selesai**, jadi client yang
di-tamper cukup memacu progress palsu tepat **di bawah** plafon untuk **selalu mengalahkan** pemain
jujur — menurunkan plafon mempersempit jendela itu. 240 dipilih karena masih sedikit di atas rekor
manusia berkelanjutan (~210–230), jadi run elite sungguhan tetap lolos. (Plafon 250 yang lebih
longgar masih menerima pemalsuan ~245 WPM yang tak bisa dibedakan dari kemenangan sah.)

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
| F-02 | Manipulasi skor/WPM/leaderboard | **Ditutup** — §7, diperketat di §10.1, dilapisi §7.7–§7.8 & gerbang §11 |
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

`saveResult` dibatasi **30 kiriman/menit** per user (`MAX_RESULTS_PER_MINUTE`). Ini menutup pola
"sapu payload sampai ada yang lolos" seperti yang saya lakukan sendiri saat menguji.

> **Dinaikkan 10 → 30 pada 2026-07-27.** Angka lama disertai alasan "tes terpendek 15 detik, jadi
> permainan jujur tak pernah mendekati batas". **Alasan itu salah**: tes terpendek adalah
> `words/10`, yang diselesaikan pengetik cepat dalam **<4 detik**. Dengan siklus latihan realistis
> (~4 detik mengetik + `tab`/`enter` + membaca teks berikutnya ≈ 5,4 detik) itu **~11 run/menit** —
> melewati batas 10. Pemain yang berlatih intensif ditolak pada run jujur ke-11, **dengan pesan
> yang menuduhnya curang**. Perhatikan polanya: sama seperti `MAX_CHARS_PER_SECOND` dan
> `IMPOSSIBLE_CONSISTENCY`, konstanta ini dikalibrasi dari asumsi tentang perilaku pemain, bukan
> dari pengukuran.

### 10.2b Log alasan penolakan solo

Enam gerbang di `saveResult()` berakhir di `rejectSubmission()`, dan dulu **semuanya** memakai satu
pesan yang sama tanpa log. Akibatnya jalur ini tak bisa didiagnosis: pemain ~174 WPM yang ditolak
gerbang konsistensi tak bisa dibedakan dari rate limit atau sesi kedaluwarsa, dan **setiap
penyetelan konstanta jadi tebakan**.

Sekarang `rejectSubmission(string $reason, array $context)` mencatat `Log::warning` dengan alasan
mesin dan angka yang menentukan gerbang itu:

| `reason` | Kapan | Konteks yang dicatat |
|---|---|---|
| `rate_limited` | melewati `MAX_RESULTS_PER_MINUTE` | — |
| `session_mismatch` | sesi hilang / mode tak cocok | `had_session`, mode terbit |
| `duration_over_elapsed` | klaim waktu > jam server | `claimed_seconds`, `elapsed_seconds` |
| `char_ceiling_exceeded` | melewati plafon karakter | `total_keystrokes`, `max_chars`, durasi |
| `anti_cheat` | vonis `rejectsSoloResult()` | `reasons`, `net_wpm`, `consistency`, `wpm_samples` |

Pesan ke pemain juga dipisah (`REJECTION_MESSAGES`): hanya manipulasi sungguhan yang diberi tahu
hasilnya "tidak masuk akal". Rate limit dan sesi kedaluwarsa **bukan kecurangan**, dan menuduh
pemain jujur atas hal itu adalah bug tersendiri.

**Aturannya ke depan:** jangan tambah jalur penolakan tanpa `reason`, dan **setel ulang konstanta
apa pun di dokumen ini dari log tersebut**, bukan dari intuisi.

### 10.3 Batas klaim slot war per anggota

Satu akun sebelumnya bisa mengklaim **ke-9 slot** dan menentukan hasil war sendirian.
Sekarang dibatasi lewat `ClanWarModeCatalog::claimCapFor()`, dengan **lantai 4 slot**
(`MIN_CLAIMS_PER_MEMBER`). Ini menutup rekomendasi pentester "batasi kontribusi per anggota" dan
sekaligus memperkecil dampak satu akun yang diretas.

> **Diperbaiki 2026-08-02 — angka 4 yang datar punya biaya yang tak pernah dicatat.**
>
> Kalimat di dokumen ini dulu berbunyi "jadi butuh minimal 3 anggota berbeda". Itu **benar secara
> aritmetika** (9 ÷ 4) tapi **tak pernah ditegakkan di kode maupun diberitahukan ke pemain**: clan
> 2 orang tetap boleh menerima war, mengklaim 8 slot, lalu menatap slot ke-9 yang tak bisa diambil
> siapa pun. Karena early finish menuntut 9/9 di kedua sisi, clan **lawan** ikut terkunci 3 hari.
>
> Capnya kini `max(4, ceil(9 / anggota aktif))` dan **di-snapshot saat war diterima**, jadi
> menendang anggota di tengah war tak bisa menaikkannya. Clan 3+ tetap 4 — proteksi F-03 utuh persis
> di tempat ia berarti. Lihat [`clan-war.md`](clan-war.md) §3.10.

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

## 11. Gerbang Kelayakan Leaderboard (`minTimeTyping`)

Semua lapisan di atas menilai **apakah sebuah hasil asli**. Gerbang ini bekerja pada dimensi
berbeda — **siapa yang boleh masuk papan** — dan berjalan **sebelum** hasil dinilai. Idenya dari
`minTimeTyping` Monkeytype: dua analisis independen (laporan bot-Python §7.1 dan analisis
arsitektur Monkeytype) sama-sama menempatkannya sebagai pertahanan berrasio efektivitas tertinggi.

### 11.1 Masalah yang ditutup

UEType punya banyak papan leaderboard yang, dengan basis pemain kecil, sebagian besar **kosong**.
Papan kosong berarti **satu hasil apa pun langsung peringkat 1** — penyerang tak perlu WPM ekstrem
sama sekali. Selama pintu itu terbuka, memperketat plafon WPM (§7, §10) **tidak menyentuh serangan
ini**: peringkat 1 tersedia gratis untuk akun sekali-pakai.

### 11.2 Aturan

Hasil hanya muncul di leaderboard bila pemiliknya sudah mengumpulkan
**`TypingResult::LEADERBOARD_MIN_TYPING_SECONDS` (= 1800 detik / 30 menit)** waktu mengetik,
**diakumulasi lintas semua mode**. Difilter di satu sumber `$scoped`
([`leaderboard.blade.php`](../../resources/views/livewire/leaderboard.blade.php)) lewat subquery
`HAVING SUM(duration_seconds) >= ambang`, jadi **papan dan rank selalu setuju** — tanpa migrasi
(`duration_seconds` sudah ada di tiap hasil), tanpa perubahan klien.

Keunggulannya unik: gerbang ini **tidak bisa di-*pace*** (tak ada angka untuk dibidik dari bawah),
**tidak bisa dipalsukan dengan timing lebih baik**, dan **tak bergantung data historis** lain.

### 11.3 Keputusan desain

- **Hanya solo.** Waktu diakumulasi dari `typing_results`, dan **hanya jalur solo**
  (`TypingEngine::saveResult()`) yang menulis tabel itu. Waktu di race multiplayer **tidak**
  menyumbang, dan gerbang ini **tidak** memblokir partisipasi multiplayer sama sekali — hanya
  kemunculan di papan solo global yang tertahan.
- **Akumulasi lintas-mode, bukan per-mode.** Pemain aktif di `time` tak perlu "magang" ulang 30
  menit di `words`. Total effort yang dihitung.
- **Rekor tak hilang.** Hasil pemain belum-eligible tetap tersimpan dan tampil di profil/PB; ia
  hanya bergabung ke papan global begitu ambang tercapai (diuji eksplisit).
- **Pesan yang jelas.** Pemain yang **sudah mengetik tapi belum cukup** melihat "ketik X menit
  lagi untuk masuk papan" (`leaderboard.eligibility_pending`), bukan "Unranked" telanjang yang
  seolah rekornya lenyap. Yang **belum pernah main** tetap "Unranked" biasa.

### 11.4 Kalibrasi

30 menit adalah interim **lembut** untuk basis pemain muda yang saat ini masih sedikit yang
menembusnya. Naikkan lewat satu konstanta (`LEADERBOARD_MIN_TYPING_SECONDS`) seiring basis
tumbuh (Monkeytype memakai 2 jam). Angka final sebaiknya dari distribusi data nyata
(`SUM(duration_seconds)` per pemain), bukan tebakan.

Test: [`LeaderboardEligibilityTest`](../../tests/Feature/LeaderboardEligibilityTest.php).

### 11.5 Mendemokan gerbang ini secara lokal

[`LeaderboardDemoSeeder`](../../database/seeders/LeaderboardDemoSeeder.php) membuat satu akun
(`leaderboard@uetype.test` / **LeaderboardPro**) yang total waktu ketiknya **melewati** ambang
30 menit, jadi ia benar-benar tampil di papan — berbeda dari `DummyUserSeeder` yang sesi-sesinya
pendek dan **tertahan** ("ketik lebih dulu"). Berguna untuk memperlihatkan kedua sisi gerbang:

```bash
php artisan db:seed --class=LeaderboardDemoSeeder   # akun eligible (di papan)
php artisan db:seed --class=DummyUserSeeder         # akun belum-eligible (tertahan)
```

Login lokal lewat `/dev-login?email=leaderboard@uetype.test` (route dev hanya aktif saat
`APP_ENV=local`; lihat [auth.md](auth.md)). Seeder ini **untuk demo/dev lokal saja** — jangan
dijalankan di production.
