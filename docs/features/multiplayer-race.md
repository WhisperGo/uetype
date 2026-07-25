# Fitur 4 — Multiplayer Race (Real-time)

**Komponen:** [`App\Livewire\MultiplayerLobby`](../../app/Livewire/MultiplayerLobby.php)
(Volt route `multiplayer-lobby`)
**Model:** [`Room`](../../app/Models/Room.php), [`RoomMember`](../../app/Models/RoomMember.php)
**Events:** `RoomUpdated`, `RaceProgressUpdated`, `SuddenDeathTriggered`,
[`RoomMessageSent`](../../app/Events/RoomMessageSent.php),
[`RoomPresenceChanged`](../../app/Events/RoomPresenceChanged.php)
**View chat:** [`livewire/partials/room-chat.blade.php`](../../resources/views/livewire/partials/room-chat.blade.php)
**JS:** [`resources/js/race-echo.js`](../../resources/js/race-echo.js) (langganan Echo + komponen Alpine `roomChat`),
[`resources/js/multiplayer-nav.js`](../../resources/js/multiplayer-nav.js) (leave beacon + ready-confirm nav)
**Leave service:** [`App\Services\RoomMembershipService`](../../app/Services/RoomMembershipService.php),
[`MultiplayerPresenceController`](../../app/Http/Controllers/MultiplayerPresenceController.php)
**Route:** `/multiplayer` (+ `POST /multiplayer/leave-beacon`, `POST /multiplayer/leave-confirm`)

---

## 1. Apa Ini

Balapan mengetik **real-time** untuk hingga **5 pemain** (plus hingga **5 penonton**) dalam satu
room. Alur: buat/gabung room (kode 6 karakter) → semua siap → host mulai → countdown 3-2-1 →
balapan → sudden death → hasil. Progres tiap pemain (maskot yang bergerak) tampil live ke semua
peserta. Sambil menunggu (dan di layar hasil) peserta bisa **mengobrol lewat chat room** dan
melihat **notifikasi saat ada yang masuk/keluar** — lihat §3.9.

## 2. State Machine Room

```
waiting  ──startRace()──►  racing  ──semua finish / sudden death──►  finished
   ▲                                                                     │
   └──────────────────────── playAgain() ────────────────────────────────┘
```

`step` di komponen (`choose` → `waiting` → `racing`) mengikuti `room.status`, disinkronkan lewat
event `RoomUpdated`.

## 3. Keputusan Desain & Justifikasi

### 3.1 Dua saluran broadcast berbeda: gerakan vs lifecycle

| Event | Isi | Kenapa dipisah |
|-------|-----|----------------|
| `RaceProgressUpdated` | posisi/WPM/akurasi maskot | payload ringan langsung ke Alpine store, **tanpa re-render Livewire** — gerakan maskot harus mulus |
| `RoomUpdated` | perubahan status/badge/modal | memicu **re-render Livewire** (perubahan struktural yang butuh HTML baru) |

**Justifikasi:** memisahkan "gerakan sering & ringan" dari "perubahan struktur jarang" mencegah
Livewire me-*morph* DOM tiap kali maskot bergeser (yang akan berat & bikin patah-patah).

### 3.2 Net WPM otoritatif dihitung server, `$liveWpm` client diabaikan

```php
// $liveWpm dari client SENGAJA tidak dipakai untuk angka resmi.
$correctChars = (int) round(($progressPercent / 100) * $textLength);
$wpmCheck = app(AntiCheatService::class)->check($correctChars, $correctChars, $durationSeconds);
```

**Justifikasi:** menyelaraskan multiplayer dengan mode solo — WPM tak bisa dipompa dengan ketik
ngasal. Progres hanya naik dari karakter benar, jadi angkanya otomatis "net". `$liveWpm` tetap
diterima di signature hanya demi kompatibilitas payload client lama. Lihat
[anti-cheat-wpm.md](anti-cheat-wpm.md) dan [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).

### 3.3 Countdown sinkron pakai durasi RELATIF, bukan jam server absolut

```php
// getRaceStartsInMsProperty(): sisa milidetik menuju start, dihitung SERVER saat render.
return (int) round((float) now()->diffInMilliseconds($room->race_starts_at, false));
```

**Justifikasi (penting & non-obvious):** membandingkan jam server absolut dengan `Date.now()`
klien akan **menghitung latensi jaringan sebagai selisih jam**, dan `toIso8601String()` memotong
milidetik (galat hingga 1 detik). Dengan mengirim **durasi relatif** ("hitung mundur sekian ms
sejak halaman ini diterima"), jam & zona waktu klien tak lagi relevan — semua klien countdown-nya
sinkron tanpa peduli seberapa akurat jam mereka.

### 3.4 Sudden Death (15 detik) setelah pemain pertama finish

Saat pemain pertama menyentuh 100%, `countdown_started_at` di-set dan `SuddenDeathTriggered`
disiarkan dengan **timestamp akhir yang sama** ke semua klien.

**Justifikasi:** race tak boleh menggantung menunggu pemain lambat/AFK selamanya. Sudden death
memberi jendela adil (15 detik) bagi yang tersisa untuk menyelesaikan, lalu race ditutup. Timestamp
akhir yang seragam menjaga countdown mundur sinkron di semua layar; server tetap gerbang final
lewat `checkSuddenDeath()` (idempoten meski dipicu beberapa klien).

### 3.5 Urutan menang (`place`) berdasarkan waktu selesai, bukan WPM

```php
->orderBy('finished_time_seconds', 'asc')
->orderBy('progress_percent', 'desc')
->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
```

**Justifikasi:** ini **balapan** — yang menang adalah yang **sampai finish duluan**, bukan yang
WPM-nya tertinggi. Tie-break: progres lebih jauh, lalu yang belum selesai ditaruh di belakang.
Pemain yang menyerah (`giveUp`) atau DNF diberi `finished_time_seconds = 999` sebagai penanda.

### 3.6 EXP diberikan sekali per pemain (`xp_earned` null-guard)

```php
if (is_null($member->xp_earned) && $member->user) { /* addExp lalu set xp_earned */ }
```

**Justifikasi:** `finalizeRace()` bisa terpanggil dari beberapa jalur (fast-path "semua finish",
`giveUp`, `checkSuddenDeath`). Guard `xp_earned IS NULL` membuat pemberian EXP **idempoten** —
aman dari double-award. EXP dihitung dengan rumus yang **sama persis** dengan solo (`User::addExp`).

**Validasi hasil (sejajar solo):** sebelum EXP & baris riwayat ditulis, tiap hasil dilewatkan
`isValidRaceResult()` → `AntiCheatService::rejectsRaceResult()`. Hasil yang **mustahil** (WPM di
atas batas manusia, char inkonsisten) atau **kosong** (join tapi tak pernah mengetik, `no_input`)
**ditolak**: tak masuk `multiplayer_match_history`, tak dapat EXP, dan ditandai
`result_recorded = false` supaya rata-rata WPM pemain tak tercemar. Finisher **lambat** dan **DNF
yang sempat mengetik** tetap dicatat (hasil sah, bukan curang). Pemain yang ditolak melihat badge
**"Tidak dihitung"** di tabel hasil. Detail aturan: [`anti-cheat-wpm.md`](anti-cheat-wpm.md) §5.2.

**Peringkat dihitung SETELAH validasi.** Dulu nomor juara diambil dari urutan baris
(`$index + 1`) sebelum validitas diketahui, jadi hasil yang ditolak tetap menempati podium —
dan **pemain jujur yang benar-benar menang tercatat juara 2 secara permanen**. Sekarang
validitas seluruh peserta dihitung lebih dulu; nomor peringkat hanya naik untuk hasil yang
lolos, hasil yang ditolak mendapat `place = null`, dan `player_count` hanya menghitung peserta
sah. Lihat [`anti-cheat-wpm.md`](anti-cheat-wpm.md) §8.2.

**Jalur live juga dijaga** (`updateRaceProgress`): progress **wajib naik** (tak boleh mundur),
**spectator tak boleh** mengirim progress sama sekali, dan laju yang mustahil (teleport ke 100%)
**ditolak di sana** — bukan cuma saat finalisasi — karena waktu selesai yang menentukan juara
tercatat pada saat progress masuk. Lihat [`anti-cheat-wpm.md`](anti-cheat-wpm.md) §8.

### 3.7 Ketahanan terhadap host keluar / room hilang

Beberapa lapis penjaga (`roomUpdated()`, `getRoomDataProperty()`, dan penjaga terakhir di
`render()`) mengembalikan pemain ke halaman "choose" kalau room-nya sudah tak ada.

**Justifikasi:** tanpa ini, pemain yang tersisa akan terjebak di `step='racing'` tanpa data →
semua blok view gagal syarat → **halaman kosong**. Ini pertahanan berlapis (*defense in depth*)
terhadap kondisi race yang tak terhindarkan di sistem real-time.

### 3.8 Broadcast dibungkus `SafeBroadcast` & `toOthers()`

- `SafeBroadcast::run(...)` — kegagalan Reverb tak menggagalkan aksi race.
- `->toOthers()` pada `startRace()` — host sudah masuk `racing` secara lokal; tanpa `toOthers()`
  host menerima `RoomUpdated`-nya sendiri → re-render di tengah countdown → arena di-morph →
  countdown Alpine mulai ulang dari awal.

**Justifikasi:** kedua hal ini menutup bug real-time yang halus namun terasa jelas oleh pemain
(countdown yang "melompat" atau race yang gagal karena WebSocket sesaat down).

### 3.9 Chat room & notifikasi kehadiran (broadcast-only)

Peserta room (pemain **maupun** penonton) bisa mengobrol saat menunggu di lobby dan di layar hasil
(untuk mengajak main lagi). Saat balapan (`racing`) panel chat **tidak dirender** agar fokus mengetik.

| Aspek | Keputusan | Justifikasi |
|-------|-----------|-------------|
| **Penyimpanan** | **Broadcast-only**, tidak masuk database | Obrolan lobby bersifat sesaat & ikut hilang saat room bubar. Tanpa tabel/migrasi. Pesan ditahan di **state Alpine** klien (`roomChat`), bukan model `Message`. |
| **Channel** | Numpang `room.{code}` yang **sudah** di-subscribe | Tak perlu channel baru — cukup tambah listener `.room.message` & `.room.presence` di `race-echo.js`. |
| **Method** | `sendRoomMessage(string $body)` di `MultiplayerLobby` | Volume chat lobby rendah, komponen Livewire sudah hidup di halaman → lebih sederhana daripada endpoint `fetch()` terpisah (beda dengan chat global, lihat [chat.md](chat.md)). |
| **Optimistic + `->toOthers()`** | Pengirim menampilkan pesannya sendiri secara lokal; siaran hanya ke peserta lain | Kalau pengirim ikut menerima siaran, pesannya akan **dobel**. |

**Alur kirim:** klik kirim → `roomChat.send()` append lokal (optimistic) → `$wire.sendRoomMessage(body)`
→ server validasi (harus anggota room, bukan saat `racing`, trim + maks 500 char) → broadcast
[`RoomMessageSent`](../../app/Events/RoomMessageSent.php) `->toOthers()` → Reverb → `.room.message`
→ window event → Alpine append + auto-scroll.

**Notifikasi kehadiran** ([`RoomPresenceChanged`](../../app/Events/RoomPresenceChanged.php), action
`'join'|'leave'|'kick'`):
- **Join** disiarkan di `joinRoom()` dengan `->toOthers()` (yang masuk tak melihat notif dirinya sendiri).
- **Leave** disiarkan di `leaveRoom()` **sebelum** `RoomMember` dihapus (agar username masih terbaca),
  dan **hanya jika room masih punya anggota** — kalau anggota terakhir keluar, room dihapus sehingga
  notif tak perlu (tak ada yang mendengarkan).
- **Kick** disiarkan di `kickMember()` (lihat §3.11).
- Ditampilkan sebagai **pesan sistem di tengah** panel chat (pil samar `bg-white/[0.03]` +
  `text-muted/60`), dibedakan dari bubble chat biasa lewat flag `msg.system` di komponen Alpine.
  Label per-action (`chat_joined`/`chat_left`/`chat_kicked`) dipetakan di `race-echo.js`.

**Konsistensi visual:** bubble, input, tombol send, dan scrollbar (`chat-scroll`) disamakan dengan
halaman chat global — pesan sendiri = bubble emas (`bg-gold`), pesan orang lain = `bg-white/5`.
Namun karena broadcast-only (state Alpine, bukan objek `Message` dari DB), kelas visualnya **disalin**
dari [`components/chat/message.blade.php`](../../resources/views/components/chat/message.blade.php)
& [`components/chat/composer.blade.php`](../../resources/views/components/chat/composer.blade.php),
bukan me-reuse komponen tersebut (keduanya menerima objek pesan dari database).

**Batasan yang disengaja:** karena tak disimpan, **player yang baru join tak melihat riwayat**
pesan sebelumnya, dan chat di layar hasil adalah **instance terpisah** dari chat lobby (state Alpine
baru, `wire:key` berbeda) sehingga pesan lobby tak terbawa ke layar hasil.

### 3.10 Penonton (spectator) — kapasitas & luapan otomatis

Room memisahkan **pembalap** (`ROLE_PLAYER`) dan **penonton** (`ROLE_SPECTATOR`), masing-masing
dibatasi konstanta `MAX_PLAYERS = 5` / `MAX_SPECTATORS = 5`.

- **Luapan otomatis saat join:** pemain ke-6+ (slot pembalap penuh) **tidak ditolak**, melainkan
  otomatis masuk sebagai penonton. Room baru dianggap benar-benar penuh hanya kalau **kedua** kuota
  habis (5 pembalap + 5 penonton).
- **Pindah peran:** `toggleSpectator()` menukar pembalap ⇄ penonton, **hanya saat `waiting`**,
  dengan guard kapasitas per sisi. Host boleh jadi penonton (`host_id` terpisah dari `role`): ia
  tetap pemegang tombol "Mulai Balapan" tanpa ikut membalap.
- **Penonton tak menahan penutupan race:** deteksi "semua finish" & pemberian `place` hanya
  menghitung `ROLE_PLAYER`; penonton tak punya `finished_time_seconds` dan bukan DNF.
- **Reassign host** saat host keluar mengutamakan pembalap yang tersisa; hanya jika tak ada
  pembalap, penonton menjadi host-penonton.

### 3.11 Host kick member (di lobby)

Host bisa **mengeluarkan** anggota lain saat masih `waiting` — mengatasi kasus pemain yang tak
kunjung menekan **Ready** sehingga race tak bisa dimulai.

| Aspek | Keputusan | Justifikasi |
|-------|-----------|-------------|
| **Siapa** | Hanya **host**, dan **bukan dirinya sendiri** | Guard di `kickMember()`: `host_id === Auth::id()` + `userId !== Auth::id()`. Otorisasi di server, bukan sekadar menyembunyikan tombol. |
| **Kapan** | Hanya saat `status === 'waiting'` | Mengeluarkan pembalap **di tengah race** akan merusak akuntansi finish/placement. Diblokir setelah `racing` dimulai. |
| **Sasaran** | Pemain **maupun** penonton (keduanya menahan slot) | Tombol X muncul di kartu pemain dan di daftar penonton (keduanya non-host). |
| **UI** | Lingkaran "X" merah samar di sudut kartu | Tombol (hanya dirender kalau `$this->isHost`) memanggil Alpine `askKick(id, name)` yang menyimpan sasaran lalu membuka **overlay konfirmasi** `<x-modal name="confirm-kick-member">` — bukan dialog `confirm()`/`wire:confirm` native, konsisten dengan overlay leave/sign-out. Tombol Keluarkan → `confirmKick()` → `$wire.kickMember(id)`. |
| **Notif** | `RoomPresenceChanged` action `'kick'` → "… dikeluarkan dari ruang" di chat | Sama seperti join/leave, pesan sistem di tengah panel chat. |

**Bagaimana pemain yang di-kick tahu:** baris `RoomMember`-nya dihapus, lalu `RoomUpdated`
disiarkan **ke semua** (bukan `->toOthers()` — host juga perlu re-render agar kartu yang di-kick
langsung hilang). Di `roomUpdated()` ada penjaga: **kalau room masih ada tapi aku bukan lagi
anggotanya** (khusus fase `waiting`), kembali ke halaman choose dengan banner
`you_were_kicked`. Penjaga ini di-*scope* ke `waiting` saja supaya pemain yang sudah selesai lalu
keluar dan masih melihat **layar hasil** tak ikut terlempar dari hasilnya.

### 3.12 Persistensi keanggotaan saat navigasi (restore / auto-leave / leave-confirm)

Nav bar adalah **anchor biasa** (full page load, bukan `wire:navigate`) dan komponen tak punya
state klien yang bertahan — tapi baris `room_members` **bertahan di DB**. Tiga perilaku menjaga
agar keanggotaan konsisten dengan ekspektasi pemain:

| # | Perilaku | Mekanisme |
|---|----------|-----------|
| **Restore** | Buka/refresh `/multiplayer` saat masih anggota → **langsung masuk room** (tanpa kode) | `mount()` mencari `RoomMember` milik user, meng-set `$roomCode`/`$step` dari `room.status` (waiting/racing/finished→result), menurunkan `hasFinished`/`hasGivenUp` dari DB untuk refresh mid-race, dan `dispatch('subscribe-room')`. |
| **Auto-leave not-ready** | Member **not-ready non-host** yang meninggalkan halaman tanpa konfirmasi (tutup/refresh tab) → **keluar room** | Beacon `pagehide`/`beforeunload` ([`multiplayer-nav.js`](../../resources/js/multiplayer-nav.js)) → `POST /multiplayer/leave-beacon`. Server memutuskan: hanya not-ready non-host (& spectator) yang di-leave; **ready/host di-skip** agar row-nya bertahan untuk restore. Hanya saat `waiting`. |
| **Leave-confirm (overlay)** | **Semua** member (ready maupun tidak) klik nav ke halaman lain → **overlay konfirmasi** | Interceptor klik fase-capture: kalau `data-mp-in-room="1"` dan tujuan bukan `/multiplayer`, cegah navigasi, simpan tujuan, dan buka overlay `<x-modal name="confirm-leave-room">` lewat event `open-modal`. Tombol **Keluar** → `window.__mpConfirmLeave()` → `POST /multiplayer/leave-confirm` (host: leave + reassign) lalu navigasi. **Tetap** → overlay tutup, tetap di room. |

**Overlay, bukan `confirm()` browser:** konfirmasi memakai komponen [`x-modal`](../../resources/views/components/modal.blade.php)
yang sama dengan sign-out — bukan dialog `confirm()` bawaan. Modal ada **di dalam** view lobby
(single root Livewire), jadi hanya termuat di `/multiplayer`. Interceptor global menyimpan URL
tujuan lalu memicu modal; tombol Keluar-nya memanggil fungsi yang di-*expose* interceptor.

**Kenapa `data-*` di root view, bukan `window.*` global:** flag (`data-mp-in-room/waiting`,
URL endpoint) dirender **server** sebagai atribut di elemen root lobby, jadi **ikut re-render tiap
morph Livewire** (selalu segar saat klik/unload) dan **otomatis inert di halaman lain** (elemen
`[data-mp-flags]` hanya ada di `/multiplayer`).

**Satu pintu leave:** `leaveRoom()` (komponen), beacon, dan confirm semua memanggil
[`RoomMembershipService::depart()`](../../app/Services/RoomMembershipService.php) — hapus row,
settle room (hapus kalau kosong / reassign host), broadcast `RoomPresenceChanged('leave')` +
`RoomUpdated`. Trait `ManagesRoomMembership` kini tipis, mendelegasikan ke service ini.

**Keputusan disederhanakan:** refresh not-ready **juga** ikut leave (tanpa grace-window/kolom
DB/cron — proyek tak punya scheduler). Konsekuensinya kecil (not-ready yang refresh join ulang);
ready/host tak tersentuh beacon jadi restore mereka selalu jalan. Mid-race dilindungi (guard
`status='waiting'`, sama seperti kick).

### 3.13 Pilihan bahasa ketikan (host-only, di dalam room)

Bahasa teks race (EN/ID) dipilih **di dalam waiting room**, di header sebelah kode room —
**bukan** di layar create. Hanya **host** yang melihat tombolnya; peserta lain melihat **badge
read-only** berisi bahasa room saat ini.

Bahasa disimpan di kolom `rooms.language` (migrasi `add_language_to_rooms_table`), bukan sekadar
properti komponen. Alasannya: harus **bertahan saat host refresh** dan **terlihat oleh joiner**;
properti per-klien tak bisa keduanya.

`setRaceLang()`:
- **Gerbang server-side**: hanya host, hanya saat `status='waiting'`. Panggilan non-host diabaikan
  walau UI sudah menyembunyikan tombolnya (pertahanan berlapis). Mengganti teks mid-race akan
  men-desync semua orang.
- Divalidasi lewat [`TypingLanguage::resolve()`](../../app/Support/TypingLanguage.php) (kode tak
  dikenal → default `en`, jadi payload client tak bisa menyelundup ke generator teks).
- **Regenerate teks** dalam bahasa baru + broadcast `RoomUpdated` supaya semua klien me-render
  teks & badge baru. Kalau bahasa sama dengan yang sekarang, tak ada regen/broadcast.

`createRoom()` menyemai `rooms.language` dari preferensi solo pemain
(`session('typing_preferences')['contentLang']`) supaya terasa berkelanjutan; host bisa
menggantinya di dalam room. `playAgain()` **mempertahankan** bahasa room untuk rematch.

**Kenapa host-only:** semua peserta mengetik **teks yang sama**, jadi bahasa adalah properti
room, bukan per-pemain.

## 4. Batasan Saat Ini

- WPM/place tersimpan di `room_members` tidak melalui jalur PB/leaderboard global — race adalah
  event sosial, bukan sumber rekor pribadi. Lihat diskusi di
  [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).
