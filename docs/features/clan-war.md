# Fitur 10 — Clan War & Elo Power

**Komponen:** [`App\Livewire\ClanWar`](../../app/Livewire/ClanWar.php) — route `/clan-war`
**Service:** [`ClanWarResolver`](../../app/Services/ClanWarResolver.php),
[`ClanWarScorer`](../../app/Services/ClanWarScorer.php),
[`ClanWarModeCatalog`](../../app/Services/ClanWarModeCatalog.php),
[`EloCalculator`](../../app/Services/EloCalculator.php)
**Model:** [`ClanWar`](../../app/Models/ClanWar.php),
[`ClanWarModeClaim`](../../app/Models/ClanWarModeClaim.php),
[`ClanWarFixedText`](../../app/Models/ClanWarFixedText.php)

---

## 1. Apa Ini

Kompetisi antar dua clan. Alur:
1. Leader **menantang** clan lain → war `Pending` (harus di-accept dalam **1 jam**).
2. Lawan **accept** → war `Ongoing` selama **3 hari**.
3. Kedua clan berlomba menyelesaikan **9 mode wajib** (grid). Tiap member **mengklaim** satu mode,
   mengerjakannya di typing engine (mode terkunci), hasilnya jadi **poin**.
4. War **selesai** (lewat 3 hari, atau kedua clan tuntas 9 mode). Poin dibandingkan, **power**
   kedua clan di-update lewat **Elo**.

## 2. 9 Mode Wajib & Ceiling Poin

Katalog di [`ClanWarModeCatalog`](../../app/Services/ClanWarModeCatalog.php): 4 Words + 4 Time +
1 Survival Hard, dengan **ceiling** (poin maksimal) naik seiring kesulitan (Survival Hard tertinggi,
150).

```
poin = ceiling × performanceRatio × accuracyMultiplier
  performanceRatio (Time/Words) = min(1.0, net_wpm / 150)
  performanceRatio (Survival)   = min(1.0, duration_seconds / 90)
  accuracyMultiplier            = 0.5 + 0.5 × (accuracy/100)   // sama dgn User::addExp
```

## 3. Keputusan Desain & Justifikasi

### 3.1 War attempt mengunci mode (`?war_claim=<id>`) di typing engine

Saat mengerjakan klaim, `TypingEngine` memaksa mode ke klaim, **memblokir ganti mode & reroll teks**.

**Justifikasi:** menutup celah "refresh sampai dapat kata pendek" atau pindah ke mode termudah.
Sekali klaim = satu kesempatan pada mode yang ditentukan. Semua ini gerbang **server-side**, bukan
sekadar menyembunyikan tombol.

> **Diperbaiki 2026-08-02 — kuncinya benar, tapi hanya di satu arah.**
>
> `restart()` dan `setContentLang()` memang diblokir, tapi aturan "satu klaim, satu percobaan"
> ditegakkan **hanya di satu tempat, dan tempat itu akhir sesi**: update bersyarat
> `whereNull('typing_result_id')` di `attachToWarClaim()`. Di antara klaim dan submit, baris klaim
> **tak menyimpan state apa pun** — jadi klaim adalah tiket yang bisa diputar ulang:
>
> | Jalur | Mekanisme |
> |---|---|
> | **Refresh (F5)** | full page load → `mount()` → `tabKey` UUID baru → `generateText()` → sesi guard baru dengan `started_at` baru |
> | **Tombol Back** | jalur kode yang sama — guard bfcache di `typing-engine.blade.php` memaksa `location.reload()`, jadi Back **selalu** mount penuh |
> | **Tombol Play di grid** | `<a wire:navigate>` biasa, bisa diklik tanpa batas selama klaim belum terisi |
> | **Cancel + klaim ulang** | `cancelClaim()` hanya menolak klaim yang **sudah submit** |
>
> Dua hal memperparahnya, ke arah berlawanan. Di **Words** teksnya beku, jadi tiap pengulangan
> adalah latihan pada soal yang persis akan dinilai. Di **time/survival** justru tak ada teks beku
> sama sekali, jadi tiap mount **me-reroll teks acak** — reroll yang sama yang sudah dilarang
> `restart()`.
>
> Sekarang klaim jadi **attempt berjangkar** ([`ClanWarAttempt`](../../app/Services/ClanWarAttempt.php)),
> dan teks dibekukan ke baris klaim untuk **ketiga** mode. Detail semantik jamnya di §3.9.

`ClanWarFixedText` tetap ada, tapi perannya turun jadi **sumber pembekuan** — dibaca sekali saat
attempt dibuka, bukan tiap mount. Efek samping yang bagus: mengedit fixed text di tengah war tak
lagi bisa mengubah soal attempt yang sedang berjalan.

### 3.2 Klaim mode race-safe (unique constraint + transaksi + update bersyarat)

```php
// claimMode: cek existing dalam DB::transaction, unique constraint jadi jaring terakhir.
// attachToWarClaim: update whereNull('typing_result_id') -> idempoten, race-safe.
```

**Justifikasi:** dua member bisa klik klaim mode yang sama **bersamaan**. Lapisan pertahanan:
cek dalam transaksi, lalu **unique constraint DB** sebagai jaring pengaman terakhir (menangkap
`QueryException`). Saat submit hasil, update bersyarat `whereNull('typing_result_id')` mencegah dua
submit paralel mengisi klaim yang sama. Ini pola **optimistic concurrency** yang benar.

#### 3.2.a Tiga umur klaim: dipesan → berjalan → selesai

Klaim dulu punya dua keadaan (`claimed` / `done`) dan `claimMode()` langsung **redirect** ke mesin
ketik — artinya klaim dan percobaan adalah satu tindakan. Begitu *membuka halaman* jadi saat
percobaan dimulai (§3.9), itu tak bisa dipertahankan: satu salah-klik akan membakar slot, dan
`cancelClaim()` jadi kode yang tak pernah terjangkau.

| Status | Arti | Bisa di-cancel? |
|---|---|---|
| `claimed` | **dipesan** — slot dipegang, percobaan belum dibuka | ✅ |
| `in_progress` | percobaan **sudah dibuka**; masuk ulang melanjutkannya | ❌ |
| `done` | hasil sudah disubmit | ❌ |

Jadi klaim sekarang hanya **memesan**. Tombol **"Start Attempt"** terpisah, dengan modal konfirmasi
— konfirmasinya sengaja ditaruh di langkah yang **tak bisa dibatalkan**, bukan di langkah yang bisa.

**Cancel berhenti di "pernah dibuka", bukan di "sudah disubmit".** Kalau tidak, cancel + klaim ulang
adalah jalan **ketiga** untuk restart, dan di Words itu latihan tanpa batas pada teks yang sudah
dibaca. Biayanya nyata dan disengaja: anggota yang membuka attempt lalu menghilang meninggalkan slot
mati bernilai 0. §3.5 membuat itu berhenti menjebak clan lawan, tapi slotnya sendiri tak bisa
diselamatkan.

### 3.3 Power pakai Elo (zero-sum, asimetris)

```php
$expectedA = 1 / (1 + 10 ** (($powerB - $powerA) / 400));
$deltaA = round(K_FACTOR * ($scoreA - $expectedA));   // K = 32
return [$deltaA, -$deltaA];
```

**Justifikasi:** Elo adalah standar teruji untuk rating kompetitif. **Zero-sum** (delta A = −delta B)
menjaga total power ekosistem stabil. **Asimetris**: menang lawan clan lebih kuat memberi lebih
banyak power daripada menang lawan yang lemah — mendorong menantang lawan sepadan/lebih kuat, bukan
"farming" clan lemah. `power_before` disnapshot saat accept agar perhitungan tak bias oleh perubahan
power selama war berlangsung.

### 3.4 Resolusi war "on-the-fly", tanpa scheduler wajib

```php
// ClanWar::mount() memanggil ClanWarResolver::resolveDue() sebelum membaca data.
```

**Justifikasi (pragmatis):** alih-alih mengandalkan cron/scheduler yang harus selalu jalan,
war yang lewat waktu diselesaikan **saat ada yang membuka halaman Clan War**. Ini menjamin data yang
ditampilkan selalu terkini tanpa infrastruktur tambahan. Command `clan-war:resolve` memanggil method
yang sama untuk pemakaian manual/terjadwal bila diinginkan — jadi tetap fleksibel.

### 3.5 Early finish: war beres begitu **tak ada lagi yang bisa dimainkan**

**Justifikasi:** tak perlu menunggu penuh 3 hari kalau kompetisi sudah selesai secara efektif —
lebih responsif bagi pemain.

> **Diperbaiki 2026-08-02.** Syaratnya dulu **9/9 di kedua sisi**
> (`bothClansFinishedAllModes()`), dan itu menggantung war selamanya. Clan yang **tak sanggup**
> mencapai 9 — rosternya menyusut, atau seorang anggota membuka percobaan lalu menghilang — tak
> akan pernah memenuhinya, sehingga clan **lawan** yang sudah mengerjakan semuanya tetap wajib
> menunggu tiga hari penuh. Hukumannya jatuh ke pihak yang tak melakukan kesalahan apa pun.

Sekarang `bothClansHaveNothingLeftToPlay()`. Satu clan dianggap selesai bila **9 slot tersubmit**,
**atau** tak ada lagi yang tertunda **dan** kuota klaimnya habis (`remainingClaimQuota() === 0`).

**Clan yang malas tetap tak bisa memicunya**, karena kemalasan selalu terbaca sebagai *pending*:
- klaim yang **belum pernah dibuka** → pending selamanya;
- attempt yang **sedang berjalan** → pending sampai melewati `ClanWarAttempt::STALE_MINUTES` (15 menit,
  lebih panjang dari slot mana pun).

Mengecilkan roster untuk memaksa early finish pun hanya menguntungkan clan yang **sudah unggul** —
dan early finish tetap menuntut **lawan** juga selesai, jadi ia cuma mempercepat hasil yang sudah
ditentukan, dengan mengorbankan poin anggota yang dikeluarkan.

### 3.6 `ClanWarScorer` fungsi murni, memakai ulang rumus akurasi EXP

**Justifikasi:** scorer tanpa state/DB → mudah diuji & diprediksi. `accuracyMultiplier` yang
**identik** dengan `User::addExp()` menjaga "akurasi dihargai dengan cara yang sama" di seluruh
aplikasi. Ceiling per mode dipusatkan di katalog (satu sumber kebenaran dipakai komponen, scorer,
dan resolver).

### 3.7 Real-time lewat channel `clan.{userId}` yang sudah ada, bukan channel war baru

Halaman Clan War dulu **hanya berubah saat di-reload**: poin lawan bertambah tapi layar diam,
dan panel "menunggu di-accept" bertahan meski lawan sudah menerima tantangan.

Penyebabnya bukan satu, melainkan dua yang saling menutupi:

1. **Jembatannya menembak ke ruang kosong.** `clan-war.blade.php` sudah lama memuat blok
   `@script` yang meneruskan event window `clan-updated-remote` ke `$wire.dispatch('clan-updated')`
   — salinan persis dari halaman Clans. Tapi [`ClanWar`](../../app/Livewire/ClanWar.php) tak punya
   `#[On(...)]` **sama sekali**, jadi dispatch itu dead code.
2. **Tak ada yang menyiarkan.** `claimMode()`, `cancelClaim()`, dan
   `TypingEngine::attachToWarClaim()` tak memanggil `broadcast()` sama sekali; `acceptChallenge()`
   memang menyiarkan, tapi **hanya ke leader penantang** — padahal panel yang sama dilihat seluruh
   member clan itu.

**Yang dipakai: `ClanUpdated($userId, null)` ke tiap peserta**, lewat
[`ClanWarBroadcast::refresh()`](../../app/Support/ClanWarBroadcast.php) +
`ClanWar::participantUserIds()` (member **aktif** kedua clan, satu query).

*Kenapa memakai ulang channel per-user, bukan membuat `war.{id}`:* `toasts.js` (`listenClan`)
sudah menaikkan `clan-updated-remote` pada **setiap** `.clan.updated` dan hanya memunculkan toast
bila ada `message` — jadi payload `null` sudah persis berarti "render ulang dirimu", dan setiap
halaman yang sudah subscribe mendapatkannya gratis. Channel khusus menuntut subscription Echo
baru, jembatan JS baru, dan siklus subscribe/unsubscribe baru untuk halaman yang ketiganya sudah
punya.

*Kenapa biayanya tak masalah:* fan-out-nya dibatasi bentuk war, bukan ukuran aplikasi — dua roster,
dan maksimal 9 claim + 9 cancel + 9 submit per sisi selama tiga hari.

**Handler `#[On('clan-updated')]`-nya kosong, dan itu memang cukup.** Seluruh data halaman ini
berasal dari computed property gaya lama `getXProperty()`, yang dimemoisasi di store per-*request*
— store yang dibangun ulang dari snapshot tiap round-trip. Jadi *menangani* event-nya saja sudah
menjalankan ulang semua query. (`Clans::refreshClan()` berisi `forgetClanCache()` justru karena
komponen itu memakai `#[Computed]` **dan** memutasi data di dalam satu request yang sama.)

**Leader tak dikirimi dua kali.** Yang sudah menerima `ClanUpdated` ber-pesan untuk perubahan yang
sama dilewati (`exceptUserIds`): `toasts.js` me-refresh lewat event itu juga, jadi event senyap
kedua cuma round-trip mubazir.

`declineChallenge()` dan `challengeClan()` ikut diperbaiki — kelas bugnya identik, pemain kebetulan
baru melaporkan kasus accept.

### 3.8 Layar hasil memberi tahu poin yang benar-benar diterima war

Poin sebenarnya **sudah** dihitung dan tersimpan ke `clan_war_mode_claims.points` sejak awal, tapi
`attachToWarClaim()` membuangnya sebagai variabel lokal — pemain menyelesaikan slot lalu kembali ke
halaman war tanpa pernah tahu kontribusinya berapa.

[`ClanWarScorer::breakdown()`](../../app/Services/ClanWarScorer.php) kini membeberkan faktor yang
dikalikan, dan **`score()` didefinisikan ulang di atasnya** — bukan sebaliknya. Layar yang
menurunkan rasionya sendiri akan jadi sumber kebenaran kedua, bebas menyimpang dari angka yang
benar-benar dijumlahkan scoreboard.

`basis` (`wpm` | `duration`) ikut dikembalikan karena survival dinilai dari **lama bertahan**, bukan
WPM: tanpa itu setiap pemanggil harus menulis ulang cabang tersebut untuk melabeli angkanya, dan
layar yang berbunyi "kecepatan … wpm" untuk survival hard menyatakan sesuatu yang **salah** tentang
mode ber-ceiling tertinggi di game ini.

**`score: null` adalah state kelas satu, bukan angka 0.** Otoritasnya adalah update bersyarat
`whereNull('typing_result_id')`, bukan scorer: submit paralel rekan sekelompok bisa mengisi slot
lebih dulu, dan war tak menerima apa pun dari run ini. Layar lalu menampilkan
`result.war.not_counted_title` (emas, sama seperti banner AFK — ini bukan tuduhan, cuma kerja yang
tak mendarat), bukan "0 poin" yang tak akan pernah cocok dengan scoreboard mana pun.

### 3.9 Resume, jangkar jam, dan semantik per mode

**Service:** [`ClanWarAttempt`](../../app/Services/ClanWarAttempt.php) ·
**State:** [`ClanWarAttemptState`](../../app/Support/ClanWarAttemptState.php)

Membuka `/typing?war_claim=N` **adalah** tindakan memulai percobaan. `open()` menulis tiga hal ke
baris klaim, sekali saja (`lockForUpdate` + cek null, jadi dua tab pun mendarat di jangkar yang sama):

| kolom | peran |
|---|---|
| `attempt_started_at` | **jangkar jam milik server** — client tak pernah menulisnya, jadi refresh tak bisa me-reset-nya |
| `attempt_text` | teks yang benar-benar diterbitkan, dibekukan untuk ketiga mode |
| `attempt_chars` | posisi resume dalam **karakter** |

Plus **buku besar** (`attempt_carried_*` / `attempt_live_*`) yang menyimpan kerja tiap sesi —
lihat §3.9.a, kolom-kolom itu yang membuat WPM sebuah attempt yang di-refresh tetap jujur.

**Ya, `mount()` menulis di GET, dan itu disengaja.** Ping "aku mulai mengetik" dari client justru
takkan dikirim oleh client yang sedang kita hadapi, jadi mount adalah satu-satunya jangkar yang tak
bisa dilewati.

**Attempt yang kedaluwarsa tetap war-locked.** Melepas lock akan menjatuhkan pemain ke sesi solo
gratis di URL `?war_claim=` — persis reroll yang sedang ditutup. Kondisi `expired` dibawa di state,
lalu client langsung `finish()` dan membukukan progress yang benar-benar diperolehnya.

#### Ketiga mode tak bisa diperlakukan sama

| Mode | Aturan | Kenapa |
|---|---|---|
| **time** | countdown = `subMode + 10 − elapsed`, di-clamp ke `subMode`. Durasi = `max(subMode, waktu ketik terjumlah)`. | Slotnya memang *n* detik wall-clock. `max` menutup celah kecil dari `COUNTDOWN_GRACE`: attempt yang di-resume boleh melewati nominal, dan menilai 65 detik ketikan atas 60 adalah inflasi yang sama dalam skala kecil. |
| **words** | `durasi = carried + klaim` (jumlah waktu ketik semua sesi) | Tak ada timer, jadi durasi adalah seluruh skor. Menjumlahkan sesi tak butuh grace sama sekali — waktu yang tak seorang pun mengetik memang tak pernah masuk penjumlahan. |
| **survival** | **tidak resume.** Restart di dalam anggaran: `wasted + credited ≤ 120 detik` | Satu-satunya mode yang menilai **dari** jam, jadi jam kontinu justru **menghadiahi** refresh, sementara jam per-sesi mengizinkan retry tanpa batas. |

**`GRACE_SECONDS` (30) tidak lagi menentukan harga sebuah refresh.** Dulu ia iya —
`durasi words = max(klaim, elapsed − 30)` — dan justru di situ letak bug-nya: 30 detik adalah
kelonggaran yang pas untuk waktu **membaca** dan bencana untuk sebuah reload, karena ia menghapus
setengah menit mengetik yang benar-benar terjadi. Sekarang ia kembali berarti persis seperti
namanya. `COUNTDOWN_GRACE_SECONDS` (10) tetap terpisah dan tetap kecil: ia hanya menanggung page
load, karena satu-satunya saat ia berarti adalah **resume**, dan yang masuk ulang sudah membaca
teksnya.

**Kredit survival dipotong hanya saat menilai war, tak pernah ke `TypingResult`.** Memendekkan
durasi justru **menaikkan** WPM, dan `AntiCheatService::check()` membaca `duration_seconds`:
pasangan 900 karakter / 55 detik akan terbaca 196 WPM dan menabrak gerbang mustahil. Rekor solo
tetap jujur ("kamu memang bertahan 90 detik"); yang dibatasi cuma kredit war-nya, lewat
`ClanWarScorer::breakdown($..., $durationOverride)`.

**Plafon karakter diukur dari jangkar, bukan dari mount.** `SoloSessionGuard` mengukur elapsed
**per tab**, dan refresh mencetak `tabKey` baru — jadi pemain yang resume dengan ratusan karakter
jujur akan dinilai terhadap jam yang baru mulai, lalu **ditolak**. `maxPlausibleChars()` karena itu
menerima dua parameter opsional (`$elapsedOverride`, `$windowSeconds`); jalur solo tak mengirim
keduanya dan perilakunya **byte-identical**. Slack tetap proporsional terhadap durasi, bukan
terhadap window — kalau tidak, slot war justru dapat plafon lebih longgar daripada tes solo yang sama.

### 3.9.a Buku besar attempt — kenapa refresh tak lagi menaikkan WPM

**Controller:** [`ClanWarProgressController`](../../app/Http/Controllers/ClanWarProgressController.php) ·
**Test:** `ClanWarResumeLedgerTest`, `ClanWarProgressPingTest`

Resume versi pertama memulihkan **posisi** pemain, lalu client mengkredit karakter yang dipulihkan
itu ke penghitung keystroke-nya sendiri. Tapi jam client baru mulai pada keystroke pertama
**setelah** reload. Jadi pembilangnya mencakup seluruh attempt sementara penyebutnya hanya sesi
terakhir — dan karena poin war = `ceiling × (wpm / 150)`, **refresh membeli poin**.

Kebocoran keduanya lebih senyap: karakter yang dipulihkan semuanya ditandai **benar**, jadi tiap
kesalahan sebelum reload lenyap dan `accuracyMultiplier` (0,5–1,0×) ikut membayarnya. Refresh
**mencuci akurasi**.

Keduanya berbentuk sama — karakter dikreditkan atas waktu yang tidak — dan keduanya ditutup dengan
**mengukur**, bukan menebak. Tiap sesi melaporkan waktu ketik & jumlah keystroke-nya sendiri; server
menjumlahkan:

| kolom | isi |
|---|---|
| `attempt_live_*` | sesi yang **sedang** berjalan, ditimpa tiap ping |
| `attempt_carried_*` | seluruh sesi **sebelumnya**, disegel oleh page load berikutnya |

Keduanya dipisah karena penghitung sesi baru mulai dari nol, dan itu tak bisa dibedakan dari
"mundur" kalau disatukan — dan karena kiriman di akhir **sudah** memuat sesi live secara utuh, jadi
menjumlahkan keduanya akan menghitungnya dua kali. Penyegelnya adalah `mount()` dan hanya `mount()`:
page load justru *adalah* peristiwa yang mengakhiri sebuah sesi.

Konsekuensi yang disengaja: **waktu menganggur tak lagi ditagih.** Aturan lama menagihnya (lewat
jam jangkar), tapi menganggur tak membeli apa pun — teks yang sama tetap harus diketik dengan
kecepatan yang sama, dan slotnya terkunci selama itu.

#### Ping posisi & buku besar

Sebuah **endpoint biasa** (`POST /clan-war/attempt-progress`), bukan method Livewire — dan itu
perbaikan bug, bukan kerapian. Panggilan Livewire adalah XHR, dan browser **membatalkan** XHR saat
halaman unload; jadi ping yang dikirim tepat ketika pemain menekan refresh — satu-satunya yang
menentukan di mana ia kembali — tak pernah tiba. Yang tersimpan selalu ping berkala terakhir,
sampai 5 detik basi, dan pemain mendarat beberapa kata di belakang. Kini dijangkau
`fetch(..., { keepalive: true })`, pola yang sama dengan leave-beacon multiplayer, dipicu
`pagehide` + `beforeunload` + `visibilitychange` (yang terakhir untuk tab yang dibuang Chrome
mobile — di HP itu justru cara paling lazim sebuah attempt terputus).

Lepas dari antrean Livewire, payload-nya tinggal lima integer, jadi throttle-nya turun **5 detik →
1 detik** dan ia melapor tiap kata selesai. Batasnya 240/menit di route.

Tiap angka diikat ke jam yang dipegang server: **batas fisik** (`elapsed × 20 cps`) untuk karakter,
**elapsed** untuk waktu ketik, dan **monoton** untuk semuanya. Ping yang hilang aman justru karena
ketiganya bergerak bersama — yang hilang adalah posisi *dan* waktu *dan* karakter sekaligus, jadi
yang tersisa tetap satu run yang konsisten; pemain cuma resume sedikit lebih ke belakang.

Client merekonstruksi posisinya dengan
[`resumePosition()`](../../resources/js/war-resume.js) — hanya span "kata + spasi" **utuh**, tak
pernah kata separuh, sama seperti `restoreProgress()` di `race-arena.js`. Diekstrak ke modulnya
sendiri supaya bisa diuji Vitest (`war-resume.test.js`); salah satu karakter di sini **gagal
diam-diam**, tak ada exception.

Satuannya **karakter**, bukan persen. Persen dipinjam dari `room_members.progress_percent`, di mana
ia hanya digambar sebagai bar; di sini ia aritmetika. Pada teks Words ~280 karakter, satu persen
adalah tiga karakter, lalu client membulatkan **lagi** ke batas kata — dua kali pembulatan untuk
masalah yang cuma butuh sekali. Karakter memakan ruang simpan yang sama.

Karakter yang dipulihkan digambar sebagai sudah-benar (pemain harus melihat kerjanya masih ada) tapi
**tidak** ditambahkan ke penghitung keystroke sesi ini. Sebagai gantinya buku besar `carried`
ikut turun ke client, sehingga WPM live yang dilihat pemain setelah resume adalah angka yang sama
dengan yang akan dinilai server — bukan angka lebih rendah yang melompat di akhir.

### 3.10 Cap klaim dinamis per ukuran clan

Batas klaim per anggota dulu **tetap 4**. 9 slot ÷ 4 = butuh **3 anggota** — aturan yang tertulis di
[anti-cheat-wpm.md §10.3](anti-cheat-wpm.md) tapi **tak pernah ditegakkan di kode dan tak pernah
diberitahukan ke pemain**. Clan 2 orang boleh menerima war, mengklaim 8 slot, lalu menatap slot ke-9
yang tak bisa diambil siapa pun.

```php
// ClanWarModeCatalog
public static function claimCapFor(int $activeMembers): int
{
    return max(self::MIN_CLAIMS_PER_MEMBER, (int) ceil(count(self::MODES) / max(1, $activeMembers)));
}
```

| anggota aktif | cap | kapasitas |
|---|---|---|
| 1 | 9 | 9 ✅ |
| 2 | 5 | 10 ✅ |
| 3+ | 4 | ≥12 ✅ |

**Kenapa melonggar hanya untuk clan kecil.** "Satu akun menentukan war sendirian" adalah risiko
nyata di clan beranggota lima; di clan beranggota dua itu memang **seluruh clan-nya**, dan mengunci
mereka dari fitur bukan perlindungan. Lantai 4 menjaga temuan F-03 tetap tertutup persis di tempat
ia berarti.

**Di-snapshot saat `acceptChallenge()`**, di `update()` yang sama dengan angka power dan untuk alasan
yang sama: keduanya menggambarkan war *sebagaimana disepakati*, dan tak boleh bergerak selagi war
berjalan. Formula live akan mengizinkan clan **menendang anggota di tengah war** untuk menaikkan
capnya sendiri — konsentrasi yang justru dicegah capnya. Kolomnya nullable dengan fallback
hitung-live, supaya war lama tetap jalan.

**Pesan errornya ikut diperbaiki.** Ketiga jalur gagal `claimMode()` dulu runtuh ke
`clan.error.mode_taken` ("baru saja diambil anggota lain") — **keliru secara fakta** untuk kasus
kuota habis, dan menyuruh pemain mencari rekan yang tak ada. Sekarang closure transaksinya
mengembalikan **alasan**, bukan `null`. Grid pun berhenti menawarkan tombol Claim begitu kuota
habis, dan `clan.war.modes_hint` menyebut angka capnya.

## 4. Integritas

Poin war hanya dihitung dari `ClanWarModeClaim` yang **sudah disubmit** (`typing_result_id` terisi).
Layar hasil pun tak pernah melaporkan poin yang **tak** diterima war: yang memutuskan adalah update
bersyarat itu, bukan scorer (§3.8).
Klaim yang terkunci tapi belum dikerjakan bernilai 0 — mencegah "kunci semua mode lalu diam" memberi
keuntungan. Hasil ketik war tetap melewati anti-cheat & Net WPM yang sama dengan mode solo.

**Poin war tak punya jalur input sendiri.** Semuanya diturunkan dari `TypingResult`, jadi apa pun
yang memalsukan hasil solo otomatis memalsukan poin war — bedanya, poin war menggerakkan **clan
power yang permanen**. Dua sifat scorer membuat serangan biasa jadi maksimal di sini:

- **survival hard** (ceiling tertinggi, 150) menilai dari `duration_seconds`, sehingga durasi
  panjang adalah **hadiah**. Di solo justru sebaliknya (durasi panjang menurunkan WPM), jadi
  asumsi "over-claim durasi merugikan diri sendiri" **tidak berlaku** di war.
- **time/words** mencapai rasio penuh **persis** di 150 WPM.

Penjaganya ada di [`SoloSessionGuard`](../../app/Services/SoloSessionGuard.php): plafon karakter
diukur dari waktu yang **benar-benar berlalu di server**, bukan durasi nominal, dan klaim durasi
yang melebihi umur sesi ditolak. Detail & angka kalibrasi:
[`anti-cheat-wpm.md`](anti-cheat-wpm.md) §9.

### 4.1 Posisi resume: input baru, tapi bukan permukaan serangan baru

`attempt_progress` dilaporkan **client**, dan itu memang input baru. Yang membuatnya aman bukan
validasinya, melainkan bahwa **server tak pernah memberi kredit atasnya**: karakter yang dipulihkan
hanya hidup di browser, dan angka yang dinilai tetap `totalKeystrokes`/`correctKeystrokes` yang
dikirim saat finish — dibatasi plafon karakter seperti biasa, dan kini plafon itu diukur dari
**jangkar**, yang sepanjang umur attempt justru **lebih ketat** daripada jam per-mount yang
digantikannya.

Yang paling buruk bisa dibeli sebuah `attempt_progress` palsu adalah kemampuan *mengirim* jumlah
karakter besar — yang memang sudah selalu bisa dicoba payload palsu — dan batas fisik di
`recordProgress()` membuat itu pun menuntut waktu nyata.

### 4.2 Yang masih terbuka (sengaja ditunda)

Dua hal berikut **belum** ditutup, dan sebaiknya dikerjakan berikutnya:

1. **`resolveWarClaim()` mencocokkan `clan_id`, bukan `user_id`.** Siapa pun anggota clan yang
   memegang URL `?war_claim=N` bisa mengerjakan slot rekannya. Dengan adanya resume, dampaknya
   naik: ia kini bisa **melanjutkan** attempt yang sedang berjalan milik orang lain, bukan sekadar
   memulai yang baru.
2. **`attachToWarClaim()` menimpa `user_id`** saat mengisi klaim, sehingga batas klaim per anggota
   (dihitung atas `user_id` saat klaim) bisa dilewati diam-diam. Ini makin relevan sejak capnya
   di-snapshot per war (§3.10).

Keduanya berbagi satu akar — kepemilikan klaim tak pernah diikat ke satu pemain — jadi sebaiknya
diperbaiki bersama, bukan sepotong-sepotong.
