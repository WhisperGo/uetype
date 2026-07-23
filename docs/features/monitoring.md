# Fitur 13 — Monitoring Aktivitas Pengguna

**Paket:** [`binafy/laravel-user-monitoring`](https://github.com/binafy/laravel-user-monitoring)
**Provider kustom:** [`App\Providers\UserMonitoringServiceProvider`](../../app/Providers/UserMonitoringServiceProvider.php)
**Config:** [`config/user-monitoring.php`](../../config/user-monitoring.php)
**Middleware:** `VisitMonitoringMiddleware` (didaftarkan di [`bootstrap/app.php`](../../bootstrap/app.php)),
`EnsureUserIsAdmin` ([`app/Http/Middleware/EnsureUserIsAdmin.php`](../../app/Http/Middleware/EnsureUserIsAdmin.php))
**Dashboard:** `/user-monitoring/visits-monitoring`, `/actions-monitoring`, `/authentications-monitoring`
**Akses:** **admin saja** (`is_admin = true`) — lihat §3.6

---

## 1. Apa Ini

Panel monitoring untuk melacak aktivitas pengguna, terbagi tiga jenis:

- **Visit monitoring** — mencatat setiap kunjungan halaman (URL, browser, platform, IP).
- **Action monitoring** — mencatat aksi **create / update / delete** pada model penting
  (Clan, Room, Message, Friendship).
- **Authentication monitoring** — mencatat **login / logout** pengguna.

Data dicatat **real-time** ke database saat aktivitas terjadi. Dashboard-nya menampilkan
tabel + statistik, dengan **auto-refresh 10 detik** (bisa dimatikan lewat toggle).

## 2. Cara Kerja (alur singkat)

1. **Visit** — `VisitMonitoringMiddleware` menempel di grup `web`, mencatat tiap request
   halaman ke tabel `visits_monitoring`.
2. **Action** — model yang memakai trait `Actionable` otomatis mencatat event
   create/update/delete-nya ke `actions_monitoring`.
3. **Authentication** — paket mendengarkan event `Login`/`Logout` Laravel dan mencatat ke
   `authentications_monitoring`.

## 3. Keputusan Desain & Justifikasi

### 3.1 Provider kustom, bukan auto-discovery paket

Provider bawaan paket memanggil `loadMigrationsFrom(vendor/...)` yang migrasinya
**bertanggal 2023** — jadi jalan **sebelum** tabel `users` (2026) dan gagal (foreign key
ke `users` yang belum ada). Ini pernah membuat seluruh migrasi berhenti di tengah sehingga
`users` & semua tabel app tak terbuat.

**Solusi:** auto-discovery paket dimatikan di
[`composer.json`](../../composer.json) (`extra.laravel.dont-discover`), dan diganti
[`UserMonitoringServiceProvider`](../../app/Providers/UserMonitoringServiceProvider.php)
yang melakukan semua fungsi paket **kecuali `loadMigrationsFrom`**. Migrasi monitoring
di-*copy* ke `database/migrations` dengan tanggal `2026_04_28_070001-070004` (**setelah**
`users`).

**Kenapa begini:** tidak mengedit `vendor/` (aman dari `composer install`), dan urutan
migrasi jadi benar secara permanen.

### 3.2 `display_attribute` = `username`, bukan `name`

App ini **tak punya kolom `name`** di tabel `users` — identitas disimpan di `username`.
Config `user.display_attribute` diubah ke `'username'` supaya dashboard menampilkan nama
pengguna dengan benar (kalau tetap `'name'`, kolomnya kosong/error).

### 3.3 `on_read` dimatikan untuk action monitoring

Config `action_monitoring.on_read` diset **`false`**. Alasannya: model seperti `Message`
dibaca **puluhan kali per halaman** (paginasi chat) — kalau read ikut dicatat, tabel
`actions_monitoring` akan membanjir. Hanya aksi **tulis** (store/update/destroy) yang dilog.

### 3.4 Model yang dipantau aksinya

Trait `Actionable` dipasang hanya pada model yang mewakili **aksi domain bermakna**:
[`Clan`](../../app/Models/Clan.php), [`Room`](../../app/Models/Room.php),
[`Message`](../../app/Models/Message.php), [`Friendship`](../../app/Models/Friendship.php).
Model pivot/internal/write-once (mis. `RoomMember`, `MessageDelete`) sengaja **tidak**
dipantau agar log tetap fokus & tak berisik.

### 3.5 Auto-refresh tampilan (bukan realtime push)

Pencatatan log sudah realtime di DB, tapi **dashboard-nya halaman statis** — hanya query
sekali saat dimuat. Ditambahkan auto-refresh sederhana (reload halaman tiap 10 detik) di
layout dashboard yang di-publish
([`resources/views/vendor/LaravelUserMonitoring/layouts/master.blade.php`](../../resources/views/vendor/LaravelUserMonitoring/layouts/master.blade.php)):

- State on/off disimpan di `localStorage` (tak reset tiap reload; default **ON**).
- Berhenti saat tab tak terlihat (`visibilitychange`) — hemat request.
- Ada toggle "Auto-refresh (10s)" di pojok kanan atas.

**Kenapa reload penuh, bukan WebSocket:** dashboard monitoring jarang dibuka & tak butuh
presisi milidetik; reload sederhana sudah cukup dan minim kode. (Kalau kelak butuh update
tanpa reload, bisa naik ke polling AJAX atau broadcast via Reverb.)

### 3.6 Akses dibatasi admin (404, bukan 403)

Dashboard ini sempat **terbuka untuk siapa pun**, termasuk pengunjung yang belum login:
route-nya didaftarkan oleh provider bawaan paket
(`LaravelUserMonitoringRouteServiceProvider`) yang **menghardcode** middleware-nya menjadi
`web` + `VisitMonitoringMiddleware` saja. Artinya IP, browser, dan riwayat halaman seluruh
pengguna bisa dibaca publik — dan route `DELETE`-nya bisa dipakai menghapus jejak audit.

**Solusi:** provider paket itu **tidak lagi didaftarkan**. Sebagai gantinya
[`UserMonitoringServiceProvider::registerRoutes()`](../../app/Providers/UserMonitoringServiceProvider.php)
mengikat file route yang sama di belakang `EnsureUserIsAdmin`.

**Kenapa 404 dan bukan 403:** 403 (atau redirect ke login) sama saja mengonfirmasi bahwa
halaman itu **ada**. Guest dan user login non-admin sengaja diberi respons **identik** (404)
supaya keberadaan panel admin tak bisa disimpulkan dari perbedaan respons. Itu juga alasan
middleware `auth` **tidak** dipakai berbarengan — `auth` akan me-redirect guest ke login dan
justru membocorkan URL-nya.

**Kenapa `VisitMonitoringMiddleware` dilepas dari grup route ini:** ketiga halaman dashboard
sudah terdaftar di `visit_monitoring.except_pages`, jadi middleware itu memang tak pernah
mencatat apa pun di sini. Pencatatan kunjungan halaman biasa tetap jalan karena middleware-nya
terpasang global di [`bootstrap/app.php`](../../bootstrap/app.php).

**Cara mengangkat admin:** kolom `is_admin` default-nya `false` untuk semua akun, jadi tanpa
langkah ini tak seorang pun bisa membuka dashboard. Pakai artisan command
[`user:admin`](../../app/Console/Commands/MakeUserAdmin.php):

```bash
php artisan user:admin email@kamu.com            # jadikan admin
php artisan user:admin email@kamu.com --revoke   # cabut hak admin
```

Bagi admin, item **Monitoring** juga muncul di dropdown akun
([`NavItems::account()`](../../app/Support/NavItems.php)). Menyembunyikan link itu hanya
kemudahan navigasi — pengamanan sebenarnya ada di middleware, bukan di menu.

### 3.7 `loadMissing('user')` di view (bukan patch ke vendor)

Controller bawaan paket mem-paginate **tanpa** `with('user')`, padahal
[`AppServiceProvider`](../../app/Providers/AppServiceProvider.php) mengaktifkan
`Model::preventLazyLoading()` di luar produksi. Begitu tabelnya berisi data, kolom nama
pengguna di view memicu **`LazyLoadingViolationException`** — halaman gagal dibuka total.

**Solusi:** ketiga view memanggil `loadMissing('user')` pada paginator sebelum loop. View-nya
memang sudah di-publish ke repo ini, jadi perbaikannya di sisi kita dan `vendor/` tetap bersih.

**Catatan untuk penulis test:** Laravel **mengecualikan** hasil query berisi **satu model**
dari guard ini (satu query tambahan bukan N+1). Jadi fixture satu baris akan lolos walau
bug-nya masih ada — test regresinya sengaja memasukkan **dua baris** per tabel.

## 4. Tabel Database

> **Catatan privasi:** kolom `ip` menyimpan alamat yang **sudah dianonimkan** (oktet terakhir
> IPv4 dinolkan, IPv6 disisakan prefix /48) lewat
> [`AnonymizeClientIp`](../../app/Http/Middleware/AnonymizeClientIp.php). IP penuh adalah data
> pribadi menurut UU PDP & GDPR; log tetap berguna mengenali pola per jaringan tanpa menunjuk
> satu perangkat. Detail: [`anti-cheat-wpm.md`](anti-cheat-wpm.md) §10.4.

| Tabel | Isi |
|---|---|
| `visits_monitoring` | user_id, browser, platform, device, ip (dianonimkan), page |
| `actions_monitoring` | user_id, aksi (store/update/destroy), model terkait |
| `authentications_monitoring` | user_id, tipe (login/logout) |

Semua bertanggal migrasi `2026_04_28_070001-070004` (setelah `users`).

## 5. Konfigurasi Penting

Di [`config/user-monitoring.php`](../../config/user-monitoring.php):

- `user.display_attribute => 'username'` (lihat §3.2)
- `action_monitoring.on_read => false` (lihat §3.3)
- `visit_monitoring.turn_on`, `guest_mode`, `except_pages` — kontrol pencatatan kunjungan.
- `visit_monitoring.delete_days` — hapus otomatis log lama (0 = nonaktif; butuh scheduler).

## 6. Integrasi dengan Fitur Lain

- **Autentikasi** ([auth.md](auth.md)) — login/logout (termasuk Google & dev-login) tercatat
  di authentication monitoring.
- **Chat** ([chat.md](chat.md)), **Clan** ([clans.md](clans.md)), **Multiplayer**
  ([multiplayer-race.md](multiplayer-race.md)) — aksi create/update/delete pada
  `Message`/`Clan`/`Room` tercatat di action monitoring.

## 7. Catatan Operasional

- Dashboard butuh akun **admin** (`is_admin = true`), bukan sekadar login — non-admin dan guest
  sama-sama dapat **404** (lihat §3.6). Untuk uji lokal: `/dev-login`, lalu
  `php artisan user:admin <email>`.
- Kalau paket ini di-*reinstall*/update via composer, cukup pastikan
  `dont-discover` di `composer.json` tetap ada dan provider kustom tetap terdaftar di
  [`bootstrap/providers.php`](../../bootstrap/providers.php) — migrasi tak perlu diulang.
