# Fitur 7 — Teman & Presence (Online/Offline)

**Komponen:** [`App\Livewire\Friends`](../../app/Livewire/Friends.php) — route `/friends`
**Model:** [`Friendship`](../../app/Models/Friendship.php),
[`User`](../../app/Models/User.php) (logika presence)
**Presence:** [`PresenceController`](../../app/Http/Controllers/PresenceController.php),
event `PresenceUpdated`, `FriendshipUpdated`

---

## 1. Apa Ini

- **Teman**: kirim/terima/tolak/batalkan permintaan, hapus teman, cari user by username.
- **Presence**: titik status online/offline di daftar teman, diperbarui real-time.

## 2. Model Pertemanan

Satu baris `friendships` dengan `requester_id`, `addressee_id`, dan `status`
(`Pending`/`Accepted`). Relasi **tak berarah** untuk keperluan tampil — dicari ke dua arah lewat
`User::friendshipWith($otherId)`.

## 3. Keputusan Desain & Justifikasi

### 3.1 Satu baris per relasi, dicari dua arah

```php
// friendshipWith(): cari baris antara dua user ke arah mana pun.
->where(fn($q) => $q->where('requester_id', $me)->where('addressee_id', $other))
->orWhere(fn($q) => $q->where('requester_id', $other)->where('addressee_id', $me));
```

**Justifikasi:** menghindari **duplikat** (A→B dan B→A sebagai dua baris) dan menyederhanakan
pengecekan "apakah kita sudah berteman/ada permintaan". Arah disimpan hanya untuk membedakan
siapa pengirim (agar tombol yang tepat muncul: "batalkan" vs "terima").

### 3.2 Otorisasi ketat per aksi (siapa boleh apa)

- `acceptRequest`/`rejectRequest` → hanya **penerima** (`incomingPending`).
- `cancelRequest` → hanya **pengirim** yang masih pending.
- `removeFriend` → **salah satu pihak** dari relasi accepted.

**Justifikasi:** tiap method meng-*scope* query ke `Auth::id()` dengan peran yang benar, jadi user
tak bisa memanipulasi relasi orang lain dengan menebak `friendship_id`. Otorisasi ada di **server**,
bukan sekadar menyembunyikan tombol di UI.

### 3.3 Presence diturunkan dari `last_seen_at`, bukan flag boolean

```php
public function isOnline(): bool {
    return $this->last_seen_at?->gt(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS)) ?? false;
}
```

**Justifikasi (penting):** flag `is_online` boolean akan **nyangkut `true`** kalau browser tertutup
tanpa sempat mengirim event "offline" (crash, tab ditutup paksa, koneksi putus). Dengan menurunkan
status dari timestamp heartbeat terakhir, "online" otomatis kedaluwarsa sendiri — tak ada state
yang bisa macet.

### 3.4 Ambang online 60 detik untuk heartbeat ~30 detik

**Justifikasi:** klien ping tiap ~30 detik; ambang 60 detik memberi **toleransi satu heartbeat
terlewat** sebelum dianggap offline. Ini menyeimbangkan responsivitas (cepat mendeteksi offline)
dengan ketahanan (tak berkedip offline karena satu ping tersendat).

**Konsumen kedua — sweep multiplayer:** sinyal `isOnline()`/`last_seen_at` yang sama juga dipakai
di luar daftar teman. Multiplayer memakainya untuk menyapu member room yang "nyangkut" (ready/host
yang menutup tab tanpa klik Leave): user yang offline = yang benar-benar pergi. Satu sinyal
presence, dua pemakai — lihat [multiplayer-race.md](multiplayer-race.md) §3.14.

**Ping berhenti saat tab tersembunyi, dan itu memang disengaja.** Jeda ini **presence**, bukan sesi:
tab latar tak boleh melaporkan pemiliknya online ke daftar teman. Jangan membuangnya demi menjaga
sesi tetap hidup — keaktifan sesi tak lagi bergantung padanya sejak `SESSION_LIFETIME` jadi 14 hari
dan sign-in diingat ([auth.md](auth.md) §3.6), jadi menukarnya hanya membayar titik online yang
akurat untuk sesuatu yang sudah beres di lapisan yang benar. Ping langsung saat `visibilitychange`
adalah yang memulihkan titiknya begitu tab kembali.

### 3.5 Broadcast presence hanya saat TRANSISI, bukan tiap heartbeat

```php
if (! $wasOnline) { $this->broadcastPresenceToFriends(); }
```

**Justifikasi:** heartbeat lanjutan (saat sudah online) hanya meng-update timestamp **tanpa
broadcast**. Kalau tiap ping menyiarkan ke semua teman, WebSocket akan **banjir pesan** tiap ~30
detik per user. Menyiarkan hanya pada transisi offline→online (dan online→offline saat logout)
menekan trafik drastis sambil tetap real-time secara efektif.

### 3.6 Body listener kosong — action apa pun memicu re-render

```php
#[On('friendship-updated')]
public function refreshFriends(): void { /* kosong */ }
```

**Justifikasi:** di Livewire, sekadar **menerima** event sudah memicu re-render komponen, yang
otomatis mengambil ulang computed properties (daftar teman terbaru). Tak perlu logika di dalam
listener — pola ini berulang di `Friends`, `Clans`, `Chat`.

### 3.7 Badge friend request di nav (titik indikator)

Menu akun di nav menampilkan **titik kecil bernuansa brand** saat ada permintaan pertemanan
**masuk** yang tertunda — di **trigger dropdown** (terlihat tanpa membuka menu) dan di sebelah item
**Friends** di dalam menu.

| Aspek | Keputusan | Justifikasi |
|-------|-----------|-------------|
| **Hitungan** | Hanya `Pending` di mana user adalah **addressee** (incoming), bukan yang dikirim | Badge menandai "ada yang perlu kamu respons"; request yang kamu kirim bukan urusan tindakan. |
| **Baseline** | Di-render **server** di `navigation.blade.php` (`$pendingFriendRequests`) | Akurat tepat saat load/`wire:navigate`, tanpa menunggu round-trip JS. |
| **Real-time** | Endpoint ringan [`GET /friends/pending-count`](../../app/Http/Controllers/FriendController.php) yang di-*fetch* oleh [`nav-badges.js`](../../resources/js/nav-badges.js) saat event `friendship-updated-remote` | Nav adalah **partial statis** (bukan Livewire), jadi tak bisa query ulang sendiri. Endpoint memastikan badge selalu akurat — **naik** saat request masuk, **turun** saat dibatalkan/diterima/ditolak. Penghitung optimistic (+1 saja) akan meleset pada cancel/reject. |
| **Tanpa langganan Echo baru** | Mendengar `friendship-updated-remote` yang **sudah** disiarkan `toasts.js` | Satu subscriber `friends.{id}` (lihat §3.6 & [chat.md](chat.md)); nav numpang event window yang sama, tak menambah listener Echo. |

### 3.8 Tombol kembali di profil publik mengikuti asal kunjungan

Profil publik ([`ProfileController::show`](../../app/Http/Controllers/ProfileController.php)) dibuka
dari **lima** tempat: roster clan, detail clan, Friends, Chat, dan Leaderboard. Panah kembalinya
dulu di-hardcode ke `route('friends.index')`, jadi membuka profil anggota clan lalu menekan kembali
melempar pengguna ke halaman yang tak pernah ia buka — dan posisinya di roster hilang.

Tujuannya kini diturunkan dari **`Referer`** di server, bukan parameter `?from=`, supaya tak ada
pemanggil yang perlu mengirim apa pun dan tautan yang dibagikan tetap berperilaku benar.

Logikanya tinggal di [`App\Support\BackLink`](../../app/Support/BackLink.php) dan kini dipakai
**tiga** halaman — profil, chat ([chat.md](chat.md) §2.6.a), dan jalan keluar tamu di `/login`
([auth.md](auth.md) §3.7). Sengaja generik (`from($request, $fallback, $except)`) alih-alih
menawarkan `forProfile()`/`forChat()`: fallback dan daftar pengecualian adalah **kebijakan** milik
tiap pemanggil, dan memindahkannya ke kelas Support akan membalik arah ketergantungan.

`Referer` dikendalikan klien, jadi dijaga dua hal:

| Penjaga | Justifikasi |
|---------|-------------|
| Host **harus** sama dengan host request | Tanpa ini, header dari luar akan diterima mentah ke `href` — setiap halaman profil jadi **open redirect** ke mana saja. |
| Path **tidak boleh** halaman profil (`/users/…`) | Melompat profil → profil akan membuat panah menunjuk ke profil yang baru saja ditinggalkan, bukan daftar tempat pengguna memulai. |

Kalau tak ada referer yang layak (kunjungan langsung, bookmark, header dibuang demi privasi),
jatuh ke Friends — panah selalu mengarah ke suatu tempat yang masuk akal.

**Di klien**, `href` hasil server tetap dipasang sebagai tautan asli (klik tengah, buka di tab baru,
dan render tanpa JS tetap jalan), sementara handler klik mendahulukan `history.back()` bila memang
ada riwayat — itu **memulihkan posisi scroll** halaman sebelumnya. Kembali ke roster 20 orang di
posisi paling atas, alih-alih di tempat yang sedang dibaca, adalah bentuk tersesat tersendiri.

> **Peningkatan ini tak bisa dipakai ulang di halaman tamu.** `layouts.guest` tak pernah memuat
> `@livewireScripts`, jadi Alpine tak menyala di sana dan `history.back()` akan jadi markup mati —
> lihat [auth.md](auth.md) §3.7. Di halaman itu `href`-nya adalah keseluruhan kontrolnya.

## 4. Real-time Aman

Semua broadcast presence/friendship lewat [`SafeBroadcast`](../../app/Support/SafeBroadcast.php) —
kegagalan Reverb tak menggagalkan aksi teman/logout.
