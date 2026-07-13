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

## 4. Overlay Global (Chat Bubble)

**Komponen:** [`App\Livewire\ChatOverlay`](../../app/Livewire/ChatOverlay.php) — mounted
sekali via `<livewire:chat-overlay />` di [`layouts/app.blade.php`](../../resources/views/layouts/app.blade.php),
di luar `{{ $slot }}`, sama seperti tiga toast global (`friendToasts`/`clanToasts`/`chatToasts`).

Drawer chat yang bisa dibuka dari halaman mana pun (tombol bulat kanan-bawah) untuk balas cepat,
tanpa meninggalkan halaman yang sedang dibuka. Halaman `/chat` penuh tetap ada untuk riwayat
panjang & browsing multi-percakapan — ini **penambahan**, bukan pengganti.

### 4.1 Kenapa komponen Livewire terpisah, bukan `Chat` yang di-mount dua kali

`Chat::$activeMode`/`$withUsername` adalah `#[Url]`-bound (justifikasi §2.6: shareable/bookmarkable).
Me-mount `Chat` sekali lagi sebagai overlay global akan membuat dua instance memperebutkan siapa
yang mengontrol query string halaman — terutama saat overlay dibuka di halaman `/chat` itu sendiri.
`ChatOverlay` adalah kelas terpisah dengan properti polos (tanpa `#[Url]`): membuka drawer di
halaman mana pun **tidak pernah** mengubah URL halaman itu. Konsekuensinya: thread yang dibuka
lewat overlay tidak bisa dibagikan/di-bookmark — untuk itu overlay selalu punya link
**"Buka penuh →"** yang mengarah ke `/chat?mode=...&with=...` (properti overlay yang sama).

### 4.2 Guard keamanan dipakai bersama lewat trait

Sebelum overlay ada, logika "siapa boleh DM siapa" sudah terduplikasi 2x (`Chat` dan
`ChatController`, alasan §2.1). Menambah overlay akan jadi salinan ke-3 — sebagai gantinya,
`isAcceptedFriend`/`canSeeMessage`/`markDmAsRead`/`sendDmMessage`/`sendClanMessageAs` diekstrak
ke [`GuardsChatAccess`](../../app/Livewire/Concerns/GuardsChatAccess.php), dipakai oleh `Chat`
dan `ChatOverlay`. `ChatController` tetap terpisah seperti sebelumnya (bukan kelas Livewire,
alasan latensi tak berubah).

### 4.3 Realtime: overlay tidak subscribe Echo sendiri

`chatToasts()` di layout tetap **satu-satunya** subscriber `chat.{id}`/`clan-chat.{id}`, dan
tetap me-relay lewat window `CustomEvent` (`message-received-remote`, `message-mutated-remote`).
`chat-overlay.blade.php` mendengarkan event relay yang sama — persis seperti `chat.blade.php` —
supaya tak ada listener Echo dobel atau toast dobel.

Fungsi window global overlay diberi nama **berbeda** dari milik halaman penuh
(`window.chatOverlaySend` vs `window.chatSend`, dst.) karena kedua script bisa sama-sama termuat
di halaman `/chat` sekaligus overlay aktif — nama yang sama akan saling menimpa `window.*` dan
salah satunya bisa memanggil `$wire` komponen yang salah.

### 4.4 Supresi toast: `window.__chatOverlayState`

`chatToasts()`'s `isViewingDm`/`isViewingClan` awalnya hanya mengecek `location.pathname`/`search`
(apakah halaman `/chat` sedang membuka thread ini). Diperluas dengan kondisi tambahan yang membaca
`window.__chatOverlayState = { open, mode, withUsername }` — ditulis oleh `chat-overlay.blade.php`
lewat `$wire.$watch(...)` tiap kali drawer dibuka/ditutup/ganti thread. Bukan `Alpine.store()`
(hanya dipakai sekali di seluruh proyek, page-scoped) — mengikuti idiom `window.*` global +
`window.__xRegistered` guard yang sudah dipakai toast-toast lain.

### 4.5 Cakupan: DM saja untuk badge unread

Tombol toggle overlay menampilkan badge `unreadCount` — hitungan yang sama dengan
`Chat::getTotalUnreadProperty()` (`Message::where('recipient_id', ...)->whereNull('read_at')`).
**Ini hanya mencakup DM.** Clan chat tidak punya kolom read-tracking per user sama sekali di
skema saat ini, jadi badge unread untuk pesan clan di luar cakupan — akan butuh migrasi baru
(mis. `clan_message_reads` atau `last_read_message_id` per `clan_members`) kalau suatu saat
diperlukan.

### 4.6 Full parity, ukuran ringkas

Overlay punya fitur yang sama dengan halaman penuh (reply, edit, delete-for-me/everyone,
clear-chat) — bukan versi terbatas. Yang dipangkas hanyalah **ukuran jendela**:
`OVERLAY_PAGE_SIZE = 15` (vs `Chat::PAGE_SIZE = 30`) dan picker kontak dibatasi 8 kontak
terbaru (`getRecentContactsProperty()`), karena drawer memang bukan tempat untuk browsing
riwayat panjang — itu tugas halaman `/chat`.

### 4.7 Sembunyi saat sesi test/balapan aktif

Tombol chat (dan drawer) **disembunyikan tepat saat user sedang mengetik atau balapan**, supaya
tak menutupi area ketik atau memecah fokus — lalu muncul lagi saat sesi selesai. Sinyalnya adalah
window event **`test-activity`** dengan `{ active: bool }`:

- **Solo** ([`typing-engine.blade.php`](../../resources/views/livewire/typing-engine.blade.php)):
  `active:true` saat keystroke pertama (`isStarted` jadi true), `active:false` di `finish()` dan
  di `resetProgress()` (restart/ganti mode di tengah sesi).
- **Multiplayer** ([`multiplayer-lobby.blade.php`](../../resources/views/livewire/multiplayer-lobby.blade.php)):
  blok arena (`step === 'racing'`, mencakup countdown 3-2-1) punya `x-data` kecil yang emit
  `active:true` di `init()` dan `active:false` di `destroy()` — jadi otomatis kembali saat race
  selesai / result modal muncul / keluar room.

`chatOverlayDock` mendengarkan `test-activity` → set `hidden` + tutup drawer. Karena overlay
bertahan lintas `wire:navigate`, ada juga listener `livewire:navigated` yang me-reset `hidden`
(sesi test halaman lama sudah berakhir begitu pindah halaman). Sinyalnya berbasis **aktivitas
nyata**, bukan sekadar route — jadi di halaman `/typing` yang masih idle (belum mengetik) tombol
tetap tampil.

### 4.8 Bubble bisa digeser (drag) dengan clamp ke layar

Tombol chat bisa dipindah lewat drag (`chatOverlayDock`, pointer events). Aturannya:

- Posisi disimpan di `window.__chatOverlayPos` supaya **tak lompat balik ke sudut** saat
  `wire:navigate`.
- `clampToViewport()` menjaga bubble **selalu utuh di dalam layar** (min `MARGIN=20px` dari tiap
  tepi), dijalankan ulang saat drag maupun `resize`.
- Drawer (`panelStyle()`) menempel ke posisi bubble lalu ikut di-clamp: buka ke atas kalau ruang
  bawah kurang, geser kiri kalau mepet kanan — **kotak chat tak pernah melewati batas layar**.
- `dragged` membedakan klik (buka/tutup) vs geser (>4px) supaya drag tak sengaja men-toggle drawer.
