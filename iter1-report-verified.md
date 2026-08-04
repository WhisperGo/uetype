# Audit Code Quality — UeType (verifikasi + iterasi 2)

**Tanggal:** 2026-08-03 · **Branch:** `development` · **Temuan dicatat pada:** `3e330f4`
**Sifat:** Bagian A & B read-only; Bagian C mencatat perbaikan yang sudah dikerjakan sesudahnya.

**Verifikasi suite (dijalankan sendiri di mesin ini, Windows 11 + Herd PHP 8.4.16):**
`php -d memory_limit=2048M ./vendor/bin/pest --parallel` dijalankan **dua kali**:
- Run 1 → **1105 passed, 3 failed** (3347 assertions, 227s) — gagal: `RaceWpmIntegrityTest`
- Run 2 → **1105 passed, 3 failed** (3346 assertions) — gagal: `StatsPageTest`, `MultiplayerStatsTest`, `RaceCountdownSyncTest`

**Himpunan test yang gagal berbeda di tiap run**, dan ketujuhnya lolos saat dijalankan berurutan. Suite ini tidak deterministik — lihat H-4. CI tidak terpengaruh (memakai `php artisan test`, berurutan).

> ⚠️ **Pengungkapan tindakan saya.** Untuk mengisolasi kegagalan, saya menjalankan Pest **berurutan** dua kali. Dengan konfigurasi proyek saat ini, itu menjalankan `migrate:fresh` terhadap **`DB_DATABASE=uetype`** — database dev di `.env`, bukan database test terpisah (lihat M-10). Database `uetype` sekarang kosong (`users`/`typing_results`/`clans` = 0 baris). Saya tidak bisa memastikan apakah ia sudah kosong sebelumnya. Kalau ada data lokal yang Anda butuhkan, jalankan ulang `php artisan db:seed`. Mode `--parallel` tidak berdampak — ia memakai `uetype_test_1..12` yang terpisah.

Dokumen ini punya tiga bagian:
- **Bagian A** — analisis `iter1-report.md`: mana yang terbukti, mana yang salah.
- **Bagian B** — audit penuh sesuai `iter1.md`, memprioritaskan area yang iterasi 1 akui belum dibaca.
- **Bagian C** — status perbaikan: seluruh temuan sudah dikerjakan, lihat §C.

> **Status:** audit ini **sudah ditindaklanjuti**. Bagian A dan B dipertahankan apa adanya
> sebagai catatan temuan pada `3e330f4`; Bagian C mencatat apa yang berubah sesudahnya.

---

# BAGIAN A — Analisis `iter1-report.md`

## A.1 Yang terverifikasi benar (dicek ulang ke kode)

| Temuan | Status | Bukti |
|---|---|---|
| H-1 subquery kelayakan leaderboard | **Benar** | [leaderboard.blade.php:79-82](resources/views/livewire/leaderboard.blade.php#L79-L82); dieksekusi 2× per render (`$leaderboard` → `$bestPerUser` → `$scoped`, dan `$userRank:150`). Indeks `(user_id, duration_seconds)` memang tidak ada. |
| H-2 drift indeks | **Benar** | `typing_results_review_index` = `(mode, mode_config, review_status, net_wpm)` tanpa `language`; `..._wpm_index` = `(mode, mode_config, language, net_wpm)` tanpa `review_status`. Query memfilter keempatnya. |
| M-1 file kelewat besar | **Benar** | 1538 & 1420 baris, terverifikasi. |
| M-2 slack durasi datar | **Benar, dan lebih tajam dari yang ditulis** | [SoloSessionGuard.php:332](app/Services/SoloSessionGuard.php#L332) pakai `DURATION_SLACK_SECONDS` datar; [:297](app/Services/SoloSessionGuard.php#L297) pakai `min(30, durasi × 0.35)`. Tambahan yang report lewatkan: **komentar konstanta itu sendiri di baris 105 menyatakan "Capped further by SLACK_FRACTION"** — pernyataan yang hanya benar untuk jalur karakter. Dokumentasinya ikut salah, bukan cuma kodenya. |
| M-4 duplikasi aturan `highest_wpm` | **Benar** | [ReviewQueue.php:53](app/Livewire/ReviewQueue.php#L53) vs [TypingEngine.php:1106-1111](app/Livewire/TypingEngine.php#L1106-L1111). |
| M-5 `KEYSTROKE_TIMING_ENFORCED` masih `false` | **Benar** | [TypingEngine.php:95](app/Livewire/TypingEngine.php#L95). |
| L-4 log campur bahasa | **Benar** | [GoogleAuthController.php:99](app/Http/Controllers/GoogleAuthController.php#L99). |
| Nol TODO/FIXME/HACK | **Benar** | 0 hit di `app/`, `routes/`, `resources/js/`. |
| 4 lokasi `{!! !!}`, semuanya aman | **Benar** | 3 di `clan-war.blade.php`, 1 di `multiplayer-lobby.blade.php`; semua `number_format()`/`e()`. |
| 3 blok `catch`, semuanya di-log | **Benar** | `SafeBroadcast`, `GoogleAuthController`, `ClanWar`. |
| 48 migrasi, 15 model, semua model punya `$fillable` | **Benar** | Terverifikasi. |

Rekam jejaknya bagus: **tidak satu pun temuan teknis di report itu yang mengada-ada.** Yang bermasalah adalah klaim di sekitarnya.

## A.2 Yang salah atau menyesatkan

**A.2.1 — Klaim verifikasi "hijau" tidak stabil (paling penting).**
Report menulis: *"`./vendor/bin/pest --parallel` → 1108 passed, 3348 assertions, 66s (dijalankan saat audit, hijau)"*. Total testnya cocok (1105 + 3 = 1108), jadi run itu kemungkinan besar nyata. Tapi di mesin ini hasilnya **3 gagal dalam 227 detik**, dan salah satunya gagal **berulang** saat diisolasi:

```
FAILED  RaceWpmIntegrityTest > it ignores the client wpm and stores the server-computed net wpm
Failed asserting that 9 is identical to 10.
```

Bukan flake acak — test ini menganggap waktu antara setup dan assert ≈ 0 (lihat H-3). Di mesin cepat lolos, di mesin lambat tidak. Masalahnya bukan angkanya, tapi **report menyajikan "hijau" sebagai sifat suite**, padahal itu sifat mesin yang menjalankannya. Kalimat "seluruhnya terverifikasi oleh suite yang sudah hijau" di §3 jadi menyesatkan bagi tim yang membaca lalu percaya.

**A.2.2 — Klaim macOS/Herd salah.**
§5: *"`README.md` masih menjelaskan setup Laragon/Windows sementara proyek berjalan di macOS/Herd."* Proyek ini berjalan di **Windows 11**; PHP-nya datang dari `C:\Users\willi\.config\herd\bin\php.bat` — **Herd for Windows**. Bagian "Herd" benar, bagian "macOS" dikarang. README yang menjelaskan Windows justru masih tepat sasaran; yang usang hanya rekomendasi Laragon-nya. Salah diagnosis ini bisa membuat tim menulis ulang dokumentasi ke arah yang keliru.

**A.2.3 — M-3 terbalik arah.**
Report memposisikan `casts(): array` di `User` sebagai "gaya yang dipilih proyek" dan `TypingResult` sebagai penyimpang. Faktanya **11 dari 12 model memakai `protected $casts`**; `User` adalah satu-satunya yang memakai method. Jadi rekomendasi "samakan ke `casts()` di seluruh model" berarti mengubah **11 file**, bukan 1 — dan diklaim "perubahan mekanis, aman" tanpa menyadari skalanya. Tambahan: `protected $casts` **tidak** deprecated di Laravel 12; ini murni preferensi. Rekomendasi yang lebih murah: samakan `User` ke gaya mayoritas.

**A.2.4 — L-5 salah baca dokumentasi.**
Report menulis `docs/PROJECT_OVERVIEW.md` "masih menyebutkan" stub controller yang sudah dihapus. Dokumen itu justru sudah menyatakan sebaliknya — §7 menulis stub-stub itu **"sudah dihapus"**. Drift yang nyata di sana bukan itu, melainkan **jumlahnya**: doc bilang "hanya 7 controller yang tersisa", aktualnya **10 file** (9 + base). Sama-sama drift, tapi report menunjuk kalimat yang salah.

## A.3 Yang tidak tercakup

Report jujur menyebut cakupannya (§5) — itu poin plus yang harus diakui. Tapi konsekuensinya: **verdict "Approve" diberikan tanpa membaca ~4.200 baris kode**, termasuk seluruh jalur otorisasi clan dan chat. Bagian B di bawah membaca area itu. Hasilnya: **1 temuan High baru dan 5 Medium baru**, empat di antaranya berada persis di file yang dilewati.

Penilaian akhir atas `iter1-report.md`: **analisis teknisnya solid dan layak dipercaya; klaim verifikasi dan catatan di sekitarnya tidak.** Gunakan temuan H-1/H-2/M-2/M-4-nya apa adanya, abaikan §5 soal macOS, dan balik arah M-3.

---

# BAGIAN B — Audit sesuai `iter1.md`

## B.1 Ringkasan eksekutif

Kesehatan codebase ini di atas rata-rata dan area yang paling berisiko untuk sebuah typing game — integritas skor — justru yang paling kuat: rumus WPM `(chars/5)/menit` konsisten di klien, server solo, dan race; level benar-benar diturunkan dari `total_xp` tanpa kolom tersimpan; dan angka dari client tidak pernah dipercaya (`SoloSessionGuard` menaruh fakta sesi di session store, `AntiCheatService` menghitung ulang, `LongitudinalBaseline` + `ReviewQueue` menangani kasus abu-abu). Otorisasi di jalur yang belum pernah diaudit — clan dan chat — ternyata **rapi**: setiap aksi clan discope ke `clan_id` sendiri, punya rank gate, dan menolak aksi terhadap diri sendiri; balasan chat divalidasi harus satu percakapan.

Tidak ada temuan Critical. Tidak ada SQL injection, XSS (`@js()` dipakai benar di setiap konteks JS), secret ter-hardcode, atau exception yang ditelan diam-diam.

Temuan terberat adalah **tiga bug data yang senyap**: grafik Stats yang menampilkan 200 hasil **terlama** alih-alih terbaru, papan peringkat clan yang menarik seluruh tabel perang ke memori PHP, dan pembubaran clan yang ikut menghapus riwayat perang **lawannya** sementara Elo hasil perang itu tetap tinggal. Ketiganya tidak akan memunculkan error — hanya angka yang salah, dan tak ada yang tahu sampai ada yang mengeluh.

## B.2 Tabel temuan

### Critical
Tidak ada.

### High

| # | Lokasi | Temuan | Mengapa masalah | Saran |
|---|---|---|---|---|
| **H-1** | [`app/Livewire/Stats.php:86-88`](app/Livewire/Stats.php#L86-L88) | **Grafik Stats menampilkan 200 hasil TERLAMA, bukan terbaru.** `->orderBy('created_at')->take(200)` mengurutkan menaik lalu memotong — jadi yang terambil adalah 200 baris paling awal dalam rentang. Komentarnya berbunyi *"Taken ascending then capped so the 'all' range doesn't pull thousands of rows"*, yang menjelaskan alasan capping tapi tidak menyadari bahwa arah urutannya membuang ujung yang salah. | Pada rentang `all`, begitu pemain melewati 200 tes, grafik WPM & akurasinya **membeku permanen** di 200 tes pertama — kemajuan mereka tak pernah muncul lagi. Untuk pemain berat, rentang `30` hari pun bisa melewati 200 tes dan ikut membeku. Ini bug diam: tidak ada error, hanya grafik yang salah dan makin salah seiring pemain makin aktif — persis pemain yang paling peduli pada grafiknya. Tidak tertutup test: `StatsPageTest:136` hanya menguji 1-2 baris, jauh di bawah cap. | Ambil yang terbaru lalu balik urutannya untuk chart: `->latest('created_at')->take(200)->get()->reverse()->values()`. Tambahkan test yang mengunci arah: buat 201 hasil, pastikan titik terakhir grafik adalah hasil **terbaru**, bukan yang ke-200 dari belakang. |
| **H-2** | [`resources/views/livewire/leaderboard.blade.php:79-82`](resources/views/livewire/leaderboard.blade.php#L79-L82) | Subquery kelayakan memindai seluruh `typing_results` tanpa indeks pendukung, dan dieksekusi 2× per render. | *(Mengonfirmasi H-1 iterasi 1 — verifikasi ulang saya cocok sepenuhnya.)* `typing_results` tumbuh satu baris tiap kali siapa pun mengetik; biayanya tidak mengecil oleh filter apa pun yang dipilih user. | Sama seperti iterasi 1: (a) indeks `(user_id, duration_seconds)`, atau (b) kolom `users.total_typing_seconds` yang di-`increment()` bersamaan `addExp()`. Opsi (b) juga menghapus query kedua di `$userRank`. |
| **H-3** | [`database/migrations/2026_07_25_202300_...php:27`](database/migrations/2026_07_25_202300_add_review_status_to_typing_results_table.php#L27) | Tidak ada satu pun indeks yang menutupi bentuk query leaderboard sesungguhnya (`mode + mode_config + language + review_status`). | *(Mengonfirmasi H-2 iterasi 1.)* Dua migrasi menambah dimensi ke query yang sama tanpa saling tahu. | Satu migrasi pengganti: `(mode, mode_config, language, review_status, net_wpm)` + padanan `duration_seconds`. Verifikasi dengan `EXPLAIN`, lampirkan sebelum/sesudah di commit. |
| **H-4** | [`RaceWpmIntegrityTest.php:39-51`](tests/Feature/RaceWpmIntegrityTest.php#L39-L51), [`RaceCountdownSyncTest`](tests/Feature/RaceCountdownSyncTest.php), [`StatsPageTest.php:124-134`](tests/Feature/StatsPageTest.php#L124-L134), [`MultiplayerStatsTest.php:178-188`](tests/Feature/MultiplayerStatsTest.php#L178-L188) | **Suite tidak deterministik — himpunan test yang gagal berubah tiap run.** Dua penyebab berbeda, keduanya terkonfirmasi: <br><br>**(a) Assertion terikat jam nyata.** `RaceWpmIntegrityTest` menyetel `race_starts_at = now()->subSeconds(60)` lalu meng-assert WPM tepat `10`; setiap detik nyata antara setup dan pemanggilan masuk ke penyebut, dan toleransinya hanya ~3,1 detik sebelum `round()` jatuh ke 9. `RaceCountdownSyncTest` gagal dengan `1990 is greater than 2000` — pola yang sama dalam milidetik.<br><br>**(b) `assertDontSee` pada angka telanjang.** `assertDontSee('199')` mencari substring `199` di **seluruh HTML** yang dirender, termasuk bagian yang tidak deterministik (snapshot & checksum Livewire, username hasil faker). Fixture-nya sendiri deterministik, jadi kegagalannya murni undian per run. | Saya verifikasi ini **bukan** kontaminasi database: `uetype_test_1..12` benar-benar dibuat, tiap proses paralel punya DB sendiri. Jadi ini murni kualitas test. Dampaknya mengenai fondasi cara tim ini bekerja: dengan XP + TDD, suite adalah gerbang. Suite yang merah tanpa sebab yang bisa ditelusuri mengajarkan tim untuk mengabaikan warna merah — dan itu jauh lebih mahal daripada satu test yang hilang. Saat ini kegagalan hanya muncul di `--parallel` lokal; CI berurutan masih hijau, sehingga masalahnya tidak terlihat dari CI. | **(a)** Bekukan waktu: `$this->freezeTime()` / `travelTo()` sehingga `now()` konstan. Proyek sudah punya `App\Support\AppTime` + `AppTimeTest`, polanya tinggal dipakai. Sapu semua test yang memakai `now()->subSeconds()` di setup.<br>**(b)** Jangan assert substring angka pada HTML utuh. Assert pada data terstruktur (`->viewData('multiplayerStats')`) atau pakai nilai sentinel yang mustahil bertabrakan (mis. `424242`). |

### Medium

| # | Lokasi | Temuan | Mengapa masalah | Saran |
|---|---|---|---|---|
| **M-1** | [`app/Livewire/ClanLeaderboard.php:21-38`](app/Livewire/ClanLeaderboard.php#L21-L38) | Menarik **seluruh** clan berpenghuni **dan seluruh baris `clan_wars` berstatus Finished** ke memori PHP, lalu menghitung kemenangan dengan `foreach`. Komentarnya menyebut "in one query set" — benar satu query, tapi tanpa `LIMIT` dan tanpa agregasi di DB. | Tumbuh tanpa batas seiring umur aplikasi: setiap perang yang pernah selesai ikut ditarik setiap kali ada yang membuka papan clan, selamanya. Ini masalah yang sama dengan H-2, di halaman yang iterasi 1 tidak pernah buka. Berbeda dengan leaderboard pemain, di sini bahkan tidak ada `take(10)`. | Agregasi di DB: `selectRaw("SUM(CASE WHEN result='win' THEN 1 END)")` di-`groupBy` clan, atau satu query gabungan `UNION` untuk kedua sisi. Tambahkan paginasi/`limit` pada daftar clan. Kunci dengan `QueryBudgetTest` — pola itu sudah dipakai di tempat lain. |
| **M-2** | [`app/Livewire/Clans.php:500-531`](app/Livewire/Clans.php#L500-L531) + [`migration:13-14`](database/migrations/2026_07_05_165903_create_clan_wars_table.php#L13-L14) | **Membubarkan clan ikut menghapus riwayat perang lawan.** `clan_wars` punya `onDelete('cascade')` di *kedua* sisi. `disbandClan()` memblokir pembubaran saat ada perang Pending/Ongoing (komentarnya sadar soal cascade), tapi perang yang sudah **Finished** tetap ikut terhapus. | Clan B mengalahkan clan A, mendapat Elo. Clan A bubar → baris perangnya hilang → di `ClanLeaderboard`, jumlah kemenangan clan B **berkurang**, sementara `clans.power` mereka tetap naik. Papan jadi menampilkan clan ber-power tinggi dengan kemenangan yang tak menjelaskan angkanya, dan `warHistory` di halaman Clan War kehilangan lima-terakhirnya. Tidak ada error, hanya sejarah yang menyusut diam-diam. | Ubah FK jadi `nullOnDelete()` dengan snapshot nama/tag clan di baris `clan_wars` (perang yang selesai adalah fakta historis, bukan milik clan yang masih hidup) — atau soft-delete clan. Pilihan minimal: tetap cascade tapi hitung kemenangan dari kolom denormalisasi `clans.wins`. |
| **M-3** | [`app/Http/Controllers/ChatController.php:94-135`](app/Http/Controllers/ChatController.php#L94-L135) | Aturan **otorisasi** chat ditulis dua kali: `isAcceptedFriend`, `activeClan`, dan validasi target balasan diduplikasi dari [`GuardsChatAccess`](app/Livewire/Concerns/GuardsChatAccess.php) / [`ManagesChatConversation:159-183`](app/Livewire/Concerns/ManagesChatConversation.php#L159-L183). Doc comment-nya menyatakan *"Validation & authorization match App\Livewire\Chat exactly"* — sebuah janji yang hanya dijaga oleh niat baik. | Ini kelas masalah yang sama dengan M-4 iterasi 1, tapi di jalur **keamanan**, jadi taruhannya lebih tinggi: kalau nanti aturan DM diperketat (mis. blokir user), satu sisi bisa terlewat dan endpoint `/chat/send` jadi pintu belakang yang tidak ikut diperketat. Perbedaan kecil sudah ada sekarang — versi Livewire menolak balasan ke pesan yang sudah *deleted for everyone*, versi controller tidak. | Pindahkan ketiga predikat ke satu kelas polos (mis. `App\Support\ChatAccess`) yang bisa dipakai trait **dan** controller. Trait boleh tetap ada, isinya memanggil kelas itu. Tambahkan test yang menjalankan skenario otorisasi yang sama lewat dua pintu. |
| **M-4** | [`app/Livewire/MultiplayerLobby.php:816-850`](app/Livewire/MultiplayerLobby.php#L816-L850) | `sendRoomMessage()` **tidak punya rate limit**, padahal setiap panggilan memicu satu broadcast WebSocket ke seluruh anggota room. Bandingkan: `invitePlayer()` 10/menit, `updateRaceProgress()` punya limit per detik, `/chat/send` 60/menit, `/heartbeat` 30/menit. | Aksi Livewire tidak melewati middleware `throttle` (`bootstrap/app.php` tidak memasang throttle global), jadi tidak ada apa pun yang membatasi klien berskrip membanjiri Reverb lewat pintu ini. Efeknya amplifikasi: satu request → N pengiriman WebSocket. Tim jelas paham soal throttling — `ThrottleTest.php` bahkan mengunci batas di setiap endpoint route — celah ini justru muncul karena aksi Livewire tidak terlihat sebagai "endpoint". | `RateLimiter` per user, pola persis seperti `invitePlayer()` (mis. 20/menit). Sekalian audit aksi Livewire publik lain yang menyiarkan: kandidat berikutnya adalah `ClanWar::claimMode`. |
| **M-5** | [`resources/js/typing-game.js:27-31`](resources/js/typing-game.js#L27-L31) | **Simulasi stamina Survival berjalan sepenuhnya di klien.** Server tidak pernah memverifikasi bahwa pemain benar-benar "mati" pada waktunya — ia hanya membatasi durasi klaim (lewat `claimsMoreTimeThanElapsed`) dan throughput. | Di solo ini tidak berbahaya. Di **Clan War** berbahaya: `survival/hard` adalah slot dengan plafon poin **tertinggi** (150) dan poinnya naik seiring `duration_seconds` sampai 90 detik. Klien yang mematikan kondisi kalah bisa memanen slot paling berharga itu, dibatasi hanya oleh keharusan menunggu 90 detik nyata dan mengetik dengan laju minimum. Pemain jujur mati lebih awal. Digabung dengan M-6 (slack durasi datar 30 detik), ini jalur poin termurah di seluruh Clan War. | Tidak perlu memindahkan simulasi ke server. Cukup satu invarian yang bisa dicek server: rekonstruksi ulang stamina dari `(durasi, karakter benar, kata kotor)` yang sudah dikirim, pakai preset yang sama — jika hasilnya "seharusnya sudah mati jauh sebelum durasi yang diklaim", tandai `pending` lewat jalur `LongitudinalBaseline` yang sudah ada. Cocok dengan prinsip "server sumber kebenaran" yang sudah dipegang di tempat lain. |
| **M-6** | [`app/Services/SoloSessionGuard.php:332`](app/Services/SoloSessionGuard.php#L332) | Slack durasi datar 30 detik, sementara jalur karakter memakai `min(30, durasi × 0.35)`. | *(Mengonfirmasi M-2 iterasi 1, dengan tambahan: komentar konstanta di baris 105 mengklaim slack ini sudah dibatasi `SLACK_FRACTION` — dokumentasi yang keliru, sehingga pembaca berikutnya akan mengira gate ini sudah aman.)* | `min(DURATION_SLACK_SECONDS, $claimedSeconds * SLACK_FRACTION)`. Perbaiki juga komentarnya. Test regresi: klaim 45 detik pada sesi `words/10` berumur 15 detik harus ditolak. |
| **M-7** | [`app/Livewire/ReviewQueue.php:53`](app/Livewire/ReviewQueue.php#L53) | Aturan "hasil ini menaikkan `highest_wpm`" ditulis dua kali. | *(Mengonfirmasi M-4 iterasi 1.)* | `User::recordPersonalBest(TypingResult $result): bool`, dipanggil dari kedua sisi. |
| **M-8** | [`app/Livewire/TypingEngine.php:95`](app/Livewire/TypingEngine.php#L95) | `KEYSTROKE_TIMING_ENFORCED = false` — lapisan anti-bot hanya mencatat log. | *(Mengonfirmasi M-5 iterasi 1.)* Rollout fail-safe adalah keputusan benar, tapi tanpa kriteria terukur ia jadi permanen. | Tulis kriteria aktivasi yang bisa dicek (mis. "N sesi tercatat tanpa flag") + tanggal peninjauan, atau jadikan issue ber-assignee. |
| **M-10** | [`phpunit.xml:18-26`](phpunit.xml#L18-L26) + tidak ada `.env.testing` | **Menjalankan suite secara berurutan menghapus database dev.** `phpunit.xml` sengaja tidak memaku `DB_DATABASE` (ada komentarnya), dan tidak ada `.env.testing`. Akibatnya `RefreshDatabase` menjalankan `migrate:fresh` terhadap `DB_DATABASE` dari `.env` — di mesin ini `uetype`, database dev itu sendiri. | Keputusan "jangan paku DB agar driver salah gagal saat migrasi" itu masuk akal, tapi efek sampingnya tidak diantisipasi: setiap `php artisan test` atau `vendor/bin/pest` **tanpa** `--parallel` menghapus seluruh data lokal — seeder, akun dev, room, war yang sedang diuji manual. Ironisnya `--parallel` justru aman karena Laravel membuat `uetype_test_N` terpisah. Jadi perintah yang lebih "polos" adalah yang merusak, dan itu jebakan yang hanya diketahui setelah kena. Saya sendiri terkena saat mengaudit ini. | Tambahkan `.env.testing` dengan `DB_DATABASE=uetype_test` (dan `.env.testing` masuk `.gitignore`, dengan `.env.testing.example` yang di-track). Niat asli tetap terjaga: driver yang salah tetap gagal saat migrasi, hanya targetnya yang berpindah dari database dev. Sebutkan di README. |
| **M-9** | `MultiplayerLobby.php` (1538), `TypingEngine.php` (1420) | Dua komponen melewati batas sehat; `saveResult()` sendiri ±370 baris menangani 14 concern. | *(Mengonfirmasi M-1 iterasi 1.)* Setelah membaca `MultiplayerLobby` secara penuh, saya menambahkan: kualitas logikanya **tinggi** dan setiap gate punya alasan tertulis — masalahnya murni ukuran, bukan kekusutan. Prioritasnya di bawah temuan data di atas. | Ekstrak `SoloResultPipeline`; pisahkan jalur Clan War dari komponen solo. Lakukan setelah H-1..H-4 beres. |

### Low

| # | Lokasi | Temuan | Saran |
|---|---|---|---|
| **L-1** | `app/Enums/MatchStatus.php`, `MatchType.php`, `TextMode.php`, `Difficulty.php` | **Empat enum mati** — 0 referensi di seluruh `app/`, `routes/`, `resources/`, `tests/`, `database/`. Sisa rancangan lama yang tabelnya sudah di-drop (`matches`, `texts`). Iterasi 1 menyatakan codebase bersih dari dead code, tapi tidak memeriksa `app/Enums/`. | Hapus keempatnya. Satu commit, nol risiko — `LegacyTablesRemovedTest` sudah mengunci bahwa tabelnya tidak kembali. |
| **L-2** | 70 kemunculan `'waiting'` / `'racing'` / `'finished'` di `app/` | Status room adalah magic string, sementara domain lain (clan, friendship, clan war) sudah punya enum. Inkonsistensi tepat di jalur paling ramai. | `App\Enums\RoomStatus`, cast di model `Room`. Bisa dicicil: perkenalkan enum dulu, ganti pemanggil bertahap. |
| **L-3** | `TypingEngine`, `ClanWarAttempt`, `AntiCheatService`, dll. | `TypingResult::$mode` di-cast ke `TypingMode`, tapi hampir semua perbandingan memakai string mentah (`=== 'survival'`), sampai butuh `$row->mode instanceof TypingMode ? ...` di [`Friends.php:208`](app/Livewire/Friends.php#L208) untuk menormalkan. Enum-nya ada tapi setengah dipakai. | Pilih satu: pakai enum konsisten, atau lepas cast-nya. Kondisi sekarang adalah yang terburuk dari keduanya. |
| **L-4** | `app/Models/*` | Gaya cast tidak seragam: 11 model memakai `protected $casts`, `User` memakai `casts()`. | **Koreksi arah dari iterasi 1:** yang menyimpang adalah `User`, bukan `TypingResult`. Samakan `User` ke gaya mayoritas (1 file), bukan sebaliknya (11 file). Keduanya sah di Laravel 12. |
| **L-5** | [`GoogleAuthController.php:99`](app/Http/Controllers/GoogleAuthController.php#L99) | `Log::warning('Google OAuth gagal', ...)` — satu-satunya log berbahasa Indonesia. | Samakan ke Inggris; log dibaca operator dan perkakas, bukan pemain. |
| **L-6** | [`routes/web.php:145-160`](routes/web.php#L145-L160) | `/dev-login?email=` bisa login sebagai user mana pun (sudah dijaga `environment('local')`). | Persempit ke domain dummy (`@uetype.test`) supaya dump produksi yang di-restore ke lokal tidak bisa dipakai masuk sebagai akun nyata. |
| **L-7** | `AntiCheatService.php`, `SoloSessionGuard.php` | Rasio komentar-ke-kode sangat tinggi (11-23 baris komentar untuk 1 baris kode di beberapa konstanta). Isinya berkualitas. | Pindahkan narasi historis ke `docs/wpm-accuracy-integrity.md`, sisakan 1-2 baris + rujukan. **Catatan:** M-6 menunjukkan risiko nyata komentar panjang — komentarnya sendiri jadi tidak sinkron dengan kodenya. |
| **L-8** | `docs/PROJECT_OVERVIEW.md` | Drift dokumentasi: "7 controller" (aktual 10 file), "75 file test / ±526 kasus" (aktual 131 file / ~1108 kasus), masih menyebut `routes/auth.php` Breeze (file sudah tidak ada — auth kini Google-only). | Segarkan §7 dan §10. **Bukan** yang disebut iterasi 1 — doc itu sudah benar soal stub controller yang dihapus. |

## B.3 Quick wins

Urut prioritas, semuanya murah dan berdampak:

1. **`.env.testing` dengan DB terpisah (M-10).** Satu file. Menghentikan suite dari menghapus data dev — dan sampai ini beres, setiap kali seseorang menjalankan test berurutan, kerja manual mereka hilang.
2. **Balik urutan `Stats::series()` (H-1).** Satu ekspresi. Memperbaiki bug data yang saat ini menipu setiap pemain aktif. Tambahkan satu test arah.
3. **Perbaiki 4 test non-deterministik (H-4).** `freezeTime()` untuk yang terikat jam, nilai sentinel untuk `assertDontSee`. Mengembalikan suite ke hijau yang berarti — prasyarat bagi semua perbaikan lain, karena tanpa ini tim tidak bisa membedakan regresi dari noise.
4. **Migrasi indeks leaderboard (H-3 + H-2 opsi a).** Satu migrasi ~10 baris untuk keduanya. Verifikasi dengan `EXPLAIN`.
5. **Hapus 4 enum mati (L-1).** Satu commit, nol risiko.
6. **Rate limit `sendRoomMessage` (M-4).** Lima baris, pola sudah ada di `invitePlayer()`.
7. **Satukan slack durasi + perbaiki komentarnya (M-6).** Satu ekspresi + satu test regresi.
8. **Sentralisasi `highest_wpm` (M-7).** ±15 baris, dua pemanggil.

Nomor 1-6 muat dalam satu sesi. Kerjakan **#1 dan #3 lebih dulu** — mengubah kode di atas suite yang merah karena alasan tak terkait, sementara setiap run menghapus data lokal Anda, adalah cara tercepat kehilangan sinyal sekaligus kehilangan waktu.

## B.4 Catatan positif — yang harus dipertahankan

Semua poin positif di `iter1-report.md` §4 saya verifikasi ulang dan **benar**; tidak saya ulang di sini. Yang berikut adalah tambahan dari area yang iterasi 1 belum baca:

- **Otorisasi clan rapi tanpa satu pun celah.** Setiap aksi di `Clans.php` discope ke `clan_id` milik sendiri, memakai rank gate (`role->rank()`) sehingga co-leader tidak bisa menendang leader, dan menolak aksi terhadap diri sendiri. `changeRole()` **menegaskan** peran asal (`where('role', $from)`), bukan sekadar memakainya untuk lookup — jadi halaman basi tidak bisa mempromosikan orang yang sudah co-leader. `transferLeadership()` memindahkan pivot role dan `clans.leader_id` dalam satu transaksi, dengan alasan tertulis kenapa keduanya harus bergerak bersama.
- **Otorisasi chat menutup IDOR balasan.** `resolveReplyTargetId()` tidak cukup mengecek "boleh lihat pesan ini", tapi juga memastikan pesan target berada di **percakapan yang sama** — sisi DM memeriksa kedua peserta. Tanpa ini, membalas ID pesan sembarang bisa membocorkan kutipan percakapan lain lewat preview balasan. Ini kelas bug yang sering lolos.
- **`MultiplayerLobby` jauh lebih matang dari sekadar besar.** `updateRaceProgress()` menolak progress selama countdown, memaksa progress monoton naik, memblokir spectator, menerapkan rate limit, memvalidasi finish **sebelum** ia bisa memicu sudden death atau mengambil podium, dan mengomentari dengan jujur mana yang *security boundary* dan mana yang sekadar *game rule* yang ditegakkan klien. Kejujuran soal batas kepercayaan itu langka dan lebih berharga daripada klaim aman yang menyeluruh.
- **Endpoint dipisah dari Livewire dengan alasan teknis yang benar.** `ClanWarProgressController` dan `MultiplayerPresenceController` ada karena XHR Livewire dibatalkan saat page unload — dan ping yang paling menentukan justru yang dikirim saat refresh. Keduanya tetap memverifikasi ulang kepemilikan di server.
- **`@js()` dipakai konsisten di setiap konteks JS.** Tidak ada satu pun interpolasi Blade mentah ke dalam `x-data`/`@click`. Bahkan ada komentar yang menjelaskan kapan `{{ }}` terpaksa dipakai di dalam atribut komponen dan mengapa itu aman.
- **CI menegakkan Pint sebagai gerbang, bukan konvensi.** `.github/workflows/tests.yml` menjalankan `pint --test` **sebelum** Pest, plus `composer audit`. Ini yang membuat konsistensi gaya bertahan di proyek yang dikerjakan banyak orang.

## B.5 Cakupan & batasan

**Dibaca isinya di iterasi ini:** `routes/web.php`, `bootstrap/app.php`, seluruh `app/Models/*` ($fillable/$casts), seluruh `app/Enums/*` (cek referensi), seluruh 10 controller, `Clans.php` (jalur otorisasi penuh), `ClanWar.php` (permukaan aksi), `ClanLeaderboard.php`, `Stats.php`, `MultiplayerLobby.php` (mount, kick, invite, chat, `updateRaceProgress`, give up, finalisasi), `ManagesChatConversation.php`, `GuardsChatAccess.php`, `AchievementService`, `SoloSessionGuard`, `ClanWarAttempt`, `ClanWarModeCatalog`, `typing-game.js` (WPM, stamina, shield), migrasi indeks & FK clan, `.github/workflows/tests.yml`, dan verifikasi silang seluruh klaim `iter1-report.md`.

**Belum dibaca baris-per-baris — prioritas iterasi berikutnya:**
- `app/Livewire/Concerns/FinalizesRace.php` + `ReadsRoomState.php` + `ManagesRoomMembership.php` — jalur finalisasi race & penulisan Elo/XP. Ditutupi banyak test (`RaceFinalizationAtomicityTest`, `RaceResultOrderingTest`), tapi belum diperiksa manual.
- `resources/js/race-arena.js` (1105 brs) — word-lock klien yang menjadi dasar invarian "progress hanya naik dari karakter benar" di M-5/`updateRaceProgress`. Kalau invarian itu bocor, gate WPM race ikut bocor.
- `app/Services/ClanWarResolver.php` + `ClanWarScorer.php` — resolusi & penskoran perang; hanya dibaca sebagian lewat `ClanWarAttempt`.
- Mayoritas Blade view (±85 file) — `{!! !!}` dan `@js()`/`x-data` sudah disapu menyeluruh; sisanya (logika presentasi, N+1 di dalam loop view) belum.
- `resources/js/typing-game.js` di luar bagian WPM/stamina (±1000 brs sisanya).

**Soal kegagalan test:** seluruh tujuh kegagalan dari dua run sudah diidentifikasi namanya, dijalankan ulang secara terisolasi (semuanya lolos), dan penyebabnya ditelusuri sampai dua akar yang berbeda di H-4. Yang **belum** saya lakukan: menyapu seluruh 131 file test untuk mencari pola serupa yang belum kebetulan gagal. `grep` untuk `now()->sub` di setup dan `assertDontSee('<angka>')` adalah titik mulai yang murah.

## B.6 Verdict

**Approve with changes.** Tidak ada temuan Critical dan tidak ada yang memblokir rilis dari sisi keamanan — jalur otorisasi, termasuk yang belum pernah diaudit, terbukti rapi.

Tetapi tiga hal harus dikerjakan sebelum pekerjaan lain, dan ketiganya menyerang cara tim ini bekerja, bukan produknya:
- **M-10** — menjalankan test berurutan menghapus database dev. Setiap anggota tim membayar ongkos ini diam-diam.
- **H-4** — suite memberi hasil berbeda tiap run. Untuk tim XP + TDD, suite adalah gerbang; gerbang yang berbohong akan diabaikan, dan sesudah itu TDD-nya tinggal nama.
- **H-1** — grafik Stats menampilkan data salah ke pemain aktif hari ini.

Sisanya bisa dijadwalkan normal. Kesehatan codebase secara keseluruhan baik dan pantas dijaga: kualitas penalarannya terlihat di komentar, dan bagian yang paling sulit dibuat benar — integritas skor — justru yang paling kuat.

---

*Bagian A & B ditulis read-only pada `3e330f4`. Satu-satunya efek samping saat itu adalah `migrate:fresh` pada database `uetype` akibat menjalankan suite berurutan — lihat pengungkapan di kepala dokumen. Bagian C mencatat perbaikannya.*

---

# BAGIAN C — Status perbaikan

Seluruh temuan sudah dikerjakan. Setiap perbaikan disertai test yang gagal sebelum dan lolos
sesudah, sesuai TDD; Pint bersih; Pest dan Vitest hijau.

## C.1 Ringkasan per temuan

| # | Temuan | Perbaikan | Test yang mengunci |
|---|---|---|---|
| **M-10** | Suite berurutan menghapus DB dev | `phpunit.xml` mematok `DB_DATABASE=uetype_test`; hanya NAMA-nya, bukan koneksinya, jadi niat asli (driver salah harus gagal saat migrasi) tetap utuh. README dapat langkah `CREATE DATABASE uetype_test`. | `TestDatabaseIsolationTest` |
| **H-4** | Suite tidak deterministik | Dua akar, dua obat. **Terikat jam:** `freezeTime()`/`travelTo()`/`freezeSecond()` di `RaceWpmIntegrityTest`, `RaceCountdownSyncTest`, `RaceTamperingTest`, `ClanWarProgressPingTest` — dan assertion rentangnya dinaikkan jadi nilai persis (`toBe(3000)`, `toBe(2600)`), yang hanya mungkin setelah jamnya beku. **Substring angka:** `assertDontSee('199')` diganti pemeriksaan `viewData()`. | keempat berkas di atas |
| **H-1** | Grafik Stats menampilkan 200 hasil terlama | `latest()->take(SERIES_LIMIT)->reverse()`; plafonnya jadi konstanta publik supaya test bisa menyeberanginya dengan sengaja. | `StatsPageTest` — "charts the most recent results once the cap is reached" |
| **H-2/H-3** | Indeks leaderboard tak menutupi query | Satu migrasi: `(mode, mode_config, language, review_status, metrik)` untuk kedua papan, `typing_results_review_index` dibuang (setiap prefix-nya sudah tercakup), plus `(user_id, duration_seconds)` untuk gerbang kelayakan. **Diverifikasi `EXPLAIN`:** gerbang kelayakan kini `Using index` (covering, tanpa full scan). | `LeaderboardIndexShapeTest` — mengunci URUTAN kolom, bukan sekadar keberadaannya |
| **M-1** | `ClanLeaderboard` menarik seluruh `clan_wars` ke PHP | Agregasi `COUNT(*) … GROUP BY` di DB, dua query, terbatas jumlah clan alih-alih jumlah perang. | `ClanLeaderboardTest` — "counts wins in the database" |
| **M-2** | Bubar clan menghapus riwayat perang lawan | FK `clan_wars` jadi `nullOnDelete()` + snapshot nama kedua clan, ditulis `ClanWar::booted()` supaya tak ada call site yang bisa lupa. View & `warSummary()` tahan lawan yang sudah bubar; `x-clan-emblem` jadi null-safe. | `ClanWarHistorySurvivesDisbandTest` (5 test) |
| **M-3** | Otorisasi chat ditulis dua kali | `App\Support\ChatAccess` — kelas polos, dipakai trait Livewire **dan** controller. Sekalian menutup celah kecil: balasan ke pesan yang sudah ditarik pengirimnya kini ditolak di jalur yang benar-benar dilewati setiap balasan, bukan hanya di `startReply()`. | `ChatAccessParityTest` — skenario sama lewat dua pintu |
| **M-4** | `sendRoomMessage` tanpa rate limit | 20/menit per user, dicek sesudah keanggotaan. | `MultiplayerLobbyTest` — "caps how many messages one player can flood" |
| **M-5** | Stamina Survival tak terperiksa server | `SurvivalPlausibility`: lantai fisik karakter minimum untuk durasi yang diklaim, tiap suku condong ke pihak pemain. **Ditahan (`pending`), bukan ditolak.** | `SurvivalPlausibilityTest` (7 test), termasuk yang membaca berkas JS dan memastikan preset PHP tak hanyut |
| **M-6** | Slack durasi datar 30 detik | `SoloSessionGuard::slackFor()` — satu definisi untuk kedua gerbang. Komentar konstanta yang keliru ikut dibetulkan. | `SoloResultTamperingTest` — kasus tolak **dan** kasus pemain jujur yang telat |
| **M-7** | Aturan `highest_wpm` ditulis dua kali | `User::recordPersonalBest()`, dipanggil jalur simpan & jalur approve. | `PersonalBestScopeTest` — "defines a personal best in one place" |
| **M-8** | `KEYSTROKE_TIMING_ENFORCED` tanpa kriteria | Kriteria terukur ditulis di konstantanya (<1 dari 500 hasil ber-flag selama ≥14 hari) + tanggal peninjauan 2026-09-01. | — (keputusan, bukan perilaku) |
| **M-9** | Dua komponen >1000 baris | **Sengaja tidak dikerjakan** — lihat §C.3. | — |
| **L-1** | 4 enum mati | Dihapus. | — |
| **L-2** | Status room sebagai magic string | `App\Enums\RoomStatus` + cast di model; ~70 pemanggil dikonversi. `$step` sengaja tetap string — enum-nya justru membuat percampuran keduanya terlihat. | seluruh test race/room yang ada |
| **L-4** | Gaya `casts` tak seragam | `User` disamakan ke gaya mayoritas (1 file), bukan sebaliknya (11 file). | — |
| **L-5** | Log campur bahasa | `'Google OAuth failed'`. | — |
| **L-6** | `/dev-login` bisa jadi siapa saja | Dibatasi ke domain seeder (`@uetype.test`, `@example.com`). | — |
| **L-7** | Rasio komentar tinggi | Narasi historis M-6 dipindah ke `docs/features/anti-cheat-wpm.md` §7.4. | — |
| **L-8** | Drift dokumentasi | `PROJECT_OVERVIEW.md`: jumlah controller/test/migrasi/service, auth Google-only, `RoomInvitationSent`, `SurvivalPlausibility`, catatan DB test & determinisme. `anti-cheat-wpm.md`: §7.7e baru + koreksi §7.4. README: bagian Testing ditulis ulang. | — |

## C.2 Dua bug tambahan yang ditemukan saat memperbaiki

Keduanya bukan bagian dari audit awal; keduanya muncul karena perbaikannya menuntut test baru.

1. **`review_status` punya default di DB tapi tidak di model.** Baris yang dibuat tanpa
   menyebut kolom itu kembali dari `create()` dengan atributnya **belum ter-set**, jadi
   membacanya di PHP menghasilkan `null`, bukan `'clear'`. Aturan apa pun yang bertanya "apakah
   hasil ini sudah clear?" akan menjawab tidak — termasuk rekor pribadi yang sah. Diperbaiki
   dengan `protected $attributes` di `TypingResult` supaya model sepakat dengan skemanya sejak
   ia dibuat. Ditemukan oleh test M-7.

2. **`happy-dom` tidak terpasang di `node_modules` lokal.** Sudah terdaftar di `package.json`,
   jadi CI (`npm ci`) aman — tapi di mesin ini `focus-mode.test.js` gagal dimuat dan Vitest
   melaporkan "4 passed" seolah lengkap, padahal 1 dari 5 berkas tak pernah jalan. `npm install`
   menyelesaikannya; sekarang **5 berkas, 30 test**.

## C.3 Yang sengaja TIDAK dikerjakan

**M-9 (memecah `MultiplayerLobby` 1538 brs & `TypingEngine` 1420 brs).** Ini satu-satunya
temuan yang saya biarkan, dan alasannya bukan kehabisan waktu.

Setelah membaca keduanya secara penuh, masalahnya murni **ukuran**, bukan kekusutan: tiap
gerbang punya alasan tertulis, dan `saveResult()` adalah **satu-satunya gerbang tulis** untuk
hasil solo sekaligus titik integrasi Clan War. Memecahnya berarti memindahkan 14 concern yang
saling terkait — persis pekerjaan yang paling butuh suite tepercaya sebagai jaring. Suite itu
baru saja jadi tepercaya (H-4 + M-10 selesai hari ini), dan menumpangkan refactor terbesar di
proyek pada perbaikan yang belum sempat dipakai siapa pun adalah urutan yang salah.

Rekomendasi: kerjakan setelah beberapa siklus dengan suite yang stabil, mulai dari
`SoloResultPipeline` seperti di M-9. Itu keputusan tim, bukan keputusan yang pantas saya ambil
diam-diam di tengah pekerjaan lain.

## C.4 Verifikasi akhir

- **Pint:** `{"tool":"pint","result":"passed"}`
- **Vitest:** 5 berkas, 30 test, hijau (sebelumnya 4 berkas — lihat §C.2)
- **Pest:** **1136 passed, 3419 assertions, 0 gagal**, dijalankan `--parallel` — **empat run
  berturut-turut memberi angka yang identik** (dua sebelum penyisiran §C.6, dua sesudahnya).

Bandingkan dengan kondisi awal: 1105 passed **dan 3 gagal**, dengan himpunan yang berbeda di
tiap run. Suite sekarang bukan cuma lebih hijau, tapi **hijau yang sama berulang kali** — dan
itu, bukan angkanya, yang membuatnya bisa dipakai sebagai gerbang lagi.

## C.5 Tiga test yang gagal di run pertama sesudah perbaikan

Layak dicatat karena dua di antaranya menceritakan sesuatu:

1. **`LeaderboardLanguageTest`** — gagal karena **perbaikan M-6 bekerja**. Test itu memanggil
   `saveResult` seketika sambil mengklaim durasi 30 detik, dan lolos selama ini justru berkat
   kelonggaran 30 detik datar yang baru saja ditutup. Diperbaiki dengan `backdate(30)`, pola
   yang sudah dipakai `SoloResultTamperingTest` dengan alasan tertulis. Ini bukan test yang
   "dibetulkan supaya lewat" — ia memang sedang menguji bahasa, bukan gerbang durasi.

2. **`RaceTamperingTest`** dan **`ClanWarProgressPingTest`** — dua kasus H-4 yang belum ketahuan
   di dua run pertama saya, keduanya terikat jam nyata. Keduanya lolos saat dijalankan sendiri
   dan gagal di bawah beban paralel, persis pola yang bikin suite ini tak bisa dipercaya.
   Dibekukan dengan `freezeTime()`/`freezeSecond()`.

Artinya sapuan H-4 saya di awal belum lengkap: saya memperbaiki yang gagal, bukan yang
*berpotensi* gagal. Penyisirannya lalu dikerjakan — lihat §C.6.

## C.6 Penyisiran H-4 sampai tuntas

41 berkas test memakai `now()->sub` di setup. Menyisirnya berarti memutuskan **mana yang
benar-benar rapuh**, bukan membekukan 41-41-nya: pembekuan yang tak perlu adalah kebisingan
yang membuat pembaca berikutnya berhenti mempercayai alasannya.

Kriterianya ternyata bukan setup-nya, melainkan **apa yang diassert**:

| Aman — nilai **disimpan** | Rapuh — nilai **diturunkan dari jam** |
|---|---|
| `finished_time_seconds` yang ditulis langsung ke baris (`GiveUpHistoryTest`, `RaceDeadlineTest:223`) | WPM race: `karakter / (now() − race_starts_at)` |
| `wpm` yang disalin apa adanya oleh `finalizeRace()` (`MultiplayerStatsTest`) | `attempt_live_ms`, `attempt_carried_ms` |
| `DNF_SENTINEL_SECONDS` — konstanta, bukan pengukuran | `raceStartsInMs`, `suddenDeathRemaining` |
| `duration_seconds` mode words/survival: datang dari payload, bukan jam | `duration_seconds` yang menyertakan ledger berjalan |

Yang lolos dari sapuan pertama dan sekarang dibekukan:

- **`RaceWpmIntegrityTest`** — ketiga test sisanya, semuanya WPM turunan jam. Dipindah ke
  `beforeEach` karena sifat itu milik **berkas** ini, bukan satu test: menaruhnya per test
  berarti test yang ditambahkan orang lain besok lahir tanpa perlindungan yang sama.
  Toleransi terketatnya cuma ~1,5 detik (`toBe(20)`).
- **`RaceLiveWpmTest`** — "updates wpm on the server without moving progress", `toBe(8)`,
  toleransi ~4 detik.
- **`ClanWarResumeLedgerTest`** — "never lets a refresh buy characters beyond the attempt wall
  budget". Ini yang paling menarik: ambangnya sengaja dilonggarkan ke 11 detik untuk batas 10
  detik, **dengan komentar yang mengakui alasannya**. Padding itu persis obat yang gagal di
  `ClanWarProgressPingTest` (21019 > 21000). Padding tak pernah menyelesaikan masalah ini — ia
  hanya memindahkan titik gagalnya. Dengan jam beku, ambangnya **dikembalikan ke 10 detik yang
  sebenarnya**, dan testnya jadi lebih tajam sekaligus lebih tenang.

Aturan praktis ini ikut ditulis ke `docs/PROJECT_OVERVIEW.md` §10, termasuk catatan bahwa
melonggarkan ambang bukan perbaikan.

Kesehatan codebase secara keseluruhan baik dan pantas dijaga: kualitas penalarannya terlihat di komentar, dan bagian yang paling sulit dibuat benar — integritas skor — justru yang paling kuat.
