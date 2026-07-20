# UeType — Dokumentasi Proyek Menyeluruh

**Dibuat:** 2026-07-13
**Cakupan:** Gambaran arsitektur, database, seluruh route, dan tooling di satu tempat.
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
Semua fitur real-time (multiplayer race, chat, presence, notifikasi clan/teman)
memakai **channel publik** Reverb (bukan `PrivateChannel`) — keamanan mengandalkan
kode/ID yang tak mudah ditebak (kode room 6 digit, user ID), bukan otorisasi channel
Laravel. `routes/channels.php` hanya mendaftarkan channel privat bawaan
`App.Models.User.{id}`. Setiap broadcast dibungkus
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

1. **Server adalah satu-satunya sumber kebenaran** — WPM, akurasi, poin tidak pernah
   dipercaya dari client; selalu dihitung ulang server (`AntiCheatService`).
2. **State diturunkan, bukan disimpan ganda** — level dari `total_xp`, status online
   dari `last_seen_at`, dsb.

## 4. Skema Database

31 migrasi di `database/migrations/`. Dikelompokkan per domain:

### Identitas & Autentikasi
- **`users`** — `google_id`, `email`, `username` (unik), `avatar`, `highest_wpm`,
  `total_xp`, `is_admin`, `preferences` (json), `last_seen_at` (presence).
- **`password_reset_tokens`**, **`sessions`** — standar Laravel/Breeze.

### Konten Mengetik
- **`languages`** — bahasa konten (en/id).
- **`texts`** — materi sumber (mode/difficulty/author) — sebagian besar sudah
  digantikan pendekatan wordlist JSON (`database/data/*.json`), tapi tabel tetap
  dipakai untuk Ghost Mode & referensi hasil.
- **`typing_results`** — catatan sesi solo: `net_wpm`, `raw_wpm`, `accuracy`,
  `correct_chars`/`incorrect_chars`, `duration_seconds`, `score` (khusus survival),
  `xp_earned`. Write-once (`UPDATED_AT = null`).
- **`paragraphs`** — materi latihan tambahan (jarang dipakai langsung).

### Multiplayer Race
- **`rooms`** — `code` (unik), `host_id`, `status`, `text_to_type`,
  `race_starts_at` (countdown tersinkron), `countdown_started_at` (sudden death).
- **`room_members`** — state live per pemain: `progress_percent`, `wpm`, `accuracy`,
  `finished_time_seconds`, `place`, `xp_earned`, `result_recorded` (flag anti-cheat,
  lihat [`wpm-accuracy-integrity.md`](wpm-accuracy-integrity.md)).
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
  power before/delta kedua sisi, `accept_deadline_at`, `started_at`, `ends_at`.
- **`clan_war_mode_claims`** — satu baris per slot mode yang diklaim pemain di grid
  9-mode; unik per `(clan_war_id, mode, mode_config, clan_id)`.
- **`clan_war_fixed_texts`** — teks Words-mode yang dibekukan (di-seed langsung di
  migrasi) supaya semua pemain dapat teks identik per konfigurasi.

### Sosial
- **`friendships`** — `requester_id`/`addressee_id`, `status`
  (pending/accepted/rejected/blocked).
- **`messages`** — DM & clan chat dalam satu tabel (`recipient_id` XOR `clan_id`,
  ditegakkan via CHECK constraint), plus `edited_at`, `deleted_for_everyone_at`,
  `reply_to_id` (self-referencing).
- **`message_clears`** — penanda "clear chat" per user per percakapan.
- **`message_deletes`** — penanda "delete for me" per user per pesan.

### Achievement & Legacy
- **`user_achievements`** — log unlock (`achievement_key`, `unlocked_at`);
  definisi achievement sendiri hidup di kode
  ([`AchievementDefinitions`](../app/Support/AchievementDefinitions.php) — bukan DB.
- **`matches`** + **`match_participants`** — sistem match 1v1 versi lama, sudah
  digantikan `rooms`/`room_members`; `MatchController` masih berupa stub TODO.

### Infrastruktur
- `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` — tabel bawaan Laravel.

**Catatan gaya:** komentar migrasi ditulis dalam Bahasa Indonesia dan cenderung
menjelaskan *alasan* keputusan skema, bukan cuma deskripsi kolom — konvensi yang
konsisten di seluruh proyek.

## 5. Model & Relasi Kunci

Daftar lengkap ada di `app/Models/` (19 file). Yang paling sering disentuh:

- **`User`** — memuat sistem level/XP (`addExp()`, `levelData()`, formula kuadratik
  tertutup dari `total_xp`) dan sistem presence (`isOnline()`, `touchPresence()`).
  Relasi clan diakses via accessor magic `$user->clan` / `$user->clan_role`
  (bukan relasi Eloquent biasa).
- **`Room`** / **`RoomMember`** — state live multiplayer race.
- **`TypingResult`** — write-once, sumber data Stats & leaderboard solo.
- **`Clan`** — sistem power/level paralel dengan `User` (`BASE_POWER = 1000`,
  `POWER_PER_LEVEL = 100`).
- **`Message`** — helper visibilitas (`scopeVisibleTo`) yang mengecualikan pesan
  yang di-delete-for-me maupun percakapan yang di-clear.

## 6. Livewire Components

15 komponen class-based di `app/Livewire/` + 2 view Volt-style di
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
| `Chat` | `/chat` | DM + clan chat |
| `Clans` | `/clans` | Hub clan sendiri/browse/create |
| `ClanShow` | `/clans/{clan}` | Detail clan (read-only) |
| `ClanWar` | `/clan-war` | Challenge/accept, grid klaim 9-mode |
| `ClanLeaderboard` | `/clan-leaderboard` | Ranking clan by power |
| `Friends` | `/friends` | Kelola pertemanan |
| `FriendButton` | (embedded) | Tombol add-friend mini di profil publik |
| `Settings` | `/settings` | Username, locale, hapus akun |
| `About` | `/about` | Halaman tim statis |
| `Terms` | `/privacy-policy` | Kebijakan privasi |
| `leaderboard` (Volt) | `/leaderboard` | Top-10 global per mode/config/timeframe |

Detail cara kerja tiap komponen inti ada di dokumen fitur masing-masing
(lihat tabel di §9).

## 7. Peta Route Lengkap

Sumber: `routes/web.php`, `routes/auth.php`, `routes/channels.php`.

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
- `GET /chat`, `POST /chat/send` → `Chat` / `ChatController`
- `GET /clans`, `GET /clans/{clan}` → `Clans` / `ClanShow`
- `GET /clan-war` → `ClanWar`
- `GET /clan-leaderboard` → `ClanLeaderboard`
- `GET /multiplayer` (Volt) → multiplayer-lobby
- `GET /leaderboard` (Volt) → leaderboard

**Autentikasi:**
- `POST /locale` → ganti bahasa UI
- `GET /auth/google`, `GET /auth/google/callback` → OAuth Google
- `GET|POST /auth/google/username` → pilih username pasca-OAuth
- Route Breeze standar di `routes/auth.php` (register/login/reset password/verify email)

**Lokal saja** (`app()->environment('local')`):
- `/style-guide` — referensi design system hidup
- `/dev-login`, `/dev-login2` — login instan sebagai user dummy dari seeder

**Route stub/belum jadi** (controller ada, logika TODO):
- `LeaderboardController` (leaderboard asli ada di Volt component, bukan di sini)
- `MatchController`, `TextController`, `UserController`, `ShopController` —
  menandakan fitur yang direncanakan tapi belum dibangun (panel admin, shop/coin,
  analitik keystroke).

## 8. Services & Events

### Services (`app/Services/`)
| Service | Peran |
|---|---|
| `AntiCheatService` | Hitung ulang & validasi WPM/akurasi server-side — trust boundary utama |
| `AchievementService` | Evaluasi & catat unlock achievement |
| `EloCalculator` | Rating Elo untuk Clan War (K=32) |
| `ClanWarModeCatalog` | Definisi 9 mode wajib Clan War + skala poin |
| `ClanWarScorer` | Skor hasil ketik untuk satu slot Clan War |
| `ClanWarResolver` | Resolusi war yang selesai/kadaluarsa (lazy, dipicu saat halaman dibuka) |

### Events (`app/Events/`, semua `ShouldBroadcastNow` di channel publik)
| Event | Channel |
|---|---|
| `RoomUpdated` | `room.{code}` |
| `RaceProgressUpdated` | `race.{code}` |
| `SuddenDeathTriggered` | `race.{code}` |
| `RoomMessageSent` | `room.{code}` (chat lobby, broadcast-only) |
| `RoomPresenceChanged` | `room.{code}` (notif join/leave) |
| `DirectMessageSent` | `chat.{recipientId}` |
| `ClanMessageSent` | `clan-chat.{clanId}` |
| `MessageEdited` / `MessageDeleted` | `chat.{id}` atau `clan-chat.{id}` |
| `FriendshipUpdated` | `friends.{userId}` |
| `PresenceUpdated` | `friends.{friendId}` |
| `ClanUpdated` | `clan.{userId}` |

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
- [`wpm-accuracy-integrity.md`](wpm-accuracy-integrity.md) — studi kasus keputusan
  Net WPM vs Raw WPM di multiplayer

## 10. Testing

- **Framework:** Pest v4 (`tests/Pest.php`), gaya fungsional (`it('...', fn () => ...)`).
- **Struktur:** `tests/Unit` (3 file: contoh, `AntiCheatServiceTest`, `AppTimeTest`) +
  `tests/Feature` (33 file top-level + 4 di `Auth/`).
- **Total:** 40 file test, ±234 kasus `it()`/`test()`.
- **Konvensi:** `RefreshDatabase`, pola
  `Livewire::actingAs($user)->test(Component::class)->call(...)->assertDispatched(...)`,
  fixture via factory (`UserFactory` — satu-satunya factory di proyek).
- **Fokus verifikasi trust-boundary:** banyak test secara eksplisit memverifikasi
  bahwa angka penting (WPM ghost, WPM race) selalu dihitung ulang server meski
  client mengirim payload yang berbeda — pola yang sama dipakai berulang kali saat
  menambah fitur baru (lihat juga [`wpm-accuracy-integrity.md`](wpm-accuracy-integrity.md)).

## 11. Tooling & Konfigurasi

- **Linter:** Laravel Pint (`composer.json` dev dependency), preset default (tanpa
  `pint.json` custom).
- **Broadcasting:** `config/broadcasting.php` (default `reverb`),
  `config/reverb.php` (host/port dari env, rate limiting tersedia tapi nonaktif
  default).
- **Env kunci** (`.env.example`): `DB_CONNECTION=sqlite`,
  `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID/KEY/SECRET`,
  `REVERB_HOST=127.0.0.1`, `REVERB_PORT=8080`, dicerminkan ke `VITE_REVERB_*`
  untuk frontend build (di-*bake* saat `npm run build` — lihat catatan penting di
  §12).
- **Composer script `dev`:** menjalankan `serve` + `queue:listen` + `pail` +
  `npm run dev` bersamaan.
- **Seeder** (`database/seeders/`): `LanguageSeeder`, `TextSeeder`,
  `DummyDataSeeder` selalu jalan; `DummyUserSeeder`/`DummyMultiplayerSeeder`/
  `DummyClanSeeder` hanya di env `local` (jadi basis akun `/dev-login`).
- **Console command:** `clan-war:resolve` (`app/Console/Commands/ResolveClanWars.php`)
  — jalur manual untuk resolusi Clan War di luar lazy-resolve saat halaman dibuka.

## 12. Hal yang Perlu Diperhatikan Tim (jebakan yang sudah pernah kejadian)

- **Build frontend harus diulang setelah `.env` REVERB berubah** — `VITE_REVERB_*`
  di-*bake* ke bundle JS saat `npm run build`, bukan dibaca ulang saat runtime.
  Kalau host mengubah `REVERB_HOST` ke IP LAN tapi tidak `npm run build` ulang,
  perangkat lain akan gagal connect WebSocket secara diam-diam (root cause yang
  pernah ditemukan pada bug "countdown macet di PC teman").
- **Channel Reverb bersifat publik**, bukan `PrivateChannel` — keamanan bergantung
  pada kode/ID yang tak mudah ditebak, bukan otorisasi Laravel di
  `routes/channels.php`.
- **Kontroler stub** (`MatchController`, `TextController`, `UserController`,
  `ShopController`, `LeaderboardController`) jangan dikira representasi fitur asli
  — logika sesungguhnya sudah pindah ke Livewire component/Volt.
- **`minimum-stability: dev`** di `composer.json` — perlu hati-hati saat
  `composer update`.

---

*Dokumen ini adalah peta menyeluruh (arsitektur, skema DB, route, tooling).
Untuk "bagaimana X bekerja dan kenapa," rujuk dokumen fitur di §9.*
