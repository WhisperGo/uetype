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

## 4. Real-time Aman

Semua broadcast presence/friendship lewat [`SafeBroadcast`](../../app/Support/SafeBroadcast.php) —
kegagalan Reverb tak menggagalkan aksi teman/logout.
