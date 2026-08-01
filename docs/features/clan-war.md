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
Sekali klaim = satu kesempatan pada mode yang ditentukan. War mode Words bahkan pakai **teks TETAP**
(`ClanWarFixedText`, identik untuk semua pemain di config yang sama) demi keadilan — bukan dirakit
acak. Semua ini gerbang **server-side**, bukan sekadar menyembunyikan tombol.

### 3.2 Klaim mode race-safe (unique constraint + transaksi + update bersyarat)

```php
// claimMode: cek existing dalam DB::transaction, unique constraint jadi jaring terakhir.
// attachToWarClaim: update whereNull('typing_result_id') -> idempoten, race-safe.
```

**Justifikasi:** dua member bisa klik klaim mode yang sama **bersamaan**. Lapisan pertahanan:
cek dalam transaksi, lalu **unique constraint DB** sebagai jaring pengaman terakhir (menangkap
`QueryException`). Saat submit hasil, update bersyarat `whereNull('typing_result_id')` mencegah dua
submit paralel mengisi klaim yang sama. Ini pola **optimistic concurrency** yang benar.

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

### 3.5 Early finish: war beres lebih cepat kalau kedua clan tuntas 9 mode

**Justifikasi:** tak perlu menunggu penuh 3 hari kalau kompetisi sudah selesai secara efektif —
lebih responsif bagi pemain. Dicek lewat `bothClansFinishedAllModes()`.

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
