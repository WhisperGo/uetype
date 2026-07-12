# Fitur 8 — Chat (DM & Clan)

**Komponen:** [`App\Livewire\Chat`](../../app/Livewire/Chat.php) — route `/chat`
**Endpoint kirim:** [`ChatController::send`](../../app/Http/Controllers/ChatController.php) — `POST /chat/send`
**Model:** [`Message`](../../app/Models/Message.php),
[`MessageDelete`](../../app/Models/MessageDelete.php),
[`MessageClear`](../../app/Models/MessageClear.php)
**Events:** `DirectMessageSent`, `ClanMessageSent`, `MessageEdited`, `MessageDeleted`

---

## 1. Apa Ini

Sistem pesan dengan dua mode:
- **DM** (`?mode=dm&with=<username>`) — percakapan pribadi antar dua teman.
- **Clan** (`?mode=clan`) — obrolan grup satu clan.

Fitur: kirim, **reply**, **edit inline** (dengan jendela waktu), **hapus** (untuk diri sendiri
atau untuk semua), **clear chat** (semua / lebih lama dari N hari), paginasi "load more".

## 2. Keputusan Desain & Justifikasi

### 2.1 Kirim pesan lewat endpoint HTTP terpisah, bukan Livewire action

```php
// routes/web.php
Route::post('/chat/send', [ChatController::class, 'send'])->name('chat.send');
```

**Justifikasi (dari komentar route):** kirim pesan lewat endpoint ringan **paralel, di luar antrean
Livewire**, supaya spam pesan tak saling menunggu. Livewire memproses request secara berurutan per
komponen; kalau kirim pesan cepat beruntun lewat action Livewire, tiap pesan mengantre di belakang
render sebelumnya → terasa lambat. Endpoint HTTP terpisah membuat pengiriman terasa instan.

### 2.2 Body pesan dikirim sebagai argumen agar input langsung dikosongkan

```php
public function sendMessage(?string $body = null): void {
    $body = trim($body ?? $this->body);
```

**Justifikasi:** UI bisa mengosongkan kotak input **segera** (optimistic) tanpa menunggu
round-trip server. `$body` opsional dengan fallback ke `$this->body` menjaga kompatibilitas.

### 2.3 Otorisasi & re-validasi di setiap aksi

- `sendDm` → cek target **benar-benar teman accepted**.
- `sendClanMessage` → cek user **punya clan aktif**.
- `resolveReplyTargetId` → pesan yang dibalas harus **milik percakapan yang sama** & boleh dilihat.
- `startEdit`/`saveEdit` → hanya **pengirim**, belum dihapus-untuk-semua, **dalam jendela edit**.

**Justifikasi:** trust boundary. `reply_to_id`, `message_id`, `username` semuanya berasal dari
client dan divalidasi ulang di server sebelum dipakai — mencegah membalas/mengedit/melihat pesan
di percakapan yang bukan hak user.

### 2.4 Delete: "untuk saya" vs "untuk semua" — tabel terpisah

- `MessageDelete` — mencatat siapa menyembunyikan pesan (delete-for-me, per user).
- Flag delete-for-everyone pada pesan itu sendiri.

**Justifikasi:** "hapus untuk saya" tak boleh menghapus pesan bagi lawan bicara — jadi ia dicatat
sebagai baris per-user, bukan menghapus row `Message`. "Hapus untuk semua" barulah mengubah pesan
jadi tombstone bagi semua. Memisahkan keduanya menjaga integritas riwayat masing-masing pihak.

### 2.5 Clear chat dengan cakupan (`all` / `days`) via `MessageClear`

**Justifikasi:** "bersihkan chat" juga bersifat **per-user** (tak menghapus bagi lawan). Menyimpan
titik clear (bukan menghapus pesan) berarti riwayat tetap ada untuk pihak lain, dan opsi "lebih
lama dari N hari" memberi kontrol tanpa kehilangan pesan terbaru.

### 2.6 State navigasi di query string (`?mode=&with=`)

**Justifikasi:** percakapan bisa **dibagikan/di-bookmark** (mis. buka DM dengan user tertentu
langsung dari URL), dan refresh tak kehilangan konteks.

### 2.7 Body listener kosong memicu re-render

Sama seperti Friends/Clans: `#[On('message-received')]` kosong — menerima event sudah cukup untuk
menyegarkan daftar pesan. Lihat [friends-presence.md](friends-presence.md#36).

## 3. Batas & Guard

- Body maksimal **2000 karakter** (dicek server).
- Paginasi manual `PAGE_SIZE = 30` dengan offset `loadedOlder` untuk scroll-ke-atas — menghindari
  memuat seluruh riwayat sekaligus.
