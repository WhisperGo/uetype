# Fitur 13 — Monitoring Aktivitas Pengguna

**Paket:** [`binafy/laravel-user-monitoring`](https://github.com/binafy/laravel-user-monitoring)
**Provider kustom:** [`App\Providers\UserMonitoringServiceProvider`](../../app/Providers/UserMonitoringServiceProvider.php)
**Config:** [`config/user-monitoring.php`](../../config/user-monitoring.php)
**Middleware:** `VisitMonitoringMiddleware` (didaftarkan di [`bootstrap/app.php`](../../bootstrap/app.php))
**Dashboard:** `/user-monitoring/visits-monitoring`, `/actions-monitoring`, `/authentications-monitoring`

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

## 4. Tabel Database

| Tabel | Isi |
|---|---|
| `visits_monitoring` | user_id, browser, platform, device, ip, page |
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

- Dashboard butuh **login**. Untuk uji lokal cepat, pakai `/dev-login`.
- Kalau paket ini di-*reinstall*/update via composer, cukup pastikan
  `dont-discover` di `composer.json` tetap ada dan provider kustom tetap terdaftar di
  [`bootstrap/providers.php`](../../bootstrap/providers.php) — migrasi tak perlu diulang.
