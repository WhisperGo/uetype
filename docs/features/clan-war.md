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

## 4. Integritas

Poin war hanya dihitung dari `ClanWarModeClaim` yang **sudah disubmit** (`typing_result_id` terisi).
Klaim yang terkunci tapi belum dikerjakan bernilai 0 — mencegah "kunci semua mode lalu diam" memberi
keuntungan. Hasil ketik war tetap melewati anti-cheat & Net WPM yang sama dengan mode solo.
