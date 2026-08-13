# UeType — Game Latihan Mengetik

UeType adalah aplikasi web **game latihan mengetik** (typing test) bergaya
Monkeytype/TypeRacer, dilengkapi mode **balapan multiplayer real-time**, sistem
**clan (guild)** dengan **perang antar-clan**, **chat** (DM & clan), **pertemanan**
dengan status online/offline, **achievement**, **leveling (XP)**, **leaderboard**,
serta **panel monitoring** aktivitas pengguna.

Dibangun dengan **Laravel 12 + Livewire 4 + Alpine.js + Tailwind CSS**, dengan
**Laravel Reverb** (WebSocket) sebagai tulang punggung fitur real-time.

> Dokumentasi teknis lebih dalam ada di [`docs/PROJECT_OVERVIEW.md`](docs/PROJECT_OVERVIEW.md)
> (arsitektur, skema database, route) dan [`docs/features/`](docs/features/README.md)
> (penjelasan per fitur beserta alasan desainnya).

---

## Daftar Isi

1. [Fitur](#fitur)
2. [Teknologi](#teknologi)
3. [Spesifikasi Minimum](#spesifikasi-minimum)
4. [Instalasi Offline](#instalasi-offline)
5. [Menjalankan Aplikasi](#menjalankan-aplikasi)
6. [Akun Uji Coba (Dev Login)](#akun-uji-coba-dev-login)
7. [Panel Monitoring](#panel-monitoring)
8. [Testing](#testing)
9. [Deployment Production](#deployment-production)
10. [Struktur Proyek](#struktur-proyek)
11. [Troubleshooting](#troubleshooting)

---

## Fitur

### Mengetik (Solo)
- **Mode Time** — ketik sebanyak mungkin dalam 15 / 30 / 60 / 120 detik.
- **Mode Words** — ketik 10 / 25 / 50 / 100 kata secepat mungkin.
- **Mode Survival** — stamina berkurang seiring waktu; skor = berapa lama bertahan.
- **Ghost Mode** — balapan melawan rekor: diri sendiri, teman, atau entri leaderboard.
- **Dua bahasa konten** (Inggris / Indonesia) yang bisa dipilih terpisah dari bahasa UI.
- **Anti-cheat**: WPM & akurasi dihitung ulang di server (Net WPM); hasil mustahil ditolak,
  sedangkan anomali longitudinal masuk probation otomatis. Lihat
  [`docs/features/anti-cheat-wpm.md`](docs/features/anti-cheat-wpm.md).

### Multiplayer Race (Real-time)
- Buat / gabung ruang lewat **kode 6 karakter acak** (maks. 5 pemain + 5 penonton).
- **Countdown 3-2-1 tersinkron** di semua layar, lalu balapan bersama.
- Progres & maskot lawan bergerak **real-time** lewat WebSocket.
- **Sudden death** (masa tenggang setelah pemenang pertama finis).
- **Mode penonton (spectator)**: masuk untuk menonton tanpa membalap; luapan otomatis
  jadi penonton saat slot pemain penuh, dan bisa tukar peran saat menunggu.
- **Undang teman**: klik slot kosong untuk mengundang teman; mereka menerima notifikasi
  Accept/Decline non-blocking dan langsung bergabung.
- **Pilih bahasa teks** (EN/ID) di dalam room — hanya host yang bisa mengubahnya.
- **Chat ruang**: ngobrol sambil menunggu di lobby & di layar hasil (untuk ngajak main
  lagi), plus **notifikasi saat ada yang masuk/keluar** ruang.
- **Auto-bersih room "hantu"**: member yang menutup tab tanpa keluar disapu otomatis saat
  ada yang membuka lobby (host di-oper ke pemain lain, atau room dihapus jika kosong).
- Riwayat pertandingan permanen + statistik (win rate, placement, dsb).

### Sosial
- **Chat** DM & clan: kirim, **reply**, **edit** (jendela 30 menit), **hapus**
  (untuk diri / untuk semua), **clear chat**, plus **overlay chat** yang bisa dibuka
  dari halaman mana pun & digeser bebas.
- **Pertemanan**: kirim/terima/tolak permintaan; **status online/offline** real-time.
- **Notifikasi toast** lintas halaman (teman, clan, chat).

### Clan & Clan War
- **Clan (guild)** hingga 20 anggota, dengan emblem/warna/deskripsi kustom.
- Sistem **power (Elo)** dan level clan.
- **Clan War**: pertempuran 1v1 antar-clan di grid 9-mode, diselesaikan dengan Elo.
- **Leaderboard clan** berdasarkan power.

### Progres & Statistik
- **Level & XP** — XP dari karakter benar × pengali akurasi (adil: ngasal-cepat tak menang).
- **Achievement** — 15 pencapaian di 5 kategori (WPM, jumlah tes, level, akurasi, karakter).
- **Halaman Stats** — grafik WPM/akurasi, distribusi mode, statistik multiplayer.
- **Leaderboard global** per mode/konfigurasi/rentang waktu.

### Autentikasi
- **Login Google** (OAuth via Socialite) + alur pilih username.
- Tidak ada register/login email-password, reset password, atau verifikasi email.

### Monitoring (Admin)
- **Visit monitoring** (kunjungan halaman), **action monitoring** (create/update/delete
  model penting), **authentication monitoring** (login/logout). Dashboard tersedia di
  `/user-monitoring/*` dengan auto-refresh 10 detik, **hanya untuk akun admin**
  (`is_admin = true`); selain itu 404.

---

## Teknologi

| Lapisan | Teknologi |
|---|---|
| Backend | PHP 8.2+, Laravel 12 |
| UI reaktif | Livewire 4 + Volt |
| Interaksi klien | Alpine.js 3 |
| Styling | Tailwind CSS 3 |
| Real-time | Laravel Reverb (WebSocket, protokol Pusher) + Laravel Echo |
| OAuth | Laravel Socialite (Google) |
| Grafik | Chart.js |
| Build tool | Vite 6 |
| Testing | Pest 4 |
| Monitoring | binafy/laravel-user-monitoring |

---

## Spesifikasi Minimum

### Perangkat Lunak (wajib)
| Komponen | Versi minimum | Catatan |
|---|---|---|
| **PHP** | **8.2** | Ekstensi wajib: `pdo_mysql`, `mbstring`, `openssl`, `ctype`, `json`, `bcmath`, `fileinfo`, `tokenizer`, `curl`, `xml` |
| **Composer** | 2.x | Manajer paket PHP |
| **Node.js** | **18 LTS+** (disarankan 20 LTS) | Untuk build aset frontend |
| **npm** | 9+ | Ikut Node |
| **MySQL / MariaDB** | MySQL 8.0+ / MariaDB 10.6+ | Bisa lewat Laragon/XAMPP |

> Cara termudah di Windows: pakai **Laragon** (sudah membundel PHP 8.2+, MySQL, dan
> Composer). Di Laragon, proyek dapat diletakkan misalnya di `C:\laragon\www\uetype`.

### Perangkat Keras (disarankan minimum untuk pengembangan lancar)
| Komponen | Minimum | Nyaman |
|---|---|---|
| CPU | 2 core | 4 core |
| RAM | 4 GB | 8 GB+ (karena menjalankan PHP + MySQL + Reverb + Vite bersamaan) |
| Penyimpanan | 2 GB kosong | 5 GB+ (vendor + node_modules cukup besar) |
| OS | Windows 10 / macOS / Linux | — |

### Browser (untuk memakai aplikasi)
Browser modern yang mendukung WebSocket & Alpine.js: **Chrome/Edge 90+**,
**Firefox 90+**, atau **Safari 15+**.

---

## Instalasi Offline

Panduan ini mengasumsikan **paket sudah tersedia lokal** (`vendor/` dan
`node_modules/` sudah ada, atau ada cache Composer/npm), sehingga tak perlu koneksi
internet. Kalau `vendor/`/`node_modules/` **belum ada**, jalankan `composer install`
dan `npm install` sekali saat masih online, lalu sisanya bisa offline.

### 1. Salin proyek
Letakkan folder proyek di web root (mis. `C:\laragon\www\uetype`).

### 2. Buat file environment
Salin `.env.example` menjadi `.env`:
```bash
cp .env.example .env
```

### 3. Sesuaikan `.env`
Proyek ini memakai **MySQL** (bukan SQLite default). Ubah bagian database:
```env
APP_NAME=UeType
APP_ENV=local
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=uetype
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=my-app-id
REVERB_APP_KEY=my-app-key
REVERB_APP_SECRET=my-app-secret
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
```
> **PENTING:** `REVERB_APP_ID/KEY/SECRET` harus diisi (nilai apa saja yang konsisten).
> Nilai `VITE_REVERB_*` di bawahnya sudah mewarisi otomatis dari `REVERB_*`.

### 4. Buat database
Buat database MySQL kosong bernama **`uetype`** (sesuai `DB_DATABASE` di atas),
mis. via phpMyAdmin/HeidiSQL/CLI:
```sql
CREATE DATABASE uetype CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Install dependency (jika belum)
```bash
composer install
npm install
```

### 6. Generate app key
```bash
php artisan key:generate
```

### 7. Migrasi & seed data awal
```bash
php artisan migrate --seed
```
Ini membuat semua tabel (termasuk tabel monitoring) dan menjalankan seeder. Teks latihan
tidak disimpan di database; teks dirakit dari wordlist JSON. Seeder default mengisi roster demo
leaderboard, sedangkan data multiplayer/clan tambahan dan akun `/dev-login` hanya dibuat pada
environment `local`. Jangan menambahkan `--seed` ke deployment production rutin kecuali memang
ingin memperbarui data demo; `deploy.sh` sengaja hanya menjalankan `php artisan migrate --force`.

### 8. Build aset frontend
```bash
npm run build
```
> **PENTING (real-time):** Nilai `VITE_REVERB_*` di-*bake* ke bundle JS saat build.
> Kalau Anda mengubah `REVERB_HOST` (mis. ke IP LAN agar bisa diakses perangkat lain),
> **wajib `npm run build` ulang**, atau koneksi WebSocket di browser gagal diam-diam.

---

## Menjalankan Aplikasi

Fitur real-time (multiplayer, chat, presence) butuh **tiga proses** berjalan bersamaan.
Buka **3 terminal**:

**Terminal 1 — web server:**
```bash
php artisan serve
```
Akses di `http://127.0.0.1:8000`.

**Terminal 2 — Reverb (WebSocket server):**
```bash
php artisan reverb:start
```
Wajib jalan untuk chat, multiplayer race, dan notifikasi real-time.

**Terminal 3 — Vite (opsional, untuk dev dengan hot-reload):**
```bash
npm run dev
```
> Kalau sudah `npm run build` (langkah 8), terminal 3 ini **tidak wajib** —
> aplikasi memakai aset statis hasil build.

> Alternatif praktis: `composer run dev` menjalankan server + queue + log + Vite
> sekaligus dalam satu perintah (tapi Reverb tetap dijalankan terpisah).

Resolver anti-cheat berjalan langsung setelah hasil disimpan. Untuk menjalankan safety net
terjadwal selama pengembangan, gunakan terminal tambahan:

```bash
php artisan schedule:work
```

---

## Akun Uji Coba (Dev Login)

Di environment `local`, tersedia jalur login instan tanpa password (butuh seeder sudah
dijalankan — sudah termasuk di `php artisan migrate --seed`):

- `http://127.0.0.1:8000/dev-login` — login sebagai user dummy utama (`dummy@uetype.test`).
- `http://127.0.0.1:8000/dev-login?email=test@example.com` — login sebagai akun **admin**
  bawaan seeder (berguna untuk membuka panel monitoring, lihat di bawah).

Parameter `?email=` menerima email user mana pun yang sudah ada, jadi untuk menguji
chat/multiplayer dua akun sekaligus, buka satu di browser biasa dan satu lagi (dengan
`?email=` berbeda) di jendela incognito.

---

## Panel Monitoring

Dashboard monitoring aktivitas, **khusus admin** (`is_admin = true`):

- `/user-monitoring/visits-monitoring` — kunjungan halaman.
- `/user-monitoring/actions-monitoring` — aksi create/update/delete pada model penting
  (Clan, Room, Message, Friendship).
- `/user-monitoring/authentications-monitoring` — riwayat login/logout.

Guest maupun user biasa mendapat **404** di ketiga URL itu, jadi keberadaan panelnya tak
bocor. Seeder sudah membuat satu akun admin bawaan (`test@example.com`) — di environment
`local` cukup login lewat `/dev-login?email=test@example.com`. Untuk mengangkat/mencabut
admin akun lain:

```bash
php artisan user:admin email@kamu.com            # jadikan admin
php artisan user:admin email@kamu.com --revoke   # cabut hak admin
```

Data dicatat **real-time** ke database; tampilan dashboard **auto-refresh tiap 10 detik**
(bisa dimatikan lewat toggle di pojok kanan atas).

---

## Testing

Proyek memakai **Pest**.

### Persiapan sekali per mesin

Test berjalan di database **terpisah** dari database dev, jadi menjalankan suite tidak
pernah menghapus data lokal Anda. Namanya dipatok di `phpunit.xml` (`uetype_test`), tapi
databasenya sendiri harus dibuat sekali:

```sql
CREATE DATABASE uetype_test;
```

Tidak perlu dimigrasikan manual — `RefreshDatabase` mengurusnya tiap kali suite jalan.

### Menjalankan

```bash
php artisan test                                   # seluruh test
php artisan test tests/Feature/ChatOverlayTest.php # sebagian
php artisan test --parallel                        # lebih cepat (butuh 1 DB per proses,
                                                   # `uetype_test_1..N`, dibuat otomatis)
vendor/bin/pint                                    # perapi gaya kode
npm test                                           # test unit JS (Vitest)
```

Test PHP memakai `BROADCAST_CONNECTION=null`, sehingga Reverb tidak perlu dijalankan saat test.

---

## Deployment Production

`npm run build` hanya membangun aset frontend. Perubahan backend dapat membawa dependency
Composer baru, migrasi database, route/config baru, atau proses persisten yang perlu dimuat ulang.
Karena itu deployment production harus menjalankan seluruh rangkaian deployment, bukan hanya build.

File `deploy.sh` di root proyek menjalankan maintenance mode, `git pull --ff-only`, instalasi
dependency, migrasi, build frontend, dan pembuatan ulang cache Laravel:

```bash
chmod +x deploy.sh       # sekali saja setelah file tersedia di server
./deploy.sh
```

Script dijalankan dari checkout production oleh user sistem yang memiliki akses tulis ke `storage/`
dan `bootstrap/cache/`. `git pull --ff-only` akan berhenti jika checkout server tidak dapat
di-*fast-forward*, sehingga perubahan lokal di server harus diamankan terlebih dahulu.

### Scheduler

Tambahkan satu cron Laravel pada server. Scheduler menjalankan `typing:reconcile` setiap jam sebagai
safety net untuk hasil anti-cheat yang belum sempat diselesaikan secara sinkron:

```cron
* * * * * cd /path/ke/uetype && php artisan schedule:run >> /dev/null 2>&1
```

Cron dipasang **sekali**, bukan setiap deployment. Verifikasi jadwal dengan:

```bash
php artisan schedule:list
```

### Proses persisten

`deploy.sh` tidak mengetahui process manager server. Setelah deployment, restart proses Reverb dan
queue worker bila production menjalankannya melalui Supervisor, systemd, atau process manager lain.
Gunakan konfigurasi service server masing-masing; jangan menjalankan dua instance Reverb pada port
yang sama. `php artisan queue:restart` hanya diperlukan bila queue production memakai worker
persisten—`.env.example` sendiri memakai queue `sync`.

---

## Struktur Proyek

```
app/
  Livewire/        Komponen UI (TypingEngine, MultiplayerLobby, Chat, ChatOverlay, Clans, ...)
  Services/        Logika domain (AntiCheatService, EloCalculator, ClanWar*, ...)
  Events/          Payload broadcast real-time (RoomUpdated, RaceProgressUpdated, ...)
  Models/          Model Eloquent
  Http/            Controller & middleware
config/            Konfigurasi (termasuk reverb.php, user-monitoring.php)
database/
  migrations/      Skema database
  seeders/         Data awal & dummy
resources/
  views/livewire/  Blade untuk komponen Livewire
  js/, css/        Aset frontend (echo.js untuk Reverb)
routes/            web.php, channels.php, console.php, user-monitoring.php
tests/             Test Pest (Feature & Unit)
docs/              Dokumentasi teknis (PROJECT_OVERVIEW.md, features/)
```

---

## Troubleshooting

**`Table 'users' doesn't exist` / migrasi berhenti di tengah**
Jalankan ulang dari awal: `php artisan migrate:fresh --seed`. Pastikan database
`uetype` sudah dibuat dan `DB_*` di `.env` benar.

**Chat/multiplayer tidak real-time / countdown tak muncul di perangkat lain**
1. Pastikan `php artisan reverb:start` berjalan.
2. Kalau `REVERB_HOST` diubah (mis. ke IP LAN), **`npm run build` ulang** lalu
   hard-refresh browser (`Ctrl+Shift+R`).
3. Pastikan firewall mengizinkan port Reverb (default `8080`) untuk koneksi masuk.

**Perubahan Blade/JS tak muncul**
Bersihkan cache view: `php artisan view:clear`, lalu hard-refresh browser.

**Login Google gagal**
Isi kredensial Google OAuth di `.env` (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
`GOOGLE_REDIRECT_URI`). Untuk uji lokal cepat, gunakan `/dev-login` saja.

---

*Untuk gambaran arsitektur, skema database utama, dan penjelasan tiap fitur beserta
alasan desainnya, lihat [`docs/PROJECT_OVERVIEW.md`](docs/PROJECT_OVERVIEW.md) dan
[`docs/features/`](docs/features/README.md).*
