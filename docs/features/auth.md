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

### 3.4 Password acak untuk akun Google

```php
'password' => encrypt(Str::random(16)),
```

**Justifikasi:** kolom password tetap terisi (memenuhi constraint) meski akun Google tak pakainya —
mencegah login password kebetulan berhasil dengan nilai kosong/tebakan.

### 3.5 Profil publik dirujuk lewat username, bukan ID

```php
// routes/web.php
Route::get('/users/{user:username}', ...)->name('profile.show');
```

**Justifikasi (keamanan, dari komentar route):** memakai username di URL mencegah **enumerasi** —
ID user (dan jumlah total user terdaftar) tak bisa ditebak dengan mengubah angka di URL.

## 4. Validasi Username

`required, alpha_dash, min:3, max:20, unique:users,username` — alfanumerik/dash/underscore, panjang
wajar, unik. Pesan error di-*localize* (`auth.username.taken`, `auth.username.format`).

## 5. Dev Login (khusus lokal)

`/dev-login` & `/dev-login2` login instan sebagai user dummy — **hanya aktif di environment
`local`** (`app()->environment('local')`). Mempercepat pengujian multiplayer/chat tanpa OAuth. Aman
karena route ini tak pernah terdaftar di produksi.
