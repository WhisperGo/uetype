# Fitur 11 — Autentikasi (Google + Pilih Username)

**Controller:** [`GoogleAuthController`](../../app/Http/Controllers/GoogleAuthController.php)
**Route:** `/login`, `/auth/google`, `/auth/google/callback`, `/auth/google/username`, `/logout` (semua di [`routes/web.php`](../../routes/web.php))

---

## 1. Apa Ini

Autentikasi **hanya via Google OAuth** (Laravel Socialite) — tak ada login/register email-password
(scaffold auth Breeze sudah dilepas; paketnya masih terpasang, tapi rute email/registrasinya tidak
dipakai). Karena game butuh **username unik** (untuk profil publik, leaderboard, mention), pendaftar
Google diarahkan memilih username sebelum akunnya dibuat.

## 2. Alur Google Auth

```
/auth/google ──► Google ──► /auth/google/callback
                                   │
              ┌── user sudah ada ──┴── user baru ──┐
              ▼                                     ▼
        login, ke /typing              simpan data Google ke SESSION
                                       (belum ke DB), ke form username
                                                     │
                                       /auth/google/username (POST)
                                                     │
                                       validasi username unik → buat user → login
```

## 3. Keputusan Desain & Justifikasi

### 3.1 User baru TIDAK langsung disimpan ke DB — data Google dititipkan di session dulu

```php
// Akun belum ada: jangan simpan ke database dulu, titipkan data Google ke session.
$request->session()->put('google_register_data', [...]);
return redirect()->route('auth.google.choose-username');
```

**Justifikasi:** username wajib & unik, tapi Google tak menyediakannya. Kalau user langsung dibuat
saat callback, kita akan punya baris user **tanpa username** (atau username auto yang jelek) yang
menggantung kalau pemilihan dibatalkan. Menunda insert sampai username dipilih menjaga tabel `users`
**bersih** — tak ada akun setengah jadi.

### 3.2 Cek ulang existing user sebelum insert (`storeUsername`)

```php
// Cek ulang database sebelum insert, untuk mencegah duplicate entry.
$existingUser = User::where('google_id', ...)->orWhere('email', ...)->first();
if ($existingUser) { login; return; }
```

**Justifikasi:** antara callback dan submit username bisa terjadi race (mis. dua tab). Cek ulang
mencegah **duplicate entry** dan menangani kasus user ternyata sudah terdaftar — langsung login
alih-alih error.

### 3.3 Sinkronisasi `google_id` untuk akun email yang sudah ada

```php
if ($user && !$user->google_id) { $user->update(['google_id' => ..., 'avatar' => ...]); }
```

**Justifikasi:** user yang dulu daftar via email lalu login via Google (email sama) di-**link**
otomatis — akunnya tidak terduplikasi. Ini pengalaman yang mulus: satu identitas email = satu akun,
apa pun metode loginnya.

### 3.4 Tak ada kolom `password` sama sekali

Dokumen ini dulu menjelaskan "password acak untuk akun Google" (`encrypt(Str::random(16))`). Itu
**tak pernah ada di kode yang ter-ship**: migrasi `users` tak punya kolom `password`, dan
`config/auth.php` mencatat bahwa broker password beserta `password_timeout` sengaja dibuang bersama
scaffold Breeze. Identitas bersandar sepenuhnya pada `google_id`.

Satu konsekuensi yang perlu diketahui: `Authenticatable::getAuthPassword()` akan mengembalikan
`null`, dan `SessionGuard::queueRecallerCookie()` menyuapkannya ke `hash_hmac()` — `$data` null
sudah deprecated di PHP 8.1+, yang berarti satu deprecation di **tiap** sign-in sejak remember-me
menyala (§3.6). Karena itu [`User`](../../app/Models/User.php) meng-override-nya jadi `''`.
Nilainya tak pernah dibaca ulang: recaller divalidasi dari `id` + `remember_token` saja
(`EloquentUserProvider::retrieveByToken`).

### 3.5 Profil publik dirujuk lewat username, bukan ID

```php
// routes/web.php
Route::get('/users/{user:username}', ...)->name('profile.show');
```

**Justifikasi (keamanan, dari komentar route):** memakai username di URL mencegah **enumerasi** —
ID user (dan jumlah total user terdaftar) tak bisa ditebak dengan mengubah angka di URL.

### 3.6 "Tetap masuk sampai sign out" — remember-me menyala

`loginAndRegenerate()` dulu memanggil `Auth::login($user)` **tanpa argumen kedua**, di ketiga jalur
login. Kolom `remember_token` sudah ada sejak migrasi pertama tapi tak pernah sekali pun diisi, jadi
cookie sesi mati saat browser ditutup dan server membuangnya setelah idle — tak ada apa pun yang
bisa memulihkan login. Itulah keluhan "kok sering ter-logout" yang dilaporkan pemain.

**Justifikasi:** Google adalah satu-satunya jalur masuk dan tak ada kolom password, jadi sesi yang
kedaluwarsa **tak membeli keamanan apa pun** — ia hanya membebani pemilik sah dengan satu putaran
OAuth. Sekarang `Auth::login($user, remember: true)`, ditemani `SESSION_LIFETIME` 14 hari
(`config/session.php`; karena `expire_on_close` false, angka itu juga jadi `Max-Age` cookie-nya).

**Sign out tetap benar-benar keluar.** `SessionGuard::logout()` memanggil `cycleRememberToken()`
sehingga recaller lama tak lagi cocok dengan barisnya, **dan** `clearUserDataFromStorage()` mengantre
`forget()` untuk cookie-nya. Berlaku untuk tombol Sign Out maupun `Settings::deleteAccount()`.

Efek sampingan yang menguntungkan: hook 419 di `layouts/app.blade.php` yang me-reload halaman kini
berubah dari "menendangmu diam-diam ke /login" jadi "memperbaikimu diam-diam" — request hasil reload
membawa recaller yang sah dan mendarat di URL yang sama dalam keadaan masuk.

### 3.7 Jalan keluar berlabel di halaman tamu

`/login` dan `/auth/google/username` memakai `layouts.guest`, yang **tak memuat `layouts.navigation`**
— padahal nav itu punya cabang `@else` khusus tamu yang berfungsi penuh. Akibatnya tamu yang berubah
pikiran hanya bisa keluar lewat tombol back browser.

Sekarang `<x-guest-layout :back-url="...">` merender `<x-header-link back>`. Tiga hal yang mudah
salah:

1. **`<a>` polos, tanpa Alpine.** Layout tamu tak pernah memuat `@livewireScripts`, dan
   `resources/js/app.js` mendaftarkan tiap komponen Alpine di listener `alpine:init` yang karena itu
   tak pernah menyala di sini. Pola `history.back()` milik panah profil akan jadi **markup mati** —
   jangan "diseragamkan".
2. **Asal diingat di session (`auth_origin`), bukan dari Referer saja.** Referer tak selamat dari
   perjalanan ke Google (jadi `accounts.google.com`) maupun dari Cancel di halaman username.
3. **Bukan `url.intended`.** Itu menyimpan ke mana tamu *hendak pergi* — halaman ber-`auth` yang
   memantulkannya ke `/login`. Mengirimnya "kembali" ke sana hanya memantulkannya balik.

Sekalian: `showLogin()` membersihkan `google_register_data`. Sampai di halaman sign-in berarti tak
sedang di tengah registrasi; tanpa ini, Cancel meninggalkan payload Google basi dan
`/auth/google/username` masih bisa dibuka untuk pendaftaran yang tak akan diselesaikan siapa pun.

### 3.8 `intended()` di **kedua** cabang `storeUsername`

Jalur user lama di `callback()` sudah benar memakai `redirect()->intended('/typing')`, tapi kedua
cabang `storeUsername()` memakai `redirect('/typing')` hardcoded. Tamu yang ditolak dari `/clans`
lalu mendaftar **justru untuk sampai ke sana** malah mendarat di `/typing`.

`session()->regenerate()` **memigrasikan** data sesi (bukan membuangnya), jadi `url.intended`
selamat melewati `loginAndRegenerate()`.

### 3.9 Kegagalan OAuth akhirnya terlihat

`callback()` sudah mem-flash `->with('error', __('auth.google_failed'))` sejak controller ini
ditulis, tapi satu-satunya perender `session('error')` di seluruh aplikasi adalah lobi multiplayer —
jadi login Google yang gagal memantulkan tamu ke kartu **kosong** tanpa satu pun petunjuk, dan
kesalahan konfigurasi tak bisa dibedakan dari user yang membatalkan consent. Markup-nya kini jadi
[`<x-session-error>`](../../resources/views/components/session-error.blade.php), dipakai kedua
halaman.

## 4. Validasi Username

`required, alpha_dash, min:3, max:20, unique:users,username` — alfanumerik/dash/underscore, panjang
wajar, unik. Pesan error di-*localize* (`auth.username.taken`, `auth.username.format`).

## 5. Dev Login (khusus lokal)

`/dev-login` login instan sebagai user dummy — **hanya aktif di environment `local`**
(`app()->environment('local')`). Default-nya masuk sebagai `dummy@uetype.test`; tambahkan
`?email=...` untuk masuk sebagai user lain (mis. `/dev-login?email=leaderboard@uetype.test` untuk
akun demo leaderboard — lihat [anti-cheat-wpm.md](anti-cheat-wpm.md) §11.5). Mempercepat pengujian
multiplayer/chat tanpa OAuth. Aman karena route ini **tak pernah terdaftar di produksi** — itulah
kenapa `/dev-login` mengembalikan **404** di `uetype.site`.

Kalau email yang diminta tak ada, route mengembalikan 404 dengan pesan yang menyarankan
`php artisan db:seed --class=DummyUserSeeder`.

Route ini dulu memanggil `Auth::login($user)` telanjang — melanggar aturan yang ditulis docblock
`loginAndRegenerate()` sendiri ("every login path must go through here"), termasuk melewatkan
`session()->regenerate()`. Kini ia merotasi sesi dan memakai `remember: true` seperti jalur asli:
sebuah pintasan dev tetap sebuah jalur login, dan pintasan yang diam-diam beda perilakunya persis
cara aturan semacam itu terkikis.
