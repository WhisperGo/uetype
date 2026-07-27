# Fitur 4 — Multiplayer Race (Real-time)

**Komponen:** [`App\Livewire\MultiplayerLobby`](../../app/Livewire/MultiplayerLobby.php)
(didaftarkan lewat `Volt::route('/multiplayer', 'multiplayer-lobby')`, tapi
[`multiplayer-lobby.blade.php`](../../resources/views/livewire/multiplayer-lobby.blade.php)
adalah **view biasa** yang di-*backing* kelas di atas — bukan komponen Volt fungsional)
**Model:** [`Room`](../../app/Models/Room.php), [`RoomMember`](../../app/Models/RoomMember.php)
**Events:** `RoomUpdated`, `RaceProgressUpdated`, `SuddenDeathTriggered`,
[`RoomMessageSent`](../../app/Events/RoomMessageSent.php),
[`RoomPresenceChanged`](../../app/Events/RoomPresenceChanged.php),
[`RoomInvitationSent`](../../app/Events/RoomInvitationSent.php)
**View chat:** [`livewire/partials/room-chat.blade.php`](../../resources/views/livewire/partials/room-chat.blade.php)
**Overlay undangan:** [`components/room-invite-overlay.blade.php`](../../resources/views/components/room-invite-overlay.blade.php)
**JS:** [`resources/js/race-echo.js`](../../resources/js/race-echo.js) (langganan Echo + komponen Alpine `roomChat`),
[`resources/js/multiplayer-nav.js`](../../resources/js/multiplayer-nav.js) (leave beacon + ready-confirm nav)
**Membership/leave service:** [`App\Services\RoomMembershipService`](../../app/Services/RoomMembershipService.php)
(leave, settle, **sweep member basi**), [`MultiplayerPresenceController`](../../app/Http/Controllers/MultiplayerPresenceController.php)
**Route:** `/multiplayer` (+ `?invite=CODE` untuk deep-link undangan, `POST /multiplayer/leave-beacon`, `POST /multiplayer/leave-confirm`)

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
ngasal. `$liveWpm` tetap diterima di signature hanya demi kompatibilitas payload client lama.
Lihat [anti-cheat-wpm.md](anti-cheat-wpm.md) dan
[`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).

#### Presisi durasi: kenapa pemain jujur pernah ditolak (bug yang ditutup, 2026-07-27)

`finished_time_seconds` adalah kolom **integer**, ditulis `round($elapsed)`. Nilai tersimpan
karena itu bisa sampai **setengah detik lebih kecil** dari durasi asli — dan angka bulat itulah
yang jadi pembagi saat `isValidRaceResult()` menghitung ulang WPM.

Setengah detik tak berarti apa-apa di balapan 20 detik, dan menentukan di balapan 2 detik. Teks
10 kata (49 karakter) diselesaikan dalam **2,2 detik** tersimpan sebagai **2**: run yang
sebenarnya 267 WPM dinilai server **294 WPM**, melewati `MAX_RACE_WPM`, dan pemainnya diberi
tahu kecepatannya tidak manusiawi.

Yang paling menyesatkan, hasilnya **tidak monoton**: finis 2,6s (tersimpan 3) lolos sementara
finis 2,2s yang **lebih cepat** (tersimpan 2) ditolak. Pemain yang sama, pace yang sama,
verdict berbeda tergantung di sisi mana setengah detik itu jatuh — persis seperti undian.

**Perbaikannya** (`AntiCheatService::FINISH_DURATION_ROUNDING_SLACK`): penilaian kecepatan
memakai `durasi + 0.5`, yaitu durasi **paling lambat** yang mungkin, sehingga keraguan akibat
pembulatan kita sendiri berpihak ke pemain. Ambang efektif jadi ≥245 WPM asli di setiap panjang
teks — di atas rekor dunia berkelanjutan, jadi tak ada pengetik jujur yang tersentuh, sementara
pace palsu (600 karakter dalam 10 detik = 720 WPM) tetap ditolak telak.

Dua batasan yang perlu diketahui:

| Hal | Catatan |
|---|---|
| Slack **tidak** dipakai di jalur live (`exceedsRaceSpeed`) | Jalur itu menerima float sungguhan (`race_starts_at` → `now()`), jadi tak ada pembulatan untuk dikompensasi. Menambahkannya hanya melebarkan celah sembunyi payload palsu |
| Slack hanya menyentuh penilaian **kecepatan** | `no_input`, `char_count_inconsistent`, cross-check progres/akurasi, dan `duration_too_short` tetap membaca nilai tersimpan apa adanya |

> **Utang yang diketahui:** ini **kompensasi** presisi yang hilang, bukan pelonggaran ambang.
> WPM yang **ditampilkan** ke pemain masih diturunkan dari detik bulat, jadi angkanya masih bisa
> meleset pada balapan pendek — hanya penolakannya yang berhenti salah. Perbaikan sesungguhnya
> adalah menyimpan durasi dengan presisi sub-detik, yang menyentuh kolom `integer`, sentinel DNF
> `999`, urutan peringkat, tampilan `mm:ss`, dan riwayat permanen — pekerjaan tersendiri, bukan
> tempelan pada perbaikan bug. Kalau itu mendarat, konstanta slack-nya ikut dibuang.

#### Word-lock: apa yang membuat "progress = karakter benar" benar-benar berlaku

Rumus di atas berdiri di atas satu invarian: **progres hanya naik dari karakter benar.** Server
tak bisa memverifikasinya sendiri — ia menurunkan `correctChars` dari progres dan **tak pernah
melihat teks yang diketik**. Jadi invarian itu harus ditegakkan di client, dan itulah tugas
**word-lock** di [`handleSpace()`](../../resources/js/race-arena.js):

```js
// Kata tidak pernah lewat sampai diketik PERSIS benar.
if (this.typedText !== targetWord) {
    this.justBlocked = true;

    return;
}
```

**Kenapa ini perlu (bug yang ditutup, 2026-07-26):** dulu spasi memajukan kata **apa pun yang
diketik** — `correctCharsFromPastWords` bertambah sepanjang kata target tanpa memeriksa
kecocokan. Akibatnya berantai: progres melebih-lebihkan karakter benar → Net WPM "hasil hitung
ulang server" ikut salah → dan karena juara ditentukan **waktu selesai** (§3.5), mengetik
sebagian tiap kata **menyelesaikan balapan lebih cepat**. Untuk teks 45 kata bahasa Indonesia
(±286 karakter), pemain yang mengetik ~60% tiap kata selesai **1,5x lebih cepat** dengan akurasi
60% — masih di atas lantai `RACE_MIN_ACCURACY_AT_PROGRESS`, jadi hasilnya **lolos validasi** dan
tercatat permanen sebagai kemenangan sah 105 WPM. Spam mustahil (akurasi 0%) memang sudah
tertutup lantai itu; yang bocor justru pita 50–99% yang tak terlihat seperti kecurangan.

Tiga hal yang **sengaja tidak** diubah untuk memperbaikinya:

| Yang tidak diubah | Alasan |
|---|---|
| Urutan podium (§3.5) | Meranking dengan progres lebih dulu berarti yang menyentuh garis duluan bisa kalah — itu membunuh makna maskot & sudden death |
| Lantai akurasi (50%) | Menjadikan akurasi penentu kemenangan justru menciptakan insentif baru memalsukannya; ia tetap sekadar gerbang validitas |
| Sifat permisif mode solo | Di solo, melewati huruf hanya menurunkan WPM sendiri; di race ia mengalahkan lawan yang jujur. Beda taruhan, beda aturan |

**Batas yang jujur:** word-lock menutup **bug aturan main** — pemain jujur tak lagi menemukan
spam sebagai strategi optimal. Ia **bukan** batas keamanan: penegakannya di client, jadi payload
palsu tetap mungkin dan tetap ditahan gerbang server yang sudah ada (`MAX_RACE_WPM`, cross-check
akurasi/progres, progres monoton, gerbang countdown, rate limit) — lihat §3.6.

**Penolakan karakter saat diketik (lapisan lebih ketat).** Di atas word-lock tingkat-spasi,
`checkInput()` kini **menolak setiap karakter salah pada saat diketik**: begitu `typedText`
berhenti menjadi prefiks kata target, karakter pelanggar itu **langsung dibuang** dari field
(`typedText.slice(0, prevTypedLength)`) dan `nudgeBlocked()` dipicu. Artinya kata yang sedang
diketik **tak pernah bisa menampung huruf salah** sama sekali — satu-satunya jalan maju adalah
mengetik prefiks yang persis benar. Backspace tetap bebas (memperpendek `typedText` selalu valid),
dan **akurasi tetap jujur**: percobaan yang ditolak tetap dihitung `totalMistakes`, jadi angka
akurasi tak melonjak palsu ke 100%.

Konsekuensi UX yang disengaja: kata yang salah **tidak menjebak** — huruf salah cukup diabaikan
(tak masuk), dan biayanya hanya waktu. Penolakan (huruf salah maupun spasi yang belum sah) diberi
sinyal lewat flag `justBlocked`: **warna merah** pada kata aktif & input, plus baris
`multiplayer.word_must_match`. (Efek getar `race-typo` sebelumnya **sudah dilepas** — warna merah
jadi satu-satunya isyarat.) Karena karakter salah tak pernah masuk lagi, `hasError` praktis selalu
`false`; `justBlocked` yang menjadi pemicu utama warna merah, termasuk saat spasi ditolak padahal
ketikan masih prefiks benar (ketik `"the"` untuk `"then"` lalu spasi — layar tetap memberi sinyal
ditahan).

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

### 3.4 Sudden Death (15 detik) setelah pemain pertama finish **valid**

Saat pemain pertama menyentuh 100% **dengan hasil yang valid**, `countdown_started_at` di-set dan
`SuddenDeathTriggered` disiarkan dengan **timestamp akhir yang sama** ke semua klien.

**Justifikasi:** race tak boleh menggantung menunggu pemain lambat/AFK selamanya. Sudden death
memberi jendela adil (15 detik) bagi yang tersisa untuk menyelesaikan, lalu race ditutup.

**Yang disiarkan adalah SISA DETIK, bukan timestamp akhir.** Ini pelajaran yang sama dengan §3.3,
dan sempat tidak diterapkan di sini: klien dulu menghitung `new Date(endTimeIso) - Date.now()`,
sehingga **selisih jam klien terbaca sebagai selisih waktu**. Jam yang cepat ≥15 detik
menghasilkan sisa `0` → `lockRace()` → pemain **langsung dibekukan** dari balapan yang jatahnya
masih penuh; jam yang lambat justru memberi jendela jauh lebih panjang. `SuddenDeathTriggered`
kini membawa `remainingSeconds` (dihitung dengan accessor yang **sama** dengan render awal,
`getSuddenDeathRemainingProperty()`), dan `endTimeIso` hanya tersisa sebagai *fallback* untuk
bundle klien yang masih ter-cache — boleh dibuang setelah semua klien berganti.

**Server adalah gerbang, bukan pemicu.** `checkSuddenDeath()` idempoten dan memvalidasi ulang
15 detik itu server-side, tapi ia **hanya dipanggil oleh klien** (`lockRace()` di
`race-arena.js`). Artinya kalau seluruh tab di sebuah race tertutup, tak ada yang menutup
race-nya — celah itu ditangani sapuan room terbengkalai di §3.14.

**Gerbang validitas (penting):** `updateRaceProgress()` memvalidasi finish **sebelum** ia boleh
menyalakan sudden death atau mengambil `place`. Hasil yang akan ditolak finalisasi (fast-garbage:
progress tinggi + akurasi mustahil rendah, atau sesi kosong) **tidak** menyalakan timer — kalau
tidak, satu pemain yang men-spam bisa memotong race untuk pemain jujur yang masih mengetik, dan
menempati podium. Finisher tak valid **tetap** ditandai selesai (tak bisa lanjut balapan) tapi
`place = null`; timer baru menyala saat ada finisher **valid**. Aturan validitas yang dipakai sama
persis dengan finalisasi (`isValidRaceResult()` → `AntiCheatService::rejectsRaceResult()`), satu
definisi.

**Give up bukan finish:** menekan GIVE UP adalah konsesi (DNF), jadi **tidak** menyalakan sudden
death dan tak mengambil place — race tetap menunggu finisher valid. Placement hasil tak valid/DNF
ditampilkan sebagai **`—`** di layar hasil (via `place = null`, lihat `$rankLabel`).

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
`isValidRaceResult()`. Yang **ditolak** (tak masuk `multiplayer_match_history`, tak dapat EXP,
`result_recorded = false`):

- **Mustahil** — WPM di atas batas manusia, char inkonsisten, atau fast-garbage (progress tinggi
  + akurasi mustahil rendah).
- **Kosong** — join tapi tak pernah mengetik (`no_input`).
- **DNF** — **setiap** hasil DNF, baik menyerah (`giveUp`) maupun **AFK yang di-timeout** saat
  sudden death. DNF berarti tak menyelesaikan, jadi bukan hasil ketik sungguhan; WPM rendahnya
  akan menyeret rata-rata pemain kalau dicatat. (Dulu DNF-yang-sempat-mengetik masih dicatat —
  aturannya sekarang **DNF tak pernah dicatat**.)

Yang **tetap dicatat**: finisher **lambat** (WPM rendah nyata tapi benar-benar selesai). Pemain
yang ditolak melihat badge **"Tidak dihitung"** + banner alasan spesifik (mis. `reject_dnf` untuk
DNF). DNF tetap tampil di layar hasil dengan placement **`—`**. Detail aturan:
[`anti-cheat-wpm.md`](anti-cheat-wpm.md) §5.2.

**Peringkat dihitung SETELAH validasi.** Dulu nomor juara diambil dari urutan baris
(`$index + 1`) sebelum validitas diketahui, jadi hasil yang ditolak tetap menempati podium —
dan **pemain jujur yang benar-benar menang tercatat juara 2 secara permanen**. Sekarang
validitas seluruh peserta dihitung lebih dulu; nomor peringkat hanya naik untuk hasil yang
lolos, hasil yang ditolak mendapat `place = null`, dan `player_count` hanya menghitung peserta
sah. Lihat [`anti-cheat-wpm.md`](anti-cheat-wpm.md) §8.2.

**Jalur live juga dijaga** (`updateRaceProgress`): progress **wajib naik** (tak boleh mundur),
**spectator tak boleh** mengirim progress sama sekali, dan laju yang mustahil (teleport ke 100%,
plafon `MAX_RACE_WPM = 240`) **ditolak di sana** — bukan cuma saat finalisasi — karena waktu selesai
yang menentukan juara tercatat pada saat progress masuk. Ditambah dua penjaga *hardening*: progress
yang datang **selama countdown 3 detik** (`race_starts_at` belum lewat) ditolak, dan ada **rate-limit
server-side** (20 update/detik/pemain) agar jalur terpanas race tak bisa dibanjiri client yang
di-script. Lihat [`anti-cheat-wpm.md`](anti-cheat-wpm.md) §5.1 & §8.

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
| **Restore** | Buka/refresh `/multiplayer` saat masih anggota → **langsung masuk room** (tanpa kode); refresh **mid-race lanjut dari progress terakhir**, bukan mulai dari kata pertama | `mount()` mencari `RoomMember` milik user, meng-set `$roomCode`/`$step` dari `room.status` (waiting/racing/finished→result), menurunkan `hasFinished`/`hasGivenUp` dari DB untuk refresh mid-race, dan `dispatch('subscribe-room')`. Untuk racer aktif, `myResumeProgress` (= `progress_percent` tersimpan) diteruskan ke `raceArena`, yang di `init()` memanggil `restoreProgress()` untuk merekonstruksi `currentWordIndex` dari persen itu (server hanya menyimpan persen, bukan indeks kata). Word-lock lalu membiarkan pemain melanjutkan kata yang belum selesai. |
| **Auto-leave not-ready** | Member **not-ready non-host** yang meninggalkan halaman tanpa konfirmasi (tutup/refresh tab) → **keluar room** | Beacon `pagehide`/`beforeunload` ([`multiplayer-nav.js`](../../resources/js/multiplayer-nav.js)) → `POST /multiplayer/leave-beacon`. Server memutuskan: hanya not-ready non-host (& spectator) yang di-leave; **ready/host di-skip** agar row-nya bertahan untuk restore. Hanya saat `waiting`. |
| **Leave-confirm (overlay)** | **Semua** member (ready maupun tidak) klik nav ke halaman lain → **overlay konfirmasi** | Interceptor klik fase-capture: kalau `data-mp-in-room="1"` dan tujuan bukan `/multiplayer`, cegah navigasi, simpan tujuan, dan buka overlay `<x-modal name="confirm-leave-room">` lewat event `open-modal`. Tombol **Keluar** → `window.__mpConfirmLeave()` → `POST /multiplayer/leave-confirm` (host: leave + reassign) lalu navigasi. **Tetap** → overlay tutup, tetap di room. **Berlaku di semua status termasuk `racing`**: menekan Keluar adalah pilihan sengaja, jadi pemain benar-benar keluar (row dihapus) walau di tengah race — berbeda dari beacon (reload) yang justru **menahan** row mid-race untuk restore. |

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
ready/host tak tersentuh beacon jadi restore mereka selalu jalan.

**Beacon vs confirm saat `racing`** — dua jalur leave sengaja dibedakan berdasarkan **niat**:

- **Beacon** (`leave-beacon`, dipicu `pagehide`/`beforeunload`) mid-race **tak menyentuh** row: sebuah
  unload saat race adalah **reload**, dan row harus bertahan agar `mount()` bisa me-restore pemain
  di progress terakhirnya. (Beacon hanya me-leave saat `waiting`, itu pun hanya not-ready non-host.)
- **Confirm** (`leave-confirm`, dipicu tombol **Keluar** di overlay) mid-race **menghapus** row:
  menekan Keluar adalah pilihan **eksplisit**, jadi pemain benar-benar keluar apa pun statusnya.
  Placement pemain yang tersisa aman — `writeFinalStandings` hanya menghitung member yang **masih
  ada** saat finalisasi, jadi member yang lenyap tak mengganggu urutan.

**Celah yang tersisa — member "nyangkut":** beacon sengaja **menahan** ready/host agar bisa
di-restore. Tapi kalau mereka **tutup tab / koneksi putus / pergi** tanpa kembali, baris mereka
menggantung selamanya — menahan slot, dan (sebagai host) membuat room tak bisa dimulai/dibersihkan.
Ini ditutup oleh **sweep member basi**, lihat §3.14.

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

### 3.14 Sweep member "nyangkut" (lazy, pakai sinyal presence)

Menutup celah dari §3.12: member yang **di-keep** beacon (ready/host) tapi tak pernah kembali
(tutup tab, koneksi putus, pergi tanpa klik **Leave**) meninggalkan baris `room_members` yang
menggantung — menahan slot, dan sebagai host membuat room jadi "hantu" yang tak bisa dimulai atau
dibersihkan siapa pun.

[`RoomMembershipService::sweepOfflineMembers()`](../../app/Services/RoomMembershipService.php)
menyapunya:

| Aspek | Keputusan | Justifikasi |
|-------|-----------|-------------|
| **Sinyal deteksi** | User **offline** menurut `last_seen_at` (basi > `ONLINE_THRESHOLD_SECONDS` = 60 dtk, atau null setelah logout) | Numpang **heartbeat presence site-wide** yang sudah ada (lihat [friends-presence.md](friends-presence.md) §3.3–3.4). Heartbeat berhenti begitu tab ditutup/hidden — penanda "benar-benar pergi". Yang **menunggu diam-diam di lobby tetap ping**, jadi tak ikut tersapu. Jauh lebih akurat daripada menebak dari `updated_at`. **Tanpa kolom/endpoint/JS baru.** |
| **Kapan jalan** | **Lazy saat lobby di-load** (`mount()`), bukan cron | Pola sama seperti [`ClanWarResolver`](../../app/Services/ClanWarResolver.php) — proyek tak punya scheduler. Setiap ada yang membuka `/multiplayer`, room hantu ikut dibersihkan. |
| **Cakupan** | Room **`waiting`** per-member; room **`racing`** hanya kalau **seluruh** member hilang | Mencabut **satu** peserta di tengah race merusak placement, jadi satu pemain yang masih online melindungi seluruh room. Tapi kalau tak ada siapa-siapa lagi, race tak bisa menutup dirinya sendiri (§3.4: server gerbang, bukan pemicu) — room-nya **dihapus**, tanpa finalisasi, tanpa baris history, tanpa XP. Balapan yang tak diselesaikan siapa pun tak menghasilkan hasil yang layak disimpan, sejalan dengan aturan "DNF tak pernah dicatat". |
| **Host tersapu** | **Handoff** ke member tersisa (racer diprioritaskan), atau room dihapus kalau semua tersapu | Memakai ulang `settleAbandonedRoom()`/`reassignHostIfNeeded()` yang sama dengan leave/kick — satu definisi. Sisa member disiarkan `RoomUpdated` agar slot bebas/host baru langsung ter-render. |
| **Pemanggil dikecualikan** | `exceptUserId` = user yang halamannya baru load | Ia provably hadir; heartbeat-nya mungkin belum mendarat pada fresh load, jadi jangan sampai menyapu diri sendiri. |

**Catatan test:** `UserFactory` kini default **online** (`last_seen_at = now()`) karena akun uji
merepresentasikan user aktif; test yang butuh user absen memakai state `->offline()`. Test
presence yang peduli status sudah selalu men-set `last_seen_at` eksplisit, jadi tak terpengaruh.

### 3.15 Undang teman ke room (dari slot kosong)

Slot pemain yang kosong bisa **diklik** untuk mengundang teman. **Semua** member (bukan cuma host)
boleh mengundang — mengundang bersifat kolaboratif. Undangan tiba sebagai **kartu notifikasi
non-blocking di pojok kanan bawah** di halaman mana pun si teman berada.

**Alur:** klik slot kosong → modal daftar teman (online di atas + tombol **Undang**, offline
terlihat tapi disabled, yang sudah di room ditandai "Di ruang") → klik Undang →
`invitePlayer($friendId)` menyiarkan [`RoomInvitationSent`](../../app/Events/RoomInvitationSent.php)
→ teman menerima overlay Accept/Decline → **Accept** membuka `/multiplayer?invite=CODE`.

| Aspek | Keputusan | Justifikasi |
|-------|-----------|-------------|
| **Model masuk** | Undangan + teman klik **terima** (bukan menyeret paksa) | Teman bisa sedang serius typing test; menariknya masuk secara paksa akan mengganggu. Kartu non-blocking + tombol memberi mereka kendali. |
| **Channel** | Numpang `friends.{id}` yang **sudah** di-subscribe `toasts.js` | Tak perlu langganan Echo baru — `toasts.js` melempar `.room.invitation` sebagai window event `room-invite-received`, ditangkap [`room-invite-overlay`](../../resources/views/components/room-invite-overlay.blade.php). |
| **Posisi overlay** | Kartu pojok kanan bawah (`bottom-24`), **bukan** overlay layar penuh | Overlay tengah + backdrop menutupi area ketik & memaksa reaksi. Kartu pojok tak memblokir; `bottom-24` agar tak bertumpuk dengan toast stack. Auto-hilang ~15 dtk kalau diabaikan. |
| **Payload** | Nama + **avatar** + kode room | Kartu profil dirender klien tanpa round-trip ke server. |
| **Auto-join** | `mount()` membaca `request()->query('invite')` | Livewire hanya inject route param ke `mount()`, bukan query param — jadi dibaca eksplisit. `joinRoomByCode()` (di-extract dari `joinRoom()`) dipakai bersama form kode manual & deep-link. |
| **Anti-spam** | Rate-limit **10/menit per pengundang** (server) + cooldown **5 dtk per teman** (klien) | Undangan bisa jadi vektor spam toast. Cooldown klien: setelah mengundang, tombol teman itu menghitung mundur lalu bisa lagi — **tanpa refresh**. Rate-limit server = backstop sebenarnya. |
| **Penjaga** | Hanya room `waiting`, hanya **teman Accepted** (bukan ID acak), bukan yang sudah di room, bukan diri sendiri | Otorisasi di server, bukan sekadar menyembunyikan tombol. |

### 3.16 Mengetik lewat keyboard layar (HP)

**JS:** [`race-arena.js`](../../resources/js/race-arena.js) (`handleSpace`, `advanceWord`,
`checkInput`) · **Test:** [`RaceMobileInputTest`](../../tests/Feature/RaceMobileInputTest.php)

Sampai 2026-07-26 balapan praktis **tak bisa dimainkan di HP**, karena dua sebab yang berdiri
sendiri — dan keduanya gagal **tanpa suara**, sehingga terbaca sebagai "HP-ku lemot" alih-alih
fitur rusak:

1. **Input tak mematikan autocapitalize.** Huruf pertama tiap kata dikapitalisasi otomatis,
   gagal cek prefiks, lalu dibuang oleh penolakan karakter di `checkInput()`. Pemain menekan
   huruf dan tak terjadi apa-apa.
2. **Kata hanya bisa maju lewat `@keydown.space`.** Gboard melaporkan `keydown` sebagai
   `Unidentified`/keyCode 229 selagi menyusun kata, jadi handler itu tak menyala; spasinya
   mendarat sebagai karakter biasa, dan karena `"the "` bukan prefiks dari `"the"` ia ikut
   dibuang. Pemain terkunci selamanya di kata pertama.

Fakta soal Gboard bukan hal baru bagi proyek ini — sudah didokumentasikan untuk mode solo di
[typing-engine.md](typing-engine.md) §3.7.a. Yang terjadi: pelajarannya tak ikut ke multiplayer.

#### Kenapa pola Solo TIDAK bisa disalin

Solo membatalkan `beforeinput` dan **selalu mengosongkan field** — inputnya cuma pemanggil
keyboard, seluruh state ada di mesin. Race sebaliknya: `x-model="typedText"` berarti **field itu
sendiri adalah state** kata yang sedang diketik dan harus mempertahankan nilainya. Menyalin pola
solo berarti menulis ulang seluruh lapisan input (backspace, caret, composition) tanpa satu pun
test JS yang bisa menangkap kesalahannya.

Yang dipakai: **deteksi spasi dari nilai field, bukan dari event keydown.**

#### Kenapa kedua jalur tak pernah dobel-hitung

```
Desktop : keydown.space → preventDefault → spasi TAK PERNAH masuk field → jalur nilai buta
Gboard  : keydown tak cocok → tak ada preventDefault → spasi masuk field → jalur nilai jalan
```

Yang menegakkan ini adalah `preventDefault()` pada **fase keydown**: ia membatalkan default
action, sehingga `beforeinput` / penyisipan / `input` tak pernah terjadi. Karena itu ia
**wajib tak bisa dilewati** — dulu sebuah `return` mendahuluinya, dan itu tak berbahaya hanya
karena input kebetulan di-`:disabled` dengan kondisi yang sama persis. Kalau kelak seseorang
memindahkan penanganannya ke `@beforeinput`, seluruh argumen ini runtuh diam-diam.
Dijaga test "membatalkan spasi desktop sebelum return mana pun bisa membocorkannya".

#### Tiga aturan yang jangan "diperbaiki"

**1. Potong di spasi PERTAMA, buang sisanya — bukan "hapus semua spasi".** Menghapus semua
spasi membuat `"th e"` lolos sebagai `"the"` yang "diketik persis benar", jadi aturan word-lock
berubah diam-diam. Membuang sisa juga membatasi **maksimum satu kata maju per event**: swipe dan
paste mengirim beberapa kata sekaligus, dan me-loop-nya akan menyelesaikan balapan dalam
segelintir gestur — karena juara ditentukan **waktu selesai**, itu langsung jadi strategi
optimal, persis bug yang word-lock diciptakan untuk membunuh (§3.2).

**2. `return` segera setelah `advanceWord()`.** `checkInput()` memegang `targetWord` dari
**sebelum** kata maju, jadi jatuh terus akan menilai cabang kata terakhir dengan target basi dan
mengirim `emitProgress` kedua.

**3. Kata yang mendarat utuh tetap dihitung keystroke-nya.** Swipe/autocorrect mengirim `"the "`
dalam satu event, jadi karakternya tak pernah melewati penghitung per-karakter. Tanpa credit itu
pemain swipe selalu tampil akurasi 100% sementara pemain desktop membayar tiap typo. Credit-nya
**hanya di cabang yang `return`** — kalau ditaruh sebelum percabangan, `if (isNewChar)` di bawah
menghitungnya dua kali.

#### Paste ditutup secara sengaja

Hari ini paragraf yang di-paste tertolak hanya karena ia bukan prefiks kata target — perlindungan
**tak disengaja**, dan jalur nilai melemahkannya (kata pertamanya kini valid). `@paste.prevent`
dan `@drop.prevent` menutupnya secara eksplisit. Biayanya: paste satu kata yang benar berhenti
bekerja di desktop. Itu memang yang diinginkan.

#### Enter = maju kata

`@keydown.enter` diikat ke `handleSpace` yang sama, sehingga `enterkeyhint="next"` jadi janji
yang ditepati. Sengaja **bukan** `"done"` seperti solo: di tengah balapan itu menutup keyboard
dan memaksa satu sentuhan lagi untuk melanjutkan. Ia juga jaring pengaman kedua kalau perilaku
spasi sebuah keyboard bermasalah.

#### Batas yang jujur

Semua test di atas adalah **kontrak markup & modul, bukan bukti perilaku** — proyek ini tak
mengeksekusi JavaScript di test sama sekali. Dua hal yang **tak bisa** ditutup dari HTML dan
harus diverifikasi di perangkat: sesi *composition* IME yang masih berjalan saat `advanceWord()`
mengosongkan field, dan fitur Gboard "dobel spasi jadi titik" (rancangan di atas menolaknya
dengan sopan, tapi tak bisa mematikannya). Checklistnya di
[`../mobile-test-checklist.md`](../mobile-test-checklist.md).

### 3.17 Arena di layar sempit (temuan uji perangkat, 2026-07-26)

Dua masalah yang **hanya muncul di HP** dan tak satu pun tertangkap test — keduanya soal
layout, bukan logika, jadi seluruh angka di baliknya sebenarnya benar.

#### Lintasan menyusut ke lebar nol

Baris lane menaruh tiga kolom lebar-tetap dalam satu baris: badge peringkat, nama (`w-40`),
dan WPM (`w-16`). Bersama gap dan padding itu butuh **~324px**, sementara layar 360px hanya
menyisakan **~250px** untuk lane. Akibatnya lintasan yang `flex-1` menyusut ke **lebar nol**
dan barisnya tetap meluber — angka WPM terpotong di tepi kanan.

Yang dilihat pemain: maskot duduk persis di atas bendera finis, tanpa lintasan di antaranya.
Terbaca sebagai **"balapannya tidak jalan"**, padahal ia hanya tak muat.

Perbaikannya: di bawah `sm` lintasan **turun ke barisnya sendiri** selebar penuh
(`order-last basis-full`), dan kolom nama melepas lebar tetapnya (`flex-1 min-w-0 sm:w-40`) —
begitu lintasan pindah baris, alignment antar-lane tak lagi bergantung padanya. Dari `sm` ke
atas markup-nya berperilaku persis seperti sebelumnya.

#### Paragraf dan field tak bisa dilihat bersamaan

Paragraf dirender setinggi seluruh teks — di HP sekitar **14 baris** — sehingga field ketik
berada jauh di bawah lipatan layar. Tiap ketikan membuat browser menggulir field yang difokus
kembali ke tampilan, jadi pemain hanya bisa melihat **kata-katanya ATAU field-nya, tak pernah
keduanya**: baca ke depan, gulir turun, ketik, tertarik turun lagi, gulir naik lagi. Praktis
tak bisa dimainkan walau setiap keystroke sebenarnya terdaftar dengan benar.

Sekarang paragrafnya **dipotong tiga baris** dan **menggeser dirinya sendiri**. Aturannya persis
menyalin mesin ketik solo: jendela **tak bergerak sama sekali** sampai kata aktif mencapai baris
**ketiga**, lalu baris itu mendarat di baris **tengah** — satu baris konteks di atas, satu baris
lookahead di bawah. Bereaksi satu baris lebih lambat daripada "kata aktif ke baris teratas" inilah
yang menghilangkan jitter "turun kepagian lalu naik lagi di huruf pertama baris berikutnya": baris 1
dan 2 sama-sama diam. Field tak pernah berpindah relatif terhadap teks, jadi tak ada lagi yang perlu
digulir.

Tiga keputusan di dalamnya:

| Hal | Kenapa |
|---|---|
| `overflow-hidden`, **bukan** `auto` | Ini tak boleh berubah jadi area gulir kedua yang harus digeser tangan — itu memindahkan masalahnya, bukan menyelesaikannya |
| Indeks baris tiap kata di-**snapshot** dari `offsetTop` yang benar-benar diukur (`_lineOf`), tak pernah ditebak dari jumlah karakter | Kata membungkus berbeda di tiap lebar layar; hitungan sendiri akan berbeda dari yang benar-benar dilay-out browser. Tapi baris sebuah kata adalah **fakta layout** — ia hanya berubah saat re-wrap nyata, bukan saat mengetik — jadi diukur sekali lalu dibaca dari map itu, bukan di-re-measure tiap panggil |
| Tingginya dalam **`em`** (`4.875em` = 3 × `leading-relaxed`) | Mengikuti line-height, bukan tebakan piksel yang patah begitu skala tipografi berubah. Angka yang sama dipakai mesin ketik solo |

`syncWordScroll()` dipicu dari **empat** tempat, semuanya lewat `scheduleWordScroll()` (rAF, jadi
`offsetTop` dibaca pasca-layout — pola `schedulePositionUpdate()` mesin solo):

1. `advanceWord()` — kata maju.
2. `checkInput()` — **self-heal tiap ketikan**. Ini yang dipunyai solo (`updatePosition()` jalan
   dari ~9 titik) dan dulu tak dipunyai balapan: solo recompute tiap karakter sehingga satu frame
   buruk terkoreksi sendiri, sementara balapan cuma recompute saat kata maju — jadi apa pun yang
   salah bertahan sampai spasi berikutnya.
3. `init()` — reload di tengah balapan mendarat di kata yang bisa jauh di bawah.
4. `_invalidateScroll()` — setiap penyebab **re-wrap**, yang juga menggugurkan snapshot `_lineOf`.
   `window.resize` saja tak cukup: flip `$arenaDense` saat racer ke-4 masuk (`p-8` → `p-5`), banner
   sudden-death yang muncul in-flow di atas kotak, dan lane lawan yang membungkus lalu mengubah
   lebar kartu — tak satu pun memicu `resize`, jadi ada `ResizeObserver` pada track. `resize`
   dipertahankan di sampingnya karena di sebagian browser mobile keyboard lunak mengubah visual
   viewport tanpa mengubah ukuran track. `document.fonts.ready` menangkap web font yang mendarat
   setelah paint pertama: diukur sebelum itu, snapshot-nya menggambarkan layout font **fallback** —
   salah untuk seluruh balapan, dan itu bukan resize, jadi tak ada lagi yang mengoreksinya.

#### Kenapa kartu paragrafnya `wire:ignore`

Ini perbaikan untuk bug "kotaknya terkadang berpindah tiba-tiba", dan hal paling berharga di
halaman ini — **enam upaya** gagal sebelum akar masalahnya ketemu.

`checkInput()` memanggil `$wire.updateRaceProgress()` pada **setiap ketikan** (dithrottle ~120ms di
`emitProgress()`), jadi selagi pemain mengetik arena di-morph Livewire **~8x per detik**. HTML server
untuk track kata **tak punya atribut `style`** — transform-nya ada semata karena Alpine mengevaluasi
`:style` di klien. Morphdom membandingkan HTML-server-tanpa-style dengan DOM-hidup-dengan-transform,
lalu **menghapus atributnya**: paragraf melompat ke `translateY(0)`, dan karena track membawa
`transition-transform duration-[85ms]`, lompatan itu **dianimasikan**.

Alpine tak memperbaikinya. `:style` hanya dievaluasi ulang saat dependensi reaktifnya
(`wordScrollOffset`) **berubah**, dan di antara dua kata maju nilainya konstan — jadi efeknya tak
pernah jalan lagi dan transform tetap hilang sampai spasi berikutnya. Bug ini hanya tampak setelah
offset-nya bukan nol (kata aktif di baris 3+) dan hanya kalau ada morph yang mendarat di antara dua
kata maju: **intermiten secara struktural**, itulah "terkadang"-nya.

Enam upaya sebelumnya semuanya mengubah **RUMUS** di dalam `syncWordScroll()`. Tak ada rumus yang
selamat kalau atributnya dihapus dari elemen yang ditulisinya.

Dua catatan yang mudah salah duga:

- Progres **lawan** bukan penyebabnya. `.race.progress` masuk langsung ke Alpine store tanpa
  round-trip Livewire (`race-echo.js`), jadi pemain lain tak pernah me-morph DOM kita. Yang me-morph
  adalah emit **kita sendiri**.
- Mesin solo lolos tanpa `wire:ignore` pada track teksnya karena `updatePosition()` jalan tiap
  ketikan — self-heal menutupi masalahnya — dan solo tak punya panggilan `$wire` per ketikan. Caret
  solo, yang tak seberuntung itu, **sudah** memakai `wire:ignore` dan mendokumentasikan kegagalan ini
  persis (lihat `typing-engine.blade.php`). Perbaikannya dua lapis: (1) `wire:ignore` supaya Livewire
  tak pernah menyentuh elemennya, (2) transform reaktif supaya Alpine selalu memasangnya. Track kata
  balapan sudah punya lapisan kedua, tapi tidak lapisan pertama.

Aman di-`wire:ignore` karena tak ada nilai server-rendered di dalam kartu itu: kata-katanya dari
`textToType` (tetap sepanjang balapan) dan semua state yang menatanya (`currentWordIndex`,
`hasError`, `justBlocked`, `wordScrollOffset`) ada di klien. Atributnya dipasang pada div paragraf
**dalam**, bukan kartu luar, karena padding kartu luar digerakkan `$dense` dan harus tetap bisa
di-morph. `wire:ignore` memblokir morph, bukan teardown Alpine — dan `wire:key` root sudah stabil,
jadi `raceArena` tidak di-remount.

> **Yang sengaja belum disentuh:** `interactive-widget=resizes-content` pada viewport meta,
> yang akan membuat Android menyusutkan layout viewport saat keyboard naik. Itu memengaruhi
> **semua** halaman sekaligus dan belum terverifikasi di perangkat; jendela tiga baris di atas
> seharusnya sudah cukup. Simpan sebagai langkah berikutnya kalau uji perangkat menunjukkan
> field-nya masih tertutup keyboard.

## 4. Batasan Saat Ini

- WPM/place tersimpan di `room_members` tidak melalui jalur PB/leaderboard global — race adalah
  event sosial, bukan sumber rekor pribadi. Lihat diskusi di
  [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).
