# UeType — Dokumentasi Fitur

Kumpulan dokumen ini menjelaskan **setiap fitur** yang sudah ada di aplikasi saat ini,
lengkap dengan **cara kerja** dan **justifikasi** kenapa metode/pendekatan tertentu dipilih.
Tujuannya: siapa pun yang masuk ke proyek bisa paham *apa* yang dibangun, *bagaimana*, dan
*kenapa begitu* — bukan hanya membaca kode mentah.

## Konteks Aplikasi

UeType adalah **game latihan mengetik** (sejenis Monkeytype/TypeRacer) berbasis:

- **Laravel 12** (backend, PHP 8.2+)
- **Livewire 4 + Volt** (UI reaktif tanpa banyak JS manual)
- **Alpine.js** (interaksi klien ringan, mis. gerakan maskot race & timer)
- **Laravel Reverb** (WebSocket real-time untuk multiplayer, chat, presence)
- **Socialite** (login Google)
- **Tailwind CSS** (styling; lihat [`../design-system.md`](../design-system.md))

## Prinsip Desain yang Berulang di Semua Fitur

Sebelum membaca per fitur, tiga prinsip ini muncul terus dan menjelaskan banyak keputusan:

1. **Server mengambil keputusan akhir pada trust boundary.**
   Angka akhir tidak diterima begitu saja dari client. Client tetap mengirim bukti mentah seperti
   jumlah karakter, durasi, progres, dan identifier; server menghitung ulang nilai yang dapat
   diturunkan lalu memeriksa bukti tersebut dengan guard sesuai mode. Karena bukti mentah bukan
   bukti kriptografis, aturan anti-cheat tetap memakai mitigasi berlapis.

2. **State diturunkan (derived), bukan disimpan ganda.**
   Level diturunkan dari `total_xp`, status online dari `last_seen_at`, keanggotaan clan dari
   pivot `clan_members`. Kenapa: menghindari data yang "nyangkut" tidak konsisten (mis. flag
   online `true` padahal browser sudah tertutup).

3. **Real-time boleh gagal tanpa merusak request inti.**
   Semua broadcast dibungkus [`SafeBroadcast`](../../app/Support/SafeBroadcast.php): kalau
   WebSocket mati, aksi utama (logout, kirim pesan) tetap sukses. Kenapa: real-time adalah
   *enhancement*, bukan syarat kebenaran data.

## Daftar Fitur

| # | Fitur | Dokumen |
|---|-------|---------|
| 1 | Mesin Ketik Solo (Time / Words / Survival) | [typing-engine.md](typing-engine.md) |
| 2 | Anti-Cheat & Perhitungan WPM | [anti-cheat-wpm.md](anti-cheat-wpm.md) |
| 3 | Ghost Mode (balapan lawan rekor) | [ghost-mode.md](ghost-mode.md) |
| 4 | Multiplayer Race (real-time) — penonton, chat ruang, undang teman & sweep member basi | [multiplayer-race.md](multiplayer-race.md) |
| 5 | Level & EXP | [level-exp.md](level-exp.md) |
| 6 | Statistik & Achievement | [stats-achievements.md](stats-achievements.md) |
| 7 | Teman & Presence (online/offline) | [friends-presence.md](friends-presence.md) |
| 8 | Chat (DM & Clan) | [chat.md](chat.md) |
| 9 | Clan | [clans.md](clans.md) |
| 10 | Clan War & Elo Power | [clan-war.md](clan-war.md) |
| 11 | Autentikasi (Google + Username) | [auth.md](auth.md) |
| 12 | Multi-bahasa (Konten & UI) | [localization.md](localization.md) |
| 13 | Monitoring Aktivitas Pengguna | [monitoring.md](monitoring.md) |

Dokumen lain yang relevan:
- [`../PROJECT_OVERVIEW.md`](../PROJECT_OVERVIEW.md) — peta tingkat tinggi proyek: tech
  stack, arsitektur, skema database, route utama, testing, dan tooling.
- [`../design-system.md`](../design-system.md) — token warna, tipografi, komponen UI.
- [`anti-cheat-wpm.md`](anti-cheat-wpm.md) — aturan integritas WPM/akurasi, race, dan
  probation otomatis.
