# UeType — Gambaran Proyek

**Dibuat:** 2026-07-13 · **Diperbarui:** 2026-08-13
**Cakupan:** Gambaran arsitektur, database, route utama, dan tooling di satu tempat.
Untuk detail *cara kerja & justifikasi* per fitur, dokumen ini merujuk ke
[`docs/features/`](features/README.md) yang sudah ada — tidak diduplikasi di sini.

---

## 1. Apa Itu UeType

Game latihan mengetik (sejenis Monkeytype/TypeRacer) dengan mode solo, balapan
multiplayer real-time, sistem clan/guild dengan perang antar-clan, chat, sistem
pertemanan, achievement, dan leaderboard.

## 2. Tech Stack

### Backend
| Package | Versi | Peran |
|---|---|---|
| PHP | ^8.2 | |
| `laravel/framework` | ^12.0 | Framework inti |
| `livewire/livewire` | ^4.2 | UI reaktif server-driven |
| `livewire/volt` | ^1.10 | Komponen Livewire gaya fungsional single-file |
| `laravel/reverb` | ^1.0 | WebSocket server real-time |
| `laravel/socialite` | ^5.28 | Login Google OAuth |
| `pestphp/pest` | ^4.4 | Testing framework |
| `laravel/pint` | ^1.13 | Code style linter (preset default, tanpa `pint.json` custom) |

### Frontend
| Package | Versi | Peran |
|---|---|---|
| `alpinejs` | ^3.4 | Interaksi klien ringan (maskot race, timer, toast) |
| `laravel-echo` + `pusher-js` | ^2.3 / ^8.5 | Klien WebSocket (protokol Pusher, dilayani Reverb) |
| `tailwindcss` | ^3.4 | Styling utama — lihat [`design-system.md`](design-system.md) |
| `chart.js` | ^4.5 | Grafik di halaman Stats |
| `vite` | ^6.0 | Build tool |

**Catatan:** `minimum-stability: dev` di `composer.json` — tidak lazim untuk proyek
non-eksperimental, perlu diperhatikan saat upgrade dependency.

### Real-time
Channel yang membawa data berdasarkan ID berurutan memakai **`PrivateChannel`** dan diotorisasi
di `routes/channels.php`: DM, clan chat, presence/friend, undangan room, dan notifikasi clan.
Channel `room.{code}` serta `race.{code}` tetap publik agar spectator dan deep link undangan dapat
subscribe sebelum memiliki baris membership; batas aksesnya adalah kode room acak enam karakter.
Setiap broadcast dibungkus
[`App\Support\SafeBroadcast::run()`](../app/Support/SafeBroadcast.php) supaya
WebSocket yang mati tidak pernah menggagalkan request utama (lihat prinsip desain di
[`features/README.md`](features/README.md)).

## 3. Arsitektur Tingkat Tinggi

```
Browser
  ├─ Livewire 4 (server-rendered, morph on update)
  ├─ Alpine.js (x-data lokal + $store global untuk race track, toast)
  └─ Echo/pusher-js ──── WebSocket ────► Reverb server
                                              │
Laravel app ──── broadcast(...) ─────────────┘
  ├─ Livewire components (app/Livewire/*.php)   → sebagian besar halaman
  ├─ Volt components (resources/views/livewire/leaderboard.blade.php) → fungsional
  ├─ Services (app/Services/*.php)              → logika domain reusable
  ├─ Events (app/Events/*.php)                  → payload broadcast
  └─ Models (app/Models/*.php) ──► MySQL
```

Dua pola arsitektur yang berulang di seluruh proyek (detail alasan ada di
[`features/README.md`](features/README.md#prinsip-desain-yang-berulang-di-semua-fitur)):

1. **Server memutuskan hasil akhir** — WPM dan akurasi diturunkan ulang dari data mentah,
   sedangkan bukti dari client dibatasi dan diperiksa sebelum dipakai (`AntiCheatService`,
   `SoloSessionGuard`, dan evaluator integritas terkait).
2. **State diturunkan, bukan disimpan ganda** — level dari `total_xp`, status online
   dari `last_seen_at`, dsb.

## 4. Skema Database

51 migrasi di `database/migrations/`. Dikelompokkan per domain:

> **Tabel legacy sudah dibuang.** Migrasi
> `2026_07_20_110000_drop_legacy_match_and_text_tables.php` men-*drop*
> `matches`, `match_participants`, `texts`, `languages`, dan `paragraphs` — sisa
> rancangan awal yang digantikan `rooms`/`room_members` (balapan),
> `multiplayer_match_history` (riwayat), dan `TextGeneratorService` (teks dirakit
> dari wordlist JSON `database/data/*.json`, bukan dari tabel). Kolom
> `typing_results.text_id` ikut dibuang. Dokumen ini menjelaskan **skema akhir**
> (setelah drop), bukan tabel-tabel mati tersebut.

### Identitas & Autentikasi
- **`users`** — `google_id`, `email`, `username` (unik), `avatar`, `highest_wpm`,
  `total_xp`, `is_admin`, `preferences` (json), `last_seen_at` (presence).
- **`password_reset_tokens`** — tabel bawaan yang masih ada di schema, tetapi tidak mempunyai
  alur reset aktif karena autentikasi aplikasi Google-only.
- **`sessions`** — penyimpanan sesi Laravel.

### Konten Mengetik
- **`typing_results`** — catatan sesi solo: `net_wpm`, `raw_wpm`, `accuracy`,
  `correct_chars`/`incorrect_chars`, `duration_seconds`, `score` (khusus survival),
  `xp_earned`, `review_status`/`review_reason`, `session_fingerprint`, `integrity_meta`, dan
  `review_resolved_at`. Write-once (`UPDATED_AT = null`). Teks latihan **tidak** lagi berasal
  dari tabel — dirakit runtime oleh `TextGeneratorService` dari wordlist JSON.

### Multiplayer Race
- **`rooms`** — `code` (unik), `host_id`, `status`, `text_to_type`,
  `race_starts_at` (countdown tersinkron), `countdown_started_at` (sudden death).
- **`room_members`** — state live per pemain: `progress_percent`, `wpm`, `accuracy`,
  `finished_time_seconds`, `place`, `xp_earned`, dan `result_recorded`. Validasi hasil race
  dijelaskan di [`features/anti-cheat-wpm.md`](features/anti-cheat-wpm.md).
- **`multiplayer_match_history`** — log permanen hasil race (karena `rooms`/
  `room_members` dihapus setelah semua pemain keluar) — sumber data tab multiplayer
  di halaman Stats.
- *Chat ruang & notifikasi join/leave **tidak menyentuh DB*** — sengaja broadcast-only
  (event `RoomMessageSent` / `RoomPresenceChanged` di channel `room.{code}`), ditahan di
  state Alpine klien. Lihat [`features/multiplayer-race.md`](features/multiplayer-race.md) §3.9.

### Clan & Clan War
- **`clans`** — `name`, `tag`, `leader_id`, `power` (rating Elo, default 1000),
  `emblem`/`emblem_color`/`description`.
- **`clan_members`** — pivot dengan `role` (leader/member) & `status` (pending/active).
- **`clan_wars`** — `challenger_clan_id`/`opponent_clan_id`, `status`,
  power before/delta kedua sisi, `accept_deadline_at`, `started_at`, `ends_at`, plus
  `challenger_max_claims`/`opponent_max_claims` (snapshot cap klaim per anggota, diambil saat
  war diterima — lihat [clan-war.md](features/clan-war.md) §3.10).
- **`clan_war_mode_claims`** — satu baris per slot mode yang diklaim pemain di grid
  9-mode; unik per `(clan_war_id, mode, mode_config, clan_id)`. Kolom `attempt_started_at` /
  `attempt_text` / `attempt_progress` menjadikannya **satu percobaan yang bisa dilanjutkan**
  alih-alih tiket yang bisa diputar ulang oleh refresh
  ([clan-war.md](features/clan-war.md) §3.9).
- **`clan_war_fixed_texts`** — teks Words-mode yang dibekukan (di-seed langsung di
  migrasi) supaya semua pemain dapat teks identik per konfigurasi.

### Sosial
- **`friendships`** — `requester_id`/`addressee_id`, `status`
  (pending/accepted/rejected/blocked).
- **`messages`** — DM & clan chat dalam satu tabel (`recipient_id` XOR `clan_id`,
  ditegakkan via CHECK constraint), plus `edited_at`, `deleted_for_everyone_at`,
  `reply_to_id` (self-referencing). Kolom `body` **dienkripsi saat disimpan** (cast
  `'encrypted'`, dikunci `APP_KEY`) — lihat [`features/chat.md`](features/chat.md) §3.1.
- **`message_clears`** — penanda "clear chat" per user per percakapan.
- **`message_deletes`** — penanda "delete for me" per user per pesan.

### Achievement
- **`user_achievements`** — log unlock (`achievement_key`, `unlocked_at`);
  definisi achievement sendiri hidup di kode
  ([`AchievementDefinitions`](../app/Support/AchievementDefinitions.php)) — bukan DB.

### Infrastruktur
- `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` — tabel bawaan Laravel.

**Catatan gaya:** komentar baru sebaiknya menjelaskan *alasan* keputusan, bukan sekadar
mengulang apa yang sudah terlihat dari kode. Jangan mengandalkan bahasa komentar sebagai kontrak
teknis karena masih ada komentar lama dalam lebih dari satu bahasa.

## 5. Model & Relasi Kunci

Daftar lengkap ada di `app/Models/` (15 file). Yang paling sering disentuh:

- **`User`** — memuat sistem level/XP (`addExp()`, `levelData()`, formula kuadratik
  tertutup dari `total_xp`) dan sistem presence (`isOnline()`, `touchPresence()`).
  Relasi clan diakses via accessor magic `$user->clan` / `$user->clan_role`
  (bukan relasi Eloquent biasa).
- **`Room`** / **`RoomMember`** — state live multiplayer race.
- **`TypingResult`** — write-once, sumber data Stats & leaderboard solo. Kolom `review_status`
  (`clear`/`pending`/`approved`/`rejected`) menyaring hasil ber-flag anti-cheat dari papan
  publik — lihat [anti-cheat-wpm.md](features/anti-cheat-wpm.md) §7.8. Papan juga menerapkan
  **gerbang kelayakan** (`LEADERBOARD_MIN_TYPING_SECONDS` = 1800 dtk / 30 menit akumulasi
  `duration_seconds` lintas mode) sebelum hasil pemain tampil — §11.
- **`Clan`** — sistem power/level paralel dengan `User` (`BASE_POWER = 1000`,
  `POWER_PER_LEVEL = 100`).
- **`Message`** — helper visibilitas (`scopeVisibleTo`) yang mengecualikan pesan
  yang di-delete-for-me maupun percakapan yang di-clear.

## 6. Livewire Components

17 komponen class-based di `app/Livewire/`, ditambah view Livewire/Volt di
`resources/views/livewire/` (`leaderboard.blade.php` fungsional penuh,
`multiplayer-lobby.blade.php` adalah view biasa yang di-*backing* kelas
`MultiplayerLobby`).

| Komponen | Route | Ringkasan |
|---|---|---|
| `TypingEngine` | `/`, `/typing` | Mesin ketik solo (Time/Words/Survival), guest-accessible |
| `TypingResult` | `/result` | Layar hasil, baca dari session |
| `MultiplayerLobby` | `/multiplayer` | Lobby + arena race real-time |
| `GhostPicker` | (embedded) | Pilih lawan ghost (diri sendiri/teman/leaderboard) |
| `Stats` | `/stats` | Agregat statistik, semua dihitung saat render |
| `Chat` | `/chat` | DM + clan chat (halaman penuh) |
| `ChatOverlay` | (global, mounted di layout) | Drawer chat balas-cepat dari halaman mana pun — lihat [`features/chat.md`](features/chat.md) §4 |
| `Clans` | `/clans` | Hub clan sendiri/browse/create |
| `ClanShow` | `/clans/{clan}` | Detail clan (read-only) |
| `ClanWar` | `/clan-war` | Challenge/accept, grid klaim 9-mode |
| `ClanLeaderboard` | `/clan-leaderboard` | Ranking clan by power |
| `Friends` | `/friends` | Kelola pertemanan |
| `FriendButton` | (embedded) | Tombol add-friend mini di profil publik |
| `Settings` | `/settings` | Username, locale, hapus akun |
| `About` | `/about` | Halaman tim statis |
| `Terms` | `/privacy-policy` | Kebijakan privasi |
| `leaderboard` (Volt) | `/leaderboard` | Top-10 global per mode/config/timeframe (gerbang kelayakan §11) |
| `ReviewQueue` | `/review-queue` | UI admin legacy untuk data review lama; bukan dependency alur anti-cheat otomatis |

**Trait bersama** (`app/Livewire/Concerns/`, 5 file) — logika yang dipakai lintas
komponen agar tak terduplikasi: `GuardsChatAccess` (guard DM/clan + kirim, dipakai
`Chat` & `ChatOverlay`), `ManagesChatConversation`, `FinalizesRace`,
`ReadsRoomState`, `ManagesRoomMembership` (tiga terakhir memecah `MultiplayerLobby`
yang besar).

Detail cara kerja tiap komponen inti ada di dokumen fitur masing-masing
(lihat tabel di §9).

## 7. Peta Route Utama

Sumber: `routes/web.php`, `routes/channels.php`, dan route paket monitoring.

**Publik/guest-accessible:**
- `GET /`, `GET /typing` → `TypingEngine`
- `GET /result` → `TypingResult`
- `GET /about`, `GET /privacy-policy`

**Auth-gated (`middleware('auth')`):**
- `GET /profile`, `GET /users/{user:username}` → `ProfileController`
- `GET /settings` → `Settings`
- `POST /heartbeat` → `PresenceController`
- `GET /achievements` → `AchievementController`
- `GET /stats` → `Stats`
- `GET /friends` → `Friends`
- `GET /friends/pending-count` → badge permintaan teman pada navigasi
- `GET /chat`, `POST /chat/send` → `Chat` / `ChatController`
- `GET /clans`, `GET /clans/{clan}` → `Clans` / `ClanShow`
- `GET /clan-war` → `ClanWar`
- `POST /clan-war/attempt-progress` → simpan progres attempt saat unload
- `GET /clan-leaderboard` → `ClanLeaderboard`
- `GET /multiplayer` (Volt) → multiplayer-lobby
- `POST /multiplayer/leave-beacon`, `POST /multiplayer/leave-confirm` → keluar room saat unload/konfirmasi
- `GET /leaderboard` (Volt) → leaderboard

**Admin-only (`EnsureUserIsAdmin`, guest/non-admin → 404):**
- `GET /review-queue` → `ReviewQueue` legacy (bukan bagian alur keputusan otomatis; lihat [anti-cheat-wpm.md](features/anti-cheat-wpm.md) §7.8)
- `GET /user-monitoring/*` → dashboard monitoring (lihat [monitoring.md](features/monitoring.md))

**Autentikasi:**
- `POST /locale` → ganti bahasa UI
- `GET /login` (guest-only), `POST /logout`
- `GET /auth/google`, `GET /auth/google/callback` → OAuth Google
- `GET|POST /auth/google/username` → pilih username pasca-OAuth

> **Autentikasi kini Google-only.** Route Breeze untuk register/login-password/reset
> password/verify email **sudah tidak ada** — dikunci oleh
> `tests/Feature/Auth/GoogleOnlyAuthTest.php`. Nama route `login` wajib dipertahankan karena
> middleware `auth` bawaan Laravel mengarahkan tamu ke nama itu.

**Lokal saja** (`app()->environment('local')`):
- `/style-guide` — referensi design system hidup
- `/dev-login` (opsional `?email=...`) — login instan sebagai user dummy dari seeder

**Catatan controller:** ada **10 file** di `app/Http/Controllers/` (9 controller + base
`Controller`): `AchievementController`, `ChatController`, `ClanWarProgressController`,
`FriendController`, `GoogleAuthController`, `LocaleController`,
`MultiplayerPresenceController`, `PresenceController`, `ProfileController`.
Kontroler-kontroler stub versi lama (`MatchController`, `TextController`, `UserController`,
`ShopController`, `LeaderboardController`) **sudah dihapus** — logika sesungguhnya berada di
komponen Livewire/Volt, jadi tak ada lagi controller kosong yang menyesatkan.

Empat yang terbaru sengaja berupa endpoint tipis di luar Livewire, bukan kelalaian: XHR
Livewire dibatalkan browser saat halaman di-unload, sedangkan ping yang paling menentukan
justru yang dikirim tepat saat pemain menekan refresh. `ClanWarProgressController` dan
`MultiplayerPresenceController` dijangkau lewat `fetch(..., { keepalive: true })` supaya
selamat melewati unload; `FriendController` melayani nav yang berupa partial statis.

## 8. Services & Events

### Services (`app/Services/`)
| Service | Peran |
|---|---|
| `AntiCheatService` | Hitung ulang & validasi WPM/akurasi server-side — trust boundary utama |
| `SurvivalPlausibility` | Lantai fisik Survival: minimum karakter yang bisa menopang durasi yang diklaim. Pelanggaran invariant ditolak sebelum hasil disimpan |
| `SoloSessionGuard` | Acuan sesi solo di server (mode/panjang teks/waktu mulai); plafon karakter & anti-replay — lihat [anti-cheat-wpm.md](features/anti-cheat-wpm.md) §7 |
| `KeystrokeAnalyzer` | Analisis distribusi timing antar-keystroke (deteksi bot) — §7.7b |
| `LongitudinalBaseline` | Bandingkan hasil vs riwayat tepercaya pemain; tandai lonjakan untuk probation otomatis — §7.7c |
| `AutomaticResultResolver` | Evaluasi cluster bukti pending dan promosikan hasil yang saling mendukung ke `clear` tanpa keputusan admin |
| `TypingSpeedVerificationService` | Terbitkan dan konsumsi challenge Time 30 dengan token sekali pakai, replay event, expiry, serta lifecycle attempt |
| `TypingVerificationReplay` | Rekonstruksi teks dan metrik challenge dari urutan event tombol yang dibatasi |
| `TypingSpeedCapabilityService` | Simpan capability per user + bahasa dan gunakan ceiling terverifikasi pada hasil berikutnya |
| `TypingResultPromoter` | Satu jalur idempoten untuk clear hasil serta sinkronisasi PB, achievement, dan poin war |
| `RoomMembershipService` | Keanggotaan room multiplayer (keluar, sapu offline, pindah host) |
| `AchievementService` | Evaluasi & catat unlock achievement |
| `GhostResolver` | Turunkan lawan ghost dari identitas (type + refId), WPM selalu di-fetch ulang dari DB — satu sumber kebenaran (dipakai `GhostPicker` & `TypingEngine`) |
| `TextGeneratorService` | Rakit teks latihan dari wordlist JSON — satu sumber untuk solo **dan** multiplayer (menggantikan tabel `texts`) |
| `TypingErrorInspector` | Ubah stream error mentah klien jadi view-model penanda error di layar hasil (`sanitize()` = trust boundary, `inspect()` = pemetaan ke chart) |
| `EloCalculator` | Rating Elo untuk Clan War (K=32) |
| `ClanWarAttempt` | Jangkar jam & teks beku satu percobaan Clan War — bikin refresh/Back melanjutkan attempt yang sama, bukan memulai yang baru ([clan-war.md](features/clan-war.md) §3.9) |
| `ClanWarModeCatalog` | Definisi 9 mode wajib Clan War + skala poin + cap klaim dinamis per ukuran clan |
| `ClanWarScorer` | Skor hasil ketik untuk satu slot Clan War |
| `ClanWarResolver` | Resolusi war yang selesai/kadaluarsa (lazy, dipicu saat halaman dibuka) |

### Events (`app/Events/`, semua `ShouldBroadcastNow`)
| Event | Channel | Akses |
|---|---|---|
| `RoomUpdated` | `room.{code}` | Publik, berbasis kode room acak |
| `RaceProgressUpdated` | `race.{code}` | Publik, berbasis kode room acak |
| `SuddenDeathTriggered` | `race.{code}` | Publik, berbasis kode room acak |
| `RoomMessageSent` | `room.{code}` | Publik, chat lobby broadcast-only |
| `RoomPresenceChanged` | `room.{code}` | Publik, notifikasi join/leave |
| `RoomInvitationSent` | `friends.{userId}` | Private, hanya pemilik feed |
| `DirectMessageSent` | `chat.{recipientId}` | Private, hanya penerima |
| `ClanMessageSent` | `clan-chat.{clanId}` | Private, anggota clan aktif |
| `MessageEdited` / `MessageDeleted` | `chat.{id}` atau `clan-chat.{id}` | Private sesuai percakapan |
| `FriendshipUpdated` | `friends.{userId}` | Private, hanya pemilik feed |
| `PresenceUpdated` | `friends.{friendId}` | Private, hanya pemilik feed |
| `ClanUpdated` | `clan.{userId}` | Private, hanya pemilik feed; lihat [clan-war.md](features/clan-war.md) §3.7 |

## 9. Dokumentasi Fitur (sudah ada, per topik)

Dokumen ini sengaja **tidak mengulang** penjelasan cara-kerja/justifikasi tiap
fitur — itu sudah didokumentasikan detail di `docs/features/`:

| # | Fitur | Dokumen |
|---|---|---|
| 1 | Mesin Ketik Solo (Time/Words/Survival) | [typing-engine.md](features/typing-engine.md) |
| 2 | Anti-Cheat & Perhitungan WPM | [anti-cheat-wpm.md](features/anti-cheat-wpm.md) |
| 3 | Ghost Mode | [ghost-mode.md](features/ghost-mode.md) |
| 4 | Multiplayer Race | [multiplayer-race.md](features/multiplayer-race.md) |
| 5 | Level & EXP | [level-exp.md](features/level-exp.md) |
| 6 | Statistik & Achievement | [stats-achievements.md](features/stats-achievements.md) |
| 7 | Teman & Presence | [friends-presence.md](features/friends-presence.md) |
| 8 | Chat (DM & Clan) | [chat.md](features/chat.md) |
| 9 | Clan | [clans.md](features/clans.md) |
| 10 | Clan War & Elo Power | [clan-war.md](features/clan-war.md) |
| 11 | Autentikasi (Google + Username) | [auth.md](features/auth.md) |
| 12 | Multi-bahasa | [localization.md](features/localization.md) |
| 13 | Monitoring Aktivitas Pengguna | [monitoring.md](features/monitoring.md) |

Dokumen terkait lain:
- [`design-system.md`](design-system.md) — token warna, tipografi, komponen UI
- [`features/anti-cheat-wpm.md`](features/anti-cheat-wpm.md) — aturan integritas WPM,
  akurasi, race, probation otomatis, dan dampaknya pada leaderboard

## 10. Testing

- **Framework:** Pest v4 (`tests/Pest.php`), gaya fungsional (`it('...', fn () => ...)`),
  ditambah **Vitest** untuk logika klien murni (`resources/js/*.test.js`, dijalankan
  `npm test` dan ikut dieksekusi CI sebelum build aset).
- **Struktur saat pembaruan dokumen:** 10 file di `tests/Unit` dan 139 file di
  `tests/Feature`. Gunakan `find tests -name '*.php'` untuk inventaris terbaru; jumlah ini bukan
  kontrak fitur dan akan bertambah bersama suite.
- **Database test terpisah.** `phpunit.xml` mematok `DB_DATABASE=uetype_test`; buat sekali per
  mesin (`CREATE DATABASE uetype_test;`). Tanpa itu `RefreshDatabase` menjalankan
  `migrate:fresh` terhadap database dev di `.env` dan menghapus seluruh data lokal — mode
  `--parallel` kebetulan aman karena Laravel membuat `uetype_test_1..N` sendiri, sehingga
  perintah yang lebih polos justru yang merusak. Dikunci `TestDatabaseIsolationTest`.
- **Test harus deterministik.** Dua pola pernah membuat suite merah berpindah-pindah tanpa ada
  perubahan kode: assertion yang terikat jam nyata (bekukan dengan
  `freezeTime()`/`freezeSecond()`/`travelTo()`) dan `assertDontSee('<angka>')` yang mencari
  substring di seluruh HTML — termasuk snapshot & checksum Livewire. Untuk yang kedua, periksa
  `viewData()` alih-alih markup.

  Aturan praktis dari penyisiran 2026-08-03: yang berbahaya **bukan** `now()->sub` di setup
  (41 berkas memakainya dan hampir semuanya aman), melainkan **apa yang diassert**. Nilai yang
  *disimpan* aman — `finished_time_seconds` yang ditulis langsung ke baris, `wpm` yang disalin
  apa adanya oleh `finalizeRace()`. Nilai yang *diturunkan dari jam* tidak: WPM race
  (`karakter / (now() − race_starts_at)`), `attempt_live_ms`, `raceStartsInMs`,
  `suddenDeathRemaining`. Kalau assertion-nya menyentuh yang kedua, bekukan jamnya — dan
  bekukan di `beforeEach` kalau seluruh berkas memang tentang besaran itu
  (lihat `RaceWpmIntegrityTest`), supaya test yang ditambahkan besok lahir ikut terlindungi.

  **Padding bukan solusi.** Melonggarkan ambang (`toBeLessThanOrEqual(11_000)` untuk batas 10
  detik) hanya memindahkan titik gagalnya; di bawah beban paralel ia tetap terlampaui. Dengan
  jam beku, ambangnya bisa dikembalikan ke nilai sebenarnya dan testnya justru lebih tajam.
- **Konvensi:** `RefreshDatabase`, pola
  `Livewire::actingAs($user)->test(Component::class)->call(...)->assertDispatched(...)`,
  fixture via factory (`UserFactory` — satu-satunya factory di proyek).
- **Fokus verifikasi trust-boundary:** banyak test secara eksplisit memverifikasi
  bahwa angka penting divalidasi atau diturunkan ulang server meski client mengirim payload
  berbeda. Detail batas dan residual risk ada di
  [`features/anti-cheat-wpm.md`](features/anti-cheat-wpm.md).

## 11. Tooling & Konfigurasi

- **Linter:** Laravel Pint (`composer.json` dev dependency), preset default (tanpa
  `pint.json` custom).
- **Broadcasting:** `.env.example` memilih `BROADCAST_CONNECTION=reverb`, sedangkan fallback
  `config/broadcasting.php` adalah `null`. Host/port Reverb berasal dari env melalui
  `config/reverb.php`; rate limiting tersedia tetapi nonaktif secara default.
- **Database:** proyek dan `.env.example` memakai **MySQL** dengan database contoh `uetype`.
- **Env kunci** (`.env.example`): `BROADCAST_CONNECTION=reverb`,
  `REVERB_APP_ID/KEY/SECRET`,
  `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, dicerminkan ke `VITE_REVERB_*`
  untuk frontend build (di-*bake* saat `npm run build` — lihat catatan penting di
  §12).
- **Sesi:** `.env.example` mematok `SESSION_LIFETIME=120` menit. Login Google memakai
  `remember: true`, sehingga cookie remember-me dapat memulihkan autentikasi setelah sesi biasa
  berakhir; lihat [`features/auth.md`](features/auth.md) §3.6.
- **Composer script `dev`:** menjalankan `serve` + `queue:listen` + `pail` +
  `npm run dev` bersamaan.
- **Seeder** (`database/seeders/`): `DatabaseSeeder` menjalankan `DummyDataSeeder` dan
  `LeaderboardRosterSeeder` di semua environment. Akun admin `test@example.com` dibuat hanya
  di non-production; `DummyUserSeeder`/`DummyMultiplayerSeeder`/`DummyClanSeeder` hanya di env
  `local` (jadi basis akun `/dev-login`). Production deployment normal hanya menjalankan migrasi,
  bukan `db:seed`; jalankan seeder di production hanya jika roster demo memang dikehendaki.
  (`LanguageSeeder`/`TextSeeder` sudah **dihapus** bersama tabel `languages`/`texts` —
  teks latihan kini dirakit `TextGeneratorService` dari wordlist JSON, bukan dari DB.)
  Seeder manual (tak ikut `db:seed` default): `LeaderboardDemoSeeder` (akun uji eligibility,
  lihat [`features/anti-cheat-wpm.md`](features/anti-cheat-wpm.md) §11.5),
  `ClanUiTestSeeder`, dan `RemoveLeaderboardRosterSeeder` untuk menghapus roster demo.
- **Console command** (`app/Console/Commands/`):
  - `clan-war:resolve` (`ResolveClanWars.php`) — resolusi Clan War manual di luar lazy-resolve.
  - `typing:audit [--wpm=150] [--limit=50]` (`AuditTypingResults.php`) — daftar hasil ber-WPM
    mencurigakan (read-only), lihat [`features/anti-cheat-wpm.md`](features/anti-cheat-wpm.md) §7.6.
  - `typing:reconcile` (`ReconcileTypingResults.php`) — proses ulang hasil probation yang memiliki
    bukti cukup; dijadwalkan setiap jam sebagai safety net di `routes/console.php`.
  - `user:admin <email> [--revoke]` (`MakeUserAdmin.php`) — angkat/cabut hak admin (satu-satunya
    cara masuk dashboard monitoring), lihat [`features/monitoring.md`](features/monitoring.md) §3.6.
  - `achievements:backfill` (`BackfillAchievements.php`) — isi ulang achievement untuk data lama.

## 12. Hal yang Perlu Diperhatikan Tim (jebakan yang sudah pernah kejadian)

- **Build frontend harus diulang setelah `.env` REVERB berubah** — `VITE_REVERB_*`
  di-*bake* ke bundle JS saat `npm run build`, bukan dibaca ulang saat runtime.
  Kalau host mengubah `REVERB_HOST` ke IP LAN tapi tidak `npm run build` ulang,
  perangkat lain akan gagal connect WebSocket secara diam-diam (root cause yang
  pernah ditemukan pada bug "countdown macet di PC teman").
- **Jalankan scheduler di production.** Pasang cron `php artisan schedule:run` setiap menit agar
  `typing:reconcile` benar-benar dieksekusi. Resolver sinkron tetap menjadi jalur utama; scheduler
  adalah safety net.
- **Bedakan channel publik dan private.** `room.{code}`/`race.{code}` publik berbasis kode acak;
  channel berbasis user/clan ID private dan wajib lolos otorisasi `routes/channels.php`.
- **Logika halaman ada di Livewire/Volt, bukan controller.** Controller stub versi
  lama sudah dihapus (lihat §7) — kalau mencari "controller" untuk suatu halaman dan
  tak menemukannya, cari komponen Livewire/Volt-nya, bukan mengira fiturnya hilang.
- **`minimum-stability: dev`** di `composer.json` — perlu hati-hati saat
  `composer update`.
- **Ganti host = ganti "toples" cookie, dan itu terlihat seperti ter-logout.**
  `SESSION_DOMAIN=null` mengikat cookie ke host **persis** yang menerbitkannya, jadi
  `127.0.0.1:8000`, `localhost:8000`, dan IP LAN adalah tiga sesi terpisah. Login di satu
  lalu membuka yang lain akan terbaca sebagai "remember-me rusak" padahal cuma beda toples.
  `GOOGLE_REDIRECT_URI` juga terpaku ke `APP_URL`, jadi menguji di IP LAN menuntut mengubah
  `APP_URL` **dan** mendaftarkan redirect URI itu di Google console. Pilih satu host untuk
  satu putaran pengujian penuh.

---

*Dokumen ini adalah peta tingkat tinggi (arsitektur, skema DB, route utama, tooling).
Untuk "bagaimana X bekerja dan kenapa," rujuk dokumen fitur di §9.*
