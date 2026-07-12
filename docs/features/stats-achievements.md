# Fitur 6 — Statistik & Achievement

**Komponen Stats:** [`App\Livewire\Stats`](../../app/Livewire/Stats.php) — route `/stats`
**Achievement:** [`App\Services\AchievementService`](../../app/Services/AchievementService.php),
[`App\Support\AchievementDefinitions`](../../app/Support/AchievementDefinitions.php) — route `/achievements`

---

## 1. Apa Ini

- **Statistik**: agregat performa pemain — rekor WPM per mode/config, rata-rata WPM & akurasi,
  total tes, total waktu, grafik tren WPM/akurasi, dan distribusi mode.
- **Achievement**: lencana yang otomatis terbuka saat pemain memenuhi ambang tertentu (mis.
  "Reach 100 WPM", "Complete 100 tests").

## 2. Keputusan Desain & Justifikasi

### 2.1 Semua statistik diturunkan saat render, tak ada kolom agregat tersimpan

```php
$activity = [
    'total_tests' => (clone $base)->count(),
    'avg_accuracy' => round((float) (clone $base)->avg('accuracy'), 1),
    // ...
];
```

**Justifikasi:** angka **selalu konsisten** dengan `typing_results` — tak mungkin "rata-rata WPM
tersimpan" menyimpang dari data sebenarnya. Trade-off: query agregat tiap render, tapi untuk skala
per-user ini murah dan menghilangkan seluruh kelas bug sinkronisasi.

### 2.2 Rekor Survival = MAX(duration_seconds), bukan MAX(net_wpm)

**Justifikasi:** metrik Survival adalah **lama bertahan**, bukan kecepatan. Memakai `net_wpm`
untuk rekor Survival akan menyesatkan. Ini mengikuti pemisahan yang sama seperti di
[typing-engine.md](typing-engine.md).

### 2.3 Distribusi mode sengaja TIDAK memuat "ghost"

```php
// 'ghost' TIDAK disertakan. Sesi ghost tersimpan sebagai baris 'time'/'words' biasa,
// jadi slice ghost akan selalu 0% dan menyesatkan.
```

**Justifikasi:** karena ghost tak pernah ditulis sebagai mode tersendiri di DB (lihat
[ghost-mode.md](ghost-mode.md)), memasukkannya ke distribusi hanya menghasilkan slice 0% yang
membingungkan. Konsistensi keputusan lintas fitur.

### 2.4 Rentang grafik disimpan di query string (`?range=7|30|all`)

**Justifikasi:** tautan halaman stats bisa **dibagikan/di-refresh** tanpa reset rentang. Whitelist
`RANGES` menjaga nilai liar tak lolos. Grafik dibatasi `take(200)` agar rentang `all` tak menarik
ribuan baris.

### 2.5 Halaman Stats hanya memajang achievement yang SUDAH diraih

**Justifikasi:** memisahkan tanggung jawab — daftar lengkap (termasuk yang terkunci + progres)
adalah tugas halaman `/achievements`. Stats fokus menampilkan pencapaian, bukan checklist.

## 3. Achievement — Desain & Justifikasi

### 3.1 Definisi di kode (closure), bukan di database

```php
'check' => fn (array $s) => $s['highest_wpm'] >= 100,
```

**Justifikasi:** achievement adalah **aturan**, bukan data yang berubah runtime. Menaruhnya di kode
(sebagai closure `check(stats): bool`) berarti perubahan aturan lewat *code review*, tak ada rule
engine kompleks, dan mudah diuji. Tabel `user_achievements` hanya mencatat **siapa & kapan**.

### 3.2 Dihitung dari data existing (stateless), bukan pelacakan streak/temporal

**Justifikasi:** semua achievement saat ini bisa diturunkan dari agregat yang sudah ada
(`highest_wpm`, `total_xp`, `COUNT(typing_results)`). Ini menghindari kebutuhan melacak event
temporal (streak harian, dll) yang jauh lebih rumit dan rawan bug. Kalau nanti butuh streak,
barulah tambah mekanisme — sekarang sengaja dijaga sederhana (*YAGNI*).
