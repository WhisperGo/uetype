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
(`MAX(net_wpm)`, `total_xp`, `COUNT(typing_results)`). Ini menghindari kebutuhan melacak event
temporal (streak harian, dll) yang jauh lebih rumit dan rawan bug. Kalau nanti butuh streak,
barulah tambah mekanisme — sekarang sengaja dijaga sederhana (*YAGNI*).

### 3.3 Rekor WPM diturunkan dari `typing_results`, bukan dibaca dari `users.highest_wpm`

```sql
COALESCE(MAX(CASE WHEN mode <> 'survival' THEN net_wpm END), 0) as highest_wpm
```

**Justifikasi:** ini penerapan prinsip §2.1 pada achievement. `users.highest_wpm` hanya pernah
**naik** dan tak pernah diturunkan, jadi begitu satu baris hasil dihapus (mis. hasil tinjauan
`typing:audit`), kolom itu terus menjamin data yang sudah tak ada — lencana tetap menyala di atas
nol. Menurunkannya membuat angkanya mustahil berselisih dengan `typing_results`.

Syarat `mode <> 'survival'` **mencerminkan persis** kondisi yang dipakai `TypingEngine` saat
menulis kolom itu (bukan daftar putih `IN ('time','words')`), supaya mode terukur baru terbaca
keduanya sekaligus. Survival dikecualikan karena diraih di bawah tekanan stamina — sejalan
dengan §2.2.

Kolomnya sendiri **tetap dipertahankan**: ia masih ditulis `TypingEngine` dan dibaca kartu profil
serta daftar teman sebagai angka "rekor karier" lintas mode.

### 3.4 Unlock bersifat permanen sekali tercatat

**Justifikasi:** status dulu dihitung ulang murni dari stat tiap render, sehingga lencana bisa
**hilang sendiri** saat data menyusut. Dipadukan dengan §3.3, itu berarti tinjauan anti-cheat yang
menghapus satu baris ikut mencabut lencana pemain jujur. Sekarang baris di `user_achievements`
diperlakukan sebagai bukti: `earned = sudah tercatat || check(stats)`. `syncUnlocks()` tak perlu
berubah — ia memang hanya menambah, tak pernah menghapus.

### 3.5 Pencatatan terjadi di DUA tempat: sesi solo dan finalisasi balapan

**Justifikasi:** balapan memberi EXP lewat `User::addExp()` persis seperti solo, jadi pemain bisa
menyeberangi ambang level di tengah balapan. Selama ini `syncUnlocks()` hanya dipanggil dari
`TypingEngine`, sehingga unlock itu tak pernah tercatat — lencananya muncul di `/achievements`
tanpa tanggal, dan baru tercatat kalau pemain kebetulan main solo lagi.

`FinalizesRace` kini ikut memanggilnya, **di luar transaksi finalisasi**. Transaksi itu menulis
place, xp, total_xp, dan satu baris riwayat untuk sampai `MAX_PLAYERS` pemain — jalur terpanas di
multiplayer — sedangkan `syncUnlocks()` menambah ~2 query per pemain. Karena achievement
diturunkan dan idempoten, kegagalannya sembuh sendiri pada panggilan berikutnya, jadi ia tak perlu
atomik bersama hasil balapan. Ini prinsip yang sama dengan broadcast: bagian sekunder tak boleh
menjatuhkan permintaan inti.

Balapan tidak menulis baris `typing_results`, jadi yang bisa terbuka dari sana hanya achievement
berbasis **level**. `syncUnlocks()` tetap dipanggil utuh (bukan memeriksa level saja) supaya
aturannya tetap di satu tempat.

### 3.6 Hasil solo mengumumkan apa yang baru terbuka

**Justifikasi:** `syncUnlocks()` sejak awal mengembalikan daftar key yang baru terbuka, tapi nilai
baliknya dibuang — sehingga pemain tak pernah tahu ia meraih sesuatu. Daftar itu kini ikut di
payload `typing_result` sebagai `newAchievements`, lalu diumumkan lewat **toast** pada stack global
yang sudah dipakai notifikasi teman/chat/klan.

Yang dibawa di session **hanya key**, bukan judul: judul cuma hidup di file lang (§3.7), dan
menyalinnya ke payload akan membangun ulang duplikasi yang sudah dibongkar. Toast tak bisa berulang
karena `syncUnlocks()` hanya menyisipkan baris yang belum ada, dan tak pernah muncul untuk sesi AFK
maupun tamu (keduanya tak mencatat apa pun).

Dua detail yang menentukan bentuknya:

- **Antrean `window.__uetypeToasts`, bukan event langsung.** `<x-toast-stack />` di-mount
  **setelah** slot halaman pada `app.blade.php`, jadi event yang dikirim saat halaman hasil
  inisialisasi belum ada pendengarnya. Halaman menaruh muatannya di antrean; `toastStack` menyerap
  antrean itu saat mount dan juga mendengarkan event `uetype-toast` sebagai dorongan. Pengurasan
  mengosongkan antrean, jadi toast yang sama tak mungkin tampil dua kali.
- **Semua unlock masuk SATU toast.** `push()` menampilkan satu toast pada satu waktu (yang baru
  menimpa yang lama, sengaja, agar layar tak penuh), sehingga mengirim dua akan membuang salah
  satunya tanpa jejak. Nama-namanya digabung memakai `common.list_separator` — dari file lang,
  bukan dirangkai di JavaScript.

Konsekuensi yang diterima: toast hilang sendiri setelah 6 detik dan tak meninggalkan jejak di
halaman hasil. Pemain yang melewatkannya baru melihat lencananya di `/achievements`.

Multiplayer sengaja **hanya mencatat, tanpa mengumumkan**: finalisasi dijalankan satu klien untuk
semua peserta, jadi klien itu tak bisa memberi tahu pemain lain. Mengumumkan butuh mekanisme
"belum diberitahu" per pemain (kolom + migrasi) yang belum dikerjakan.

`php artisan achievements:backfill` mencatat achievement yang syaratnya sudah lama terpenuhi tapi
belum pernah tertulis, supaya sesi solo pertama setelah fitur ini aktif tidak memuntahkan
pencapaian lama sekaligus sebagai "baru".

### 3.7 Judul & deskripsi hanya di file lang

**Justifikasi:** `AchievementDefinitions` dulu ikut menyimpan `title`/`description`, padahal kedua
view merender lewat `__('achievements.defs.<key>.title')` — salinan di kode **tak pernah sampai ke
layar**, jadi mengeditnya diam-diam tak mengubah apa pun. Definisi kini hanya memuat aturan.
`LangParityTest` menjaga en↔id sejajar satu sama lain, tapi tak tahu daftar definisi; celah itu
ditutup `AchievementsPageTest` yang mewajibkan tiap key punya entri di kedua bahasa.
