# Audit Code Quality — UeType (iter3, independen)

**Tanggal:** 2026-08-04 · **Branch:** `development` @ `223877e` · **Sifat:** read-only, tidak ada kode yang diubah.
**Lingkup:** sisa cakupan yang tercatat di `iter1-report-verified.md` §5 dan `iter2-report-verified.md` §5 — `app/Services/`, bagian `MultiplayerLobby` yang belum dibaca, `ClanWar.php`, sapuan tipis Support/Middleware/Commands, aspek non-XSS Blade, dan kualitas test.

---

## 0. Catatan Cakupan yang Harus Dibaca Lebih Dulu

**0.1 Prasyarat ada di jalur lain.** `iter3.md` meminta membaca `docs/iteration/iter1-report.md` dan
`docs/iteration/iter2-report.md`. Direktori itu tidak ada. Yang saya pakai adalah
[`iter1-report-verified.md`](iter1-report-verified.md) dan
[`iter2-report-verified.md`](iter2-report-verified.md) di root — keduanya memuat bagian "Status
Perbaikan" yang tidak ada di versi `iter*.md` rekan, dan itu penting (lihat 0.2).

**0.2 Basis commit berbeda dari laporan rekan.** [`iter3-report.md`](iter3-report.md) ditulis pada
`3e330f4`. Audit ini berjalan pada `223877e`, dua commit lebih baru (88 berkas, +3.343/−404).
Konsekuensinya nyata dan mengubah kesimpulan:

| Temuan iter2 | Status di `223877e` |
|---|---|
| H-1 — Elo double-apply di `ClanWarResolver` | **Ditutup.** `settleWar()` memakai conditional update dalam `DB::transaction`; dikunci `ClanWarResolutionIdempotencyTest` |
| H-2 / "H-3 rekan" — channel identitas publik | **Ditutup.** 8 event pindah ke `PrivateChannel`, `routes/channels.php` berisi otorisasi nyata via `ChannelAccess`; dikunci `PrivateChannelAuthorizationTest` |
| H-1/H-2 rekan — performa leaderboard | **Ditutup.** Migrasi `realign_leaderboard_indexes_on_typing_results_table`; dikunci `LeaderboardIndexShapeTest` |

Jadi pernyataan `iter3-report.md` §1/§3/§6 bahwa "H-3 tetap satu-satunya temuan mendesak dan tetap
belum dikerjakan" **sudah tidak berlaku**. Temuan Medium/Low-nya sendiri saya verifikasi ulang dan
sebagian besar masih valid (§2).

**0.3 Tidak dijalankan:** suite Pest, `EXPLAIN` atas query mana pun, dan reproduksi konkurensi
sungguhan. Temuan konkurensi di bawah dibuktikan dari **struktur kode** (ada/tidaknya conditional
update dan transaksi), bukan dari race yang benar-benar dipicu. Itu batas yang sama yang dinyatakan
iter2 §0, dan saya menyatakannya lagi karena temuan terberat iter3 ini bergantung padanya.

---

## 1. Ringkasan Eksekutif

`app/Services/` — area prioritas tertinggi yang belum pernah dibaca — ternyata memang lapisan
paling rapi di proyek ini, dan saya sepakat dengan penilaian rekan pada titik itu. Temuan terberat
justru muncul di tempat yang berdekatan dengan kode terbaik proyek ini: **dua *fast-path* penutupan
race di `MultiplayerLobby` (`updateRaceProgress()` dan `giveUp()`) menutup balapan dengan
read-then-write, melewati `closeRaceNow()` yang justru dibangun persis untuk mencegah itu** — pola
yang sama persis dengan H-1 iter2 yang baru saja diperbaiki di `ClanWarResolver`, dengan kerusakan
yang sama sifatnya: XP ganda dan baris riwayat duplikat, permanen dan senyap.

Temuan kedua adalah **satu aturan anti-cheat yang ditegakkan di satu dari dua jalur rekor**:
`TypingResult::bestNetWpmFor()` tidak menyaring `review_status`, sehingga hasil yang ditahan
(`pending`) — atau bahkan yang sudah **ditolak admin** — tetap menjadi rekor per-mode pemain dan
pace Ghost, padahal `User::recordPersonalBest()` dan leaderboard sama-sama menolaknya.

Kualitas test substantif dan jelas ditulis dari bug nyata, bukan demi angka coverage. Satu titik
buta yang layak dicatat: `MultiplayerStatsTest` menguji `finalizeRace` "dua kali" secara
**berurutan**, blind spot yang bentuknya identik dengan `ClanWarEarlyFinishTest` di iter2 — dan itu
persis alasan H-1 di bawah bisa lolos suite hijau.

---

## 2. Tabel Temuan

### Critical
Tidak ada.

### High

| # | Lokasi | Temuan | Mengapa ini masalah | Saran |
|---|---|---|---|---|
| **H-1** | [`MultiplayerLobby.php:1155-1169`](app/Livewire/MultiplayerLobby.php#L1155-L1169) (fast-path `updateRaceProgress`) dan [`:1202-1214`](app/Livewire/MultiplayerLobby.php#L1202-L1214) (fast-path `giveUp`); bandingkan [`closeRaceNow():1437-1446`](app/Livewire/MultiplayerLobby.php#L1437-L1446) | **Dua jalur penutupan race memakai read-then-write, bukan conditional update.** Keduanya berbentuk `$unfinished = ...->count(); if ($unfinished === 0) { $room->update(['status' => Finished]); $this->finalizeRace(...); }`. `$room->update()` di sini **tanpa syarat** — tidak ada `where('status', Racing)`. `closeRaceNow()` di file yang sama melakukan hal yang benar dan docblock-nya menjelaskan mengapa: *"exactly ONE caller — of any number of concurrent progress emits and timer pings — performs the finalize"*. Kedua fast-path tidak ikut aturan itu, jadi mereka tidak berpartisipasi dalam klaim yang melindungi jalur ketiga. | **Jendelanya nyata dan kerusakannya permanen.** Dua skenario: (a) dua pemain finis dalam beberapa milidetik yang sama — keduanya membaca `unfinished = 0` lalu keduanya finalize; (b) yang lebih lebar, fast-path bertabrakan dengan `closeRaceNow()` yang dipicu ping timer sudden-death — dan momen paling mungkin keduanya terjadi bersamaan adalah persis saat pemain terakhir finis di detik-detik akhir countdown. Satu-satunya pengaman yang tersisa adalah `is_null($member->xp_earned)` di [`FinalizesRace.php:131`](app/Livewire/Concerns/FinalizesRace.php#L131) — tapi itu **read-then-write di dalam transaksi ber-snapshot isolation**, jadi dua transaksi paralel sama-sama membaca `null` dan sama-sama menulis. Akibatnya: `addExp()` dipanggil dua kali (dan [`User::addExp():142`](app/Models/User.php#L142) memakai `increment()`, yang — memakai kalimat docblock `ClanWarResolver` sendiri — *"atomic per column, but that never protected against running the whole settlement twice"*), plus **baris `multiplayer_match_history` duplikat**, yang tabelnya tidak punya unique constraint apa pun (hanya `index(['user_id','created_at'])`). `total_xp` tidak pernah dihitung ulang dari riwayat, jadi sama seperti `clans.power`: sekali melenceng, tak ada sumber kebenaran untuk membetulkannya. | Rutekan **kedua** fast-path lewat `closeRaceNow()` alih-alih menulis status sendiri. Itu bukan sekadar perbaikan konkurensi, tapi penerapan alasan yang sudah tertulis di docblock `closeRaceNow()`: tiga deadline sudah disatukan di sana justru supaya "race berakhir" tidak punya tiga definisi — dan dua fast-path ini adalah definisi keempat dan kelima yang belum ikut. Kalau perilaku "semua sudah finis" perlu tetap terpisah dari DNF-sweep, minimal ganti `$room->update([...])` menjadi conditional `Room::where('id',...)->where('status', Racing)->update([...])` dan hanya lanjut kalau affected-rows = 1. **Test yang mengunci:** panggil `finalizeRace()` dua kali dengan dua instance komponen berbeda tanpa me-refresh state di antaranya, lalu pastikan `multiplayer_match_history` tetap satu baris per pemain dan `total_xp` hanya bergerak sekali. Test yang ada sekarang tidak cukup — lihat §5. |

### Medium

| # | Lokasi | Temuan | Mengapa ini masalah | Saran |
|---|---|---|---|---|
| **M-1** | [`TypingResult::bestNetWpmFor():110-119`](app/Models/TypingResult.php#L110-L119); pemakainya [`GhostResolver::bestWpmIn():59-66`](app/Services/GhostResolver.php#L59-L66) dan [`TypingEngine::resolvePersonalBest():1325`](app/Livewire/TypingEngine.php#L1325); varian survival di [`:1313`](app/Livewire/TypingEngine.php#L1313) | **Rekor per-mode tidak menyaring `review_status`.** Query-nya hanya `where user_id + mode + mode_config` lalu `max('net_wpm')`. Padahal dua jalur sebelah menyaringnya secara eksplisit: leaderboard di [`leaderboard.blade.php:74`](resources/views/livewire/leaderboard.blade.php#L74) memakai `whereIn('review_status', [CLEAR, APPROVED])`, dan [`User::recordPersonalBest():161-163`](app/Models/User.php#L161-L163) menyatakan aturannya hitam di atas putih: *"only a cleared result counts: a run held for anti-cheat review must not put a flagged number on the profile before a human has looked at it."* | Aturan itu ada, tapi hanya ditegakkan di **satu** dari dua angka "rekor". Konsekuensi konkret: run 200 WPM yang ditahan `pending` (persis payload yang di-pin `PatientBotTest`) **tidak** naik ke `highest_wpm` — tapi ia tetap (a) muncul sebagai "My Best" di GhostPicker, (b) jadi pace ghost yang dikejar teman yang memilihnya, dan (c) jadi `previousBest` di layar hasil, sehingga run jujur pemain itu berikutnya tak pernah lagi ditandai PB. Yang paling tajam: status `rejected` — hasil yang **sudah dinilai curang oleh admin** — tetap dihitung selamanya, karena [`ReviewQueue::reject()`](app/Livewire/ReviewQueue.php#L60-L67) hanya mengubah kolom, tidak menghapus baris. Varian survival lebih serius lagi secara prinsip: `max('duration_seconds')` adalah metrik papan survival itu sendiri, dan `SurvivalPlausibility` menahan run implausible sebagai `pending` justru karena stamina disimulasikan di klien — lalu angka yang ditahan itu tetap jadi rekor yang ditampilkan. Ini bentuk yang sama persis dengan bug yang docblock `recordPersonalBest()` ceritakan sudah pernah diperbaiki ("dua definisi yang harus tetap identik selamanya"); definisi ketiganya belum ikut. | Tambahkan `->whereIn('review_status', [TypingResult::REVIEW_CLEAR, TypingResult::REVIEW_APPROVED])` di `bestNetWpmFor()` dan di cabang survival `resolvePersonalBest()`. Satu baris di dua tempat, dan `bestNetWpmFor()` memang sudah dibangun sebagai satu sumber kebenaran untuk tiga pemanggil — jadi memperbaikinya di sana otomatis menutup Ghost, GhostPicker, dan layar hasil sekaligus. **Test:** simpan satu baris `pending` 200 WPM + satu baris `clear` 80 WPM di config yang sama, lalu pastikan `bestNetWpmFor()` mengembalikan 80 dan GhostPicker "My Best" tidak menampilkan 200. Belum ada test yang menyentuh ini (§5). |
| **M-2** | [`MultiplayerLobby.php:639`](app/Livewire/MultiplayerLobby.php#L639) dan [`:658`](app/Livewire/MultiplayerLobby.php#L658) | **`toggleSpectator()` memeriksa kuota tanpa transaksi/`lockForUpdate()`**, sementara `joinRoomByCode()` di file yang sama membungkus pemeriksaan setara dalam `DB::transaction` + `lockForUpdate()` ([`:463-470`](app/Livewire/MultiplayerLobby.php#L463-L470)) dengan komentar yang menjelaskan tepat mengapa. | *(Konfirmasi independen atas M-9 di [`iter3-report.md`](iter3-report.md); saya membaca ulang di `223877e` dan temuannya masih berlaku persis.)* Dua spectator yang menekan "jadi racer" bersamaan sama-sama membaca `count() = 4` dan keduanya lolos → 6 racer pada batas 5. Dampaknya lebih ringan daripada di jalur join dan tidak bisa dipicu penyerang dari luar. Yang membuatnya layak dikerjakan bukan besar dampaknya, melainkan bahwa **pengetahuan tentang bug ini sudah ada di file yang sama**, hanya tidak diterapkan di sini. Perhatikan ini satu keluarga dengan H-1: keduanya adalah gerbang state yang ditulis sebagai baca-lalu-tulis di komponen yang sudah menguasai obatnya. | Bungkus kedua cabang dalam `DB::transaction` dengan `lockForUpdate()` pada hitungan kuota, mengikuti pola `joinRoomByCode()` persis. ±8 baris, penyelarasan bukan desain baru. Kerjakan bersama H-1 — keduanya menyentuh file yang sama dan menegakkan aturan yang sama. |

### Low

| # | Lokasi | Temuan | Saran |
|---|---|---|---|
| **L-1** | [`routes/web.php:95-98`](routes/web.php#L95-L98) vs [`EnsureUserIsAdmin.php:10-16`](app/Http/Middleware/EnsureUserIsAdmin.php#L10-L16) dan [`UserMonitoringServiceProvider.php:65`](app/Providers/UserMonitoringServiceProvider.php#L65) | **Janji 404 middleware tidak berlaku untuk tamu di `/review-queue`.** Docblock `EnsureUserIsAdmin` menyatakan alasannya eksplisit: *"That is also why 'auth' is not used alongside this — it redirects guests to login and gives the URL away."* Dashboard monitoring menaatinya (`Route::middleware(['web', EnsureUserIsAdmin::class])`, tanpa `auth`). Tapi `/review-queue` didaftarkan **di dalam** `Route::middleware('auth')->group(...)`, jadi tamu mendapat 302 ke login — yang membuktikan route itu ada, sementara URL karangan mendapat 404. | Pindahkan route `/review-queue` keluar dari grup `auth` dan biarkan `EnsureUserIsAdmin` sendirian, sama seperti monitoring. Kebocorannya kecil (hanya keberadaan route), tapi ini aturan yang ditulis sendiri lalu dilanggar oleh wiring-nya, dan biayanya satu baris. Testnya juga perlu ditambah: `LongitudinalReviewTest:124` hanya menguji non-admin yang **sudah login**, tidak pernah tamu. |
| **L-2** | [`RoomMembershipService.php:34-37`](app/Services/RoomMembershipService.php#L34-L37) dan [`:247`](app/Services/RoomMembershipService.php#L247) | **Docblock menjanjikan transaksi yang metodenya tidak buka.** `departCurrentRooms()` didokumentasikan *"Wrapped in a transaction: … there must be no window where a concurrent read sees an inconsistent state"*, tapi metodenya sendiri tidak memanggil `DB::transaction` — ia bergantung pada pemanggil. Akibatnya `settleAbandonedRoom()` yang memakai `lockForUpdate()` menjadi **no-op** bila ada pemanggil yang lupa: di luar transaksi, `SELECT … FOR UPDATE` tidak menahan apa pun setelah statement selesai. Keempat pemanggil saat ini patuh ([`:75`](app/Services/RoomMembershipService.php#L75), [`MultiplayerLobby:377`](app/Livewire/MultiplayerLobby.php#L377), [`:463`](app/Livewire/MultiplayerLobby.php#L463), dan trait `ManagesRoomMembership` yang docblock-nya menyatakan kontrak itu), jadi ini kerapuhan, bukan bug hidup. | Pindahkan janji itu ke tempat yang menegakkannya: entah `departCurrentRooms()` membuka transaksinya sendiri (nested transaction Laravel aman untuk pemanggil yang sudah punya satu), atau docblock-nya diubah menjadi "pemanggil WAJIB menyediakan transaksi — `lockForUpdate()` di `settleAbandonedRoom()` bergantung padanya". Yang tidak boleh dibiarkan adalah komentar yang menyatakan jaminan yang tidak dimilikinya, karena pembaca berikutnya akan menambah pemanggil kelima atas dasar itu. |
| **L-3** | [`TextGeneratorService.php:73-101`](app/Services/TextGeneratorService.php#L73-L101) | **"Cached in process memory" tidak akurat, dan `rememberForever` punya efek samping operasional.** Docblock menyebut cache in-process, tapi `Cache::rememberForever()` memakai store default — dan `CACHE_STORE=database` di `.env` maupun `.env.example`. Jadi setiap `generateText()` adalah query ke tabel `cache` yang mengembalikan seluruh wordlist untuk di-`unserialize`, bukan pembacaan memori. Terpisah dari itu: `rememberForever` berarti menyunting `database/data/*.json` **tidak berpengaruh sama sekali** sampai seseorang menjalankan `cache:clear` — jebakan yang tidak terdokumentasi di mana pun. | Ubah komentarnya agar jujur, dan pertimbangkan memoisasi array statis di properti kelas **di atas** cache store (itu barulah "process memory", dan itu yang sebenarnya diinginkan). Tambahkan catatan `cache:clear` di dokumen wordlist atau `PROJECT_OVERVIEW.md` §12 bersama jebakan `npm run build` — bentuknya sama persis: perubahan yang tampak mendarat padahal tidak. |
| **L-4** | [`GhostResolver::resolveLeaderboard():113-124`](app/Services/GhostResolver.php#L113-L124) via [`TypingEngine::ingestGhostDeepLink():328-354`](app/Livewire/TypingEngine.php#L328-L354) | **Deep link `?ghost=<user_id>` adalah oracle ID→username.** `resolveLeaderboard()` menerima `refId` apa pun tanpa memeriksa apakah user itu benar-benar ada di papan, lalu mengembalikan `username` + WPM-nya untuk ditampilkan. Pemain login dapat menyapu `?ghost=1,2,3…` lintas 8 kombinasi mode/config dan memanen username + rekor untuk user mana pun yang pernah mengetik — termasuk yang tak pernah muncul di top-10 mana pun. Ini persis yang [`routes/web.php:44-46`](routes/web.php#L44-L46) nyatakan sebagai alasan profil dikunci ke username: *"so user IDs (and the total registered-user count) can't be enumerated by changing a number in the URL."* | Batasi `resolveLeaderboard()` ke user yang memang lolos gerbang kelayakan papan untuk mode/config itu (papan sudah punya filter tersebut), sehingga oracle-nya tak pernah mengonfirmasi lebih dari yang sudah publik. Bonus: perbaikan ini beririsan dengan M-1 — keduanya tentang `bestNetWpmFor()` yang mengembalikan angka yang papan sendiri tak mau tampilkan. |
| **L-5** | [`ClanWarResolver::resolveFinishedWars():38-50`](app/Services/ClanWarResolver.php#L38-L50) | `resolveDue()` dipanggil dari `ClanWar::mount()`, dan ia memuat **seluruh** war `Ongoing` secara global, lalu untuk setiap war yang belum lewat `ends_at` menjalankan `clanHasNothingLeftToPlay()` (1 query klaim + kadang 1 query anggota per sisi). Artinya satu pemain membuka `/clan-war` mengerjakan pekerjaan yang sebanding dengan jumlah war aktif **milik orang lain**. | Bukan masalah pada skala sekarang (beberapa war aktif = beberapa query), dan lazy-resolve memang keputusan sadar yang terdokumentasi. Catat batasnya: bila jumlah war aktif tumbuh, batasi loop ke war yang relevan bagi pemanggil ditambah satu sapuan periodik lewat `clan-war:resolve` yang sudah ada. Yang perlu dihindari adalah menemukan batas ini lewat halaman yang melambat, bukan lewat keputusan. |
| **L-6** | [`MultiplayerLobby::createRoom():369`](app/Livewire/MultiplayerLobby.php#L369) | Kode room `strtoupper(Str::random(6))` dibuat tanpa cek tabrakan maupun retry, sementara `rooms.code` unik. Tabrakan (walau jarang) menghasilkan `QueryException` tak tertangani di dalam transaksi — layar 500, bukan pesan yang bisa ditindaklanjuti. | Bungkus dalam retry kecil (`for ($i = 0; $i < 5; $i++)` dengan cek `Room::where('code', $code)->exists()`), atau tangkap unique violation dan buat ulang — pola yang sudah dipakai `claimMode()` di [`ClanWar.php:334-337`](app/Livewire/ClanWar.php#L334-L337). Konsisten dengan cara proyek ini sudah menangani unique violation di tempat lain. |
| **L-7** | [`ClanWar::acceptChallenge():520-539`](app/Livewire/ClanWar.php#L520-L539) | `pendingChallengeForMyLeadership()` menyeleksi `status = Pending`, lalu `$war->update([... 'status' => Ongoing ...])` tanpa syarat. Dua klik bersamaan menulis dua kali (menggeser `started_at`/`ends_at` dan me-snapshot ulang cap klaim); lebih halus lagi, sebuah war yang baru saja di-`Expired` oleh `expirePendingChallenges()` bisa "dihidupkan" oleh accept yang membaca sesaat sebelumnya. | Jadikan conditional: `ClanWarModel::where('id', $warId)->where('status', Pending)->update([...])` dan hanya lanjut bila affected-rows = 1 — pola yang sudah dipakai `settleWar()` tepat di sebelahnya. Jendelanya sangat sempit dan dampaknya kecil, jadi ini kerapuhan, bukan bug hidup; masuk akal dikerjakan bersama H-1 sebagai satu penyisiran "gerbang state = conditional update". |
| **L-8** | [`EloCalculator.php:12`](app/Services/EloCalculator.php#L12) | `K_FACTOR = 32` tetap untuk semua clan tanpa memandang jumlah war yang sudah dimainkan. | *(Sepakat dengan L-10 rekan.)* Tidak mendesak — K tinggi justru membantu rating menemukan levelnya saat basis pemain kecil. Catat sebagai keputusan sadar di docblock agar tidak terbaca sebagai kelalaian, dan tinjau ulang saat war per clan sudah signifikan. |
| **L-9** | Sapuan tipis: `app/Console/Commands/` (4 berkas, 200 brs), `app/Http/Middleware/` (4 berkas, 191 brs), `app/Support/` (17 berkas, 1.098 brs) | Semuanya kecil dan fokus; tidak ditemukan logika bisnis yang bocor ke lapisan ini. `ResolveClanWars` (27 brs) hanya mendelegasikan; `MakeUserAdmin` punya test sendiri. `ChannelAccess` (43 brs, baru di `223877e`) rapi dan docblock-nya menjelaskan mengapa aturannya diangkat keluar dari `routes/channels.php` — karena `NullBroadcaster::auth()` membuat test terhadap endpoint auth hijau tanpa membuktikan apa pun. | Tidak ada aksi. Dicatat agar cakupan tercatat lengkap. |
| **L-10** | Blade, aspek non-XSS | Tidak ditemukan kebocoran data privat baru: `profile/show.blade.php` sudah diperbaiki di iter2 (email diselesaikan di controller, bukan dijaga `@if` di view) dan itu satu-satunya view yang melayani dua tingkat privasi. Pemindaian loop untuk N+1 juga bersih — `review-queue` memakai `with('user')`, `leaderboard` menyiapkan relasi lewat map ber-key di PHP, `clan-leaderboard` menerima baris yang sudah diagregasi. | Tidak ada aksi. |

---

## 3. Quick Wins

1. **M-1 — saring `review_status` di `bestNetWpmFor()` dan cabang survival `resolvePersonalBest()`.** Dua baris. Ini quick win dengan rasio manfaat tertinggi: menutup kebocoran angka ber-flag ke tiga permukaan sekaligus (Ghost, GhostPicker, layar hasil), dan menegakkan aturan yang **sudah tertulis** di `recordPersonalBest()`.
2. **L-1 — keluarkan `/review-queue` dari grup `auth`.** Satu baris, menyamakannya dengan dashboard monitoring dan memenuhi janji yang ditulis middleware-nya sendiri.
3. **M-2 — samakan `toggleSpectator()` dengan pola `joinRoomByCode()`.** ±8 baris, polanya ada di file yang sama.

**Yang bukan quick win, dan harus dikerjakan lebih dulu: H-1.** Perbaikannya kecil (rutekan dua fast-path ke `closeRaceNow()`), tapi ia menyentuh jalur terpanas di multiplayer dan menuntut test konkurensi yang belum ada bentuknya di suite. Kerjakan sebagai satu pekerjaan tersendiri, bukan diselipkan.

---

## 4. Catatan Positif

- **`app/Services/` memang lapisan paling rapi.** `EloCalculator` (25 brs) adalah rumus Elo standar yang benar dan zero-sum secara struktural — ia mengembalikan `[$deltaA, -$deltaA]` alih-alih menghitung dua sisi terpisah yang bisa berbeda. `ClanWarScorer` adalah fungsi murni, dan `score()` didefinisikan **lewat** `breakdown()` dengan alasan tertulis: layar hasil yang menurunkan rasionya sendiri akan jadi sumber kebenaran kedua yang bebas melenceng dari angka tersimpan.
- **`ClanWarResolver` sekarang adalah contoh perbaikan yang benar, bukan sekadar tambalan.** `settleWar()` bukan hanya menambahkan conditional update — docblock-nya menjelaskan bug lamanya, mengapa `increment()` yang atomik per-kolom tidak pernah cukup, dan menyebut dua tempat lain yang memakai pola sama. Itu memperbaiki kode **dan** pengetahuan timnya sekaligus. Ironisnya justru kalimat di docblock inilah yang paling telak menjelaskan H-1.
- **`clanHasNothingLeftToPlay()` menilai aturan dari sisi siapa yang dirugikan.** Aturan lama "9 dari 9 submitted" menahan war terbuka selamanya dan menghukum clan yang sudah melakukan segalanya dengan benar karena lawannya menyusut: *"The penalty landed on the side at no fault."* Logika keadilannya benar dan mahal untuk ditemukan tanpa memikirkan pemain sungguhan.
- **`RoomMembershipService` adalah konsolidasi yang benar**, dan sapuan member basinya membaca sinyal yang **sudah dipelihara** (heartbeat presence) alih-alih menciptakan heartbeat baru. `sweepDeadRooms()` juga menyatakan mengapa 'racing' dan 'finished' digabung dalam satu query padahal alasannya berbeda.
- **Otorisasi ClanWar tertutup rapat dan berlapis.** `startAttempt()` men-scope klaim dengan empat kondisi (`clan_war_id` + `clan_id` + `user_id` + `whereNull('typing_result_id')`) dan komentarnya menyatakan ia *"mirrors TypingEngine::resolveWarClaim()"* — dua pintu ke sumber daya yang sama, gerbangnya sengaja dibuat identik. `challengeClan()` mengunci kedua baris clan dalam urutan id menaik dan **membaca ulang di dalam kunci** alih-alih memakai computed property yang sudah basi, dengan komentar yang menyebut deadlock sebagai alasan urutannya.
- **`acceptChallenge()` men-snapshot cap klaim, bukan menghitungnya live**, dengan alasan yang tepat sasaran: tanpa itu sebuah clan bisa menendang anggota di tengah war untuk menaikkan cap-nya sendiri dan menumpuk semua slot ke satu akun.
- **`AchievementService` memisahkan baca dan tulis dengan alasan yang benar.** `evaluate()` dulu menulis saat render — sebuah GET dengan efek samping, dijalankan berkali-kali karena Livewire re-render — dan achievement hanya tercatat kalau pemain kebetulan membuka halamannya. Sekarang unlock dicatat di titik prestasinya terjadi, dan `evaluate()` memperlakukan baris unlock sebagai **kuitansi**, sehingga menghapus satu baris hasil (mis. review anti-cheat) tidak ikut mencabut badge pemain jujur.
- **`KeystrokeAnalyzer` fail-safe secara sadar**: sampel terlalu kecil mengembalikan "no data", bukan "curang", supaya bundel JS lama tidak mengunci pemain jujur saat deploy.

---

## 5. Kualitas Test

Ini dimensi yang belum pernah dinilai di iter1/iter2. Penilaian saya: **substantif, dan jelas ditulis dari kegagalan nyata**, bukan demi angka.

**Yang kuat:**

- **Test menguji state, bukan status HTTP.** `PatientBotTest` menyimpan payload lalu memverifikasi `review_status` **dan** `highest_wpm` pemain — dua akibat berbeda dari satu aksi. `SoloResultTamperingTest` memeriksa `TypingResult::count()` = 0 **dan** `highest_wpm` = 0.0, bukan sekadar `assertRedirect`.
- **Test menghadapi gerbang produksi, bukan melewatinya.** `PatientBotTest` memakai `SoloSessionGuard::backdate()` untuk mensimulasikan waktu yang benar-benar berlalu, artinya ia melewati plafon karakter yang sama dengan produksi. Test yang mem-*bypass* guard akan hijau tanpa membuktikan apa pun.
- **Docblock test menjelaskan sejarahnya.** Komentar blok di `PatientBotTest` §"Pembagian tugas setelah plafon dinaikkan" mendokumentasikan trade-off yang disengaja: plafon dinaikkan 13→20 cps karena menolak pemain jujur, dan konsekuensinya payload §4.1 kini lolos plafon lalu **ditahan** lapisan berikutnya. Test itu memberi tahu pembaca berikutnya *siapa* yang menangkap apa, bukan cuma bahwa sesuatu tertangkap.
- **Test menolak pola yang tak membuktikan apa pun.** `iter2` §7.2 secara eksplisit menolak menguji otorisasi channel lewat `POST /broadcasting/auth` karena `NullBroadcaster::auth()` mengembalikan 200 untuk siapa pun termasuk tamu — dan mengangkat aturannya ke `ChannelAccess` agar bisa diuji sungguhan. Menyadari bahwa sebuah test akan hijau secara palsu lebih sulit daripada menulis testnya.
- Tidak ada berkas test tanpa assertion. Delapan berkas tidak memakai `expect()`/`assertDatabase` sama sekali (`ThrottleTest`, `GuestSurvivalGateTest`, `ProfilePrivateFieldTest`, dll.), tapi saya membacanya dan itu wajar: yang diuji memang status/markup (429, CTA login, absennya email), dan `assertDontSee('rahasia@uetype.test')` memakai string khas — bukan angka telanjang yang bisa cocok dengan checksum Livewire.

**Tiga titik buta:**

1. **Test konkurensi ditulis sebagai pemanggilan berurutan.** `MultiplayerStatsTest:61` — *"does not duplicate history rows when finalizeRace runs twice"* — memanggil `->call('finalizeRace')->call('finalizeRace')` pada satu komponen. Panggilan kedua **memang** ditolak, karena `xp_earned` sudah ter-commit dan terbaca. Yang tak pernah diuji adalah dua pembaca yang sama-sama melihat `null`. Ini bentuk yang identik dengan `ClanWarEarlyFinishTest` yang iter2 catat untuk H-1 — dan itu **persis alasan H-1 di laporan ini bisa hidup di bawah suite hijau**. Pola ini layak ditulis sebagai aturan tim: *test yang memanggil dua kali berurutan menguji idempotensi, bukan konkurensi; keduanya perlu, dan yang kedua butuh dua instance komponen yang state-nya tidak saling menyegarkan.*
2. **`PatientBotTest` menutup debut, tidak menutup eskalasi.** Keempat kasusnya adalah akun tanpa riwayat. Skenario "bangun baseline lalu naik <40% per langkah" — M-10 di laporan rekan — tidak punya test sama sekali, dan gerbang `LongitudinalBaseline` memang tidak melihatnya. Jadi temuan rekan itu terkonfirmasi dari sisi suite, bukan cuma dari sisi kode.
3. **Jalur tamu tidak diuji untuk route admin.** `LongitudinalReviewTest:124` menguji non-admin yang sudah login (`assertNotFound`), tak pernah tamu — dan justru jalur tamu itulah yang berperilaku beda (L-1).

---

## 6. Cakupan & Batasan — Setelah Tiga Iterasi

**Dibaca isinya di iter3 (penuh):** `ClanWarResolver` (285), `ClanWarScorer` (79), `RoomMembershipService` (282), `AchievementService` (164), `KeystrokeAnalyzer` (90), `GhostResolver` (125), `TextGeneratorService` (102), `LongitudinalBaseline` (67), `EloCalculator` (25), `FinalizesRace` (261), `ChannelAccess` (43), `EnsureUserIsAdmin` (28).
**Dibaca sebagian (blok yang ditargetkan):** `MultiplayerLobby.php` brs 151-673 dan 1130-1490; `ClanWar.php` brs 36-105 dan 283-594; `TypingEngine.php` brs 328-354, 806-960, 1303-1340; `TypingResult.php` brs 90-120; `User.php` brs 128-168.
**Disapu, tidak dibaca baris demi baris:** `app/Console/Commands/`, `app/Http/Middleware/`, `app/Support/` (ukuran + tanggung jawab + docblock), Blade untuk pola N+1 dan kebocoran privat, sampel berkas test di area anti-cheat & otorisasi.

**Estimasi jujur setelah tiga iterasi: sekitar 85% dari ~14.000 baris PHP di `app/` sudah benar-benar dibaca isinya.** Angka ini sengaja sama dengan estimasi rekan, karena iter3 saya menutup area yang sama — perbedaannya pada commit dan pada apa yang ditemukan, bukan pada luas cakupan. Yang lebih berarti daripada persentase: **seluruh jalur yang menyentuh integritas skor, otorisasi, dan mata uang game (XP/poin/rating Elo) sudah tercakup penuh di ketiga iterasi**, dan H-1 di laporan ini adalah sudut gelap terakhir yang saya temukan di sana.

**Yang tersisa (~15%), dan mengapa tidak saya prioritaskan:**
- `MultiplayerLobby.php` brs 674-1130 — updateRaceProgress bagian depan, kick, startRace, render read-model. Sebagian besar sudah tersentuh iter2 lewat `FinalizesRace`/`ReadsRoomState`.
- `Clans.php` brs 371-693 (identitas clan, disband, emblem) dan `ClanWar.php` brs 106-283 (render, grid, riwayat).
- `Chat.php`, `ChatOverlay.php`, `Friends.php`, `FriendButton.php`, `Settings.php`, `GhostPicker.php`, `TypingResult.php` — komponen UI yang gerbang otorisasinya sudah diverifikasi iter2 lewat trait bersama.
- Mayoritas isi 71 berkas Blade sebagai markup (XSS disapu menyeluruh di iter2; aspek privasi & N+1 ditutup L-10 di sini).
- `resources/js/` selain `typing-game.js` dan bagian race — terutama **`race-arena.js` (±1.100 brs)**, yang iter2 §7.6 sudah tandai sebagai prioritas iter3 dan yang **tidak** tercakup di sini karena `iter3.md` tidak memasukkannya ke lingkup. Ia memikul invarian yang dipercaya `updateRaceProgress()`, dan sekarang H-1 membuatnya lebih relevan lagi: jalur yang memicu fast-path penutupan race justru dikendalikan dari sana.

**Rekomendasi:** hentikan audit PHP setelah H-1 diperbaiki — tiga iterasi sudah menunjukkan pola yang konsisten dan iterasi keempat kemungkinan besar hanya mengonfirmasinya. Yang belum pernah diaudit sama sekali dan memikul invarian nyata adalah **`race-arena.js`**; kalau ada iterasi berikutnya, ke sanalah arahnya, bukan ke sisa PHP.

---

## 7. Verdict

**Kesehatan codebase: baik, dan membaik secara terukur di antara iterasi.** Dua commit terakhir menutup seluruh temuan iter2 — termasuk dua High — dengan test yang gagal lebih dulu, dan perbaikannya membawa serta docblock yang menjelaskan bug-nya, bukan sekadar menambal.

**Satu temuan yang memblokir: H-1.** Ia merusak data (XP dan riwayat permanen), kerusakannya tak bisa dipulihkan karena `total_xp` tidak pernah dihitung ulang, dan ia lolos suite hijau karena testnya menguji idempotensi alih-alih konkurensi.

Satu pola yang layak diperhatikan tim, dan ini kemunculan **ketiga**-nya: iter2 H-1 (`ClanWarResolver`), iter2 M-1/M-3, dan sekarang iter3 H-1/M-2/L-7 adalah **kesalahan yang sama dalam baju berbeda** — gerbang state ditulis sebagai baca-lalu-tulis di jalur yang ditulis belakangan, padahal proyek ini sudah punya jawabannya dan memakainya dengan benar di `closeRaceNow()`, `startSuddenDeathIfNeeded()`, `attachToWarClaim()`, dan kini `settleWar()`. Yang hilang bukan pengetahuan.

Karena ini pola berulang dan bukan insiden, saran saya bukan sekadar memperbaiki H-1 melainkan **menyisir sekali untuk semuanya**: cari setiap tempat yang membaca sebuah status/flag lalu menulis berdasarkan bacaan itu, dan ubah menjadi conditional update. Daftarnya di laporan ini sudah lengkap (H-1, M-2, L-7), dan mengerjakannya sebagai satu penyisiran memberi tim satu aturan yang bisa dipegang — jauh lebih murah daripada menemukan baju keempatnya di iterasi berikutnya.

---

*Bagian 0-7 ditulis read-only pada `223877e`. Bagian 8 mencatat perbaikannya, dikerjakan sesudahnya.*

---

# 8. Status Perbaikan

Seluruh temuan sudah dikerjakan. Tiap perbaikan yang bisa diuji didahului test yang **gagal lebih dulu**, sesuai TDD.

## 8.1 Ringkasan per temuan

| # | Perbaikan | Test yang mengunci |
|---|---|---|
| **H-1** | Kedua fast-path (`updateRaceProgress()` dan `giveUp()`) kini menutup balapan lewat `closeRaceNow()`, bukan `$room->update(['status' => Finished])` sendiri — jadi keduanya ikut klaim `where('status', racing)` alih-alih menjadi definisi keempat & kelima dari "balapan selesai". Efek samping yang disengaja: pemain terakhir yang finis kini langsung mendapat panel hasil pada round-trip yang sama, bukan menunggu broadcast berputar. | `RaceCloseClaimTest` (4) |
| **M-1** | `TypingResult::scopeTrustworthy()` sebagai satu definisi "hasil ini boleh mewakili pemain", dipakai `bestNetWpmFor()` dan `bestSurvivalDurationFor()` yang baru. `resolvePersonalBest()` cabang survival diarahkan ke helper itu, jadi tak ada lagi `MAX()` polos di dua tempat berbeda. | `RecordExcludesFlaggedTest` (6) |
| **M-2** | `toggleSpectator()` membungkus kedua cabang dalam `DB::transaction` dengan `lockForUpdate()` pada kedua hitungan kuota, mengikuti pola `joinRoomByCode()` persis. Flash pesan dipindah ke luar transaksi. | `RoomQuotaConcurrencyTest` (3) |
| **L-1** | Route `/review-queue` keluar dari grup `auth`, menyamakannya dengan wiring dashboard monitoring. Tamu kini dapat 404, bukan 302 yang membuktikan route-nya ada. | `LongitudinalReviewTest` (+1) |
| **L-2** | `departCurrentRooms()` membuka transaksinya **sendiri**, jadi `lockForUpdate()` di `settleAbandonedRoom()` tak lagi bergantung pada kepatuhan pemanggil. Nested aman (savepoint); `depart()` melepas pembungkusnya. | — (penyelarasan kontrak, tanpa perubahan perilaku) |
| **L-3** | Memo statis per-proses **di depan** cache store, plus docblock yang jujur bahwa `Cache::rememberForever()` menempuh store `database` — dan catatan bahwa menyunting wordlist tak berlaku sampai `cache:clear`. | `TextGeneratorServiceTest` (+1) |
| **L-4** | `TypingResult::scopeLeaderboardEligible()` sebagai satu definisi "siapa yang ditampilkan papan". `GhostResolver::resolveLeaderboard()` menolak id di luar itu; daftar picker memakai gerbang yang sama supaya tak menawarkan lawan yang lalu ditolak resolver. | `GhostLeaderboardScopeTest` (4) |
| **L-5** | Batas skala `resolveFinishedWars()` didokumentasikan beserta jalan keluarnya, supaya ditemukan lewat keputusan bukan lewat halaman yang melambat. | — (dokumentasi) |
| **L-6** | `freshRoomCode()` — maksimal 5 percobaan, lalu menyerahkan ke unique index. Berbeda sengaja dari `claimMode()` yang *menangkap*: di sana tabrakan berarti informasi untuk pemain, di sini kode cuma identitas sembarang. | `RoomCodeCollisionTest` (2) |
| **L-7** | `acceptChallenge()` memakai conditional update `where('status', Pending)`; notifikasi & broadcast hanya dijalankan pemenang klaim, dan `$war->refresh()` supaya broadcast melaporkan kolom yang benar-benar tertulis. | `ClanWarFlowTest` dkk (regresi) |
| **L-8** | K-factor tetap didokumentasikan sebagai keputusan sadar beserta kapan meninjaunya. | — (dokumentasi) |

## 8.2 Yang jujur perlu diketahui soal test H-1 dan M-2

**Interleaving konkuren yang sesungguhnya tidak bisa direproduksi di suite ini.** Itu butuh dua koneksi database, dan transaksi `RefreshDatabase` menutup jalan itu. Jadi kedua berkas test menahan **kontraknya**, bukan interleaving-nya — pola yang sama yang dipakai iter2 untuk H-1 (`"itu sudah cukup, karena bug-nya adalah panggilan kedua yang tidak ditolak"`).

Untuk M-2 itu berarti satu assertion **struktural** yang membaca sumber (`DB::transaction` hadir, `lockForUpdate()` tepat dua kali). Membaca implementasi biasanya bau, dan di sini disengaja: sebuah row lock tak punya jejak yang bisa diobservasi single-threaded, jadi satu-satunya tempat invarian itu bisa ditahan adalah di tempat ia ditulis. Presedennya sudah ada di proyek ini — `RaceTrackDesignTest` menahan "`race_starts_at` ditulis tepat sekali" dengan cara yang sama.

Dua dari empat test `RaceCloseClaimTest` juga **hijau sebelum perbaikan**; keduanya kunci regresi, bukan bukti bug. Yang benar-benar gagal lebih dulu adalah kontrak "fast-path menutup lewat `closeRaceNow()`".

## 8.3 Satu keputusan yang perlu diketahui: fixture ghost ikut berubah

Gerbang L-4 membuat sejumlah fixture ghost lama tak lagi lolos — rekornya ada, tapi pemiliknya tak pernah ditampilkan papan. **Fixture-nya yang disesuaikan, bukan gerbangnya**, lewat satu helper baru `accumulateTypingTime()` di `tests/Pest.php`.

Baris pemanasnya sengaja **survival**, bukan `time 60` seperti helper serupa di `LeaderboardIntegrityTest`: ghost hanya berlaku di time/words, jadi baris survival menambah waktu terakumulasi tanpa pernah muncul sebagai rekor di mode yang sedang diassert. Baris `time 60` justru akan mencemari assertion "tak ada rekor di config ini".

Ia tinggal di `Pest.php` dan bukan di berkas test, dengan alasan yang sudah ditulis berulang di berkas itu: fungsi yang dideklarasikan di sebuah berkas test menjadi global saat suite dijalankan penuh.

## 8.4 Verifikasi

- **Pint:** `{"tool":"pint","result":"passed"}`
- **Vitest:** 5 berkas, 30 test, hijau
- **Pest:** **1183 passed, 3526 assertions, 0 gagal**, `--parallel` — **dua run berturut-turut memberi angka identik** (sebelumnya 1162; 21 test baru di 5 berkas baru + 3 berkas yang ditambah)

## 8.5 Deploy

**Tidak ada `npm run build` yang wajib kali ini.** Tak satu pun berkas di `resources/js/` disentuh, jadi jebakan bundel yang di-*bake* (PROJECT_OVERVIEW.md §12) tidak berlaku untuk perubahan ini.

## 8.6 Yang sengaja tidak dikerjakan

**M-10 & M-11 laporan rekan (kalibrasi `LongitudinalBaseline`).** Keduanya terkonfirmasi masih berlaku — dan §5 laporan ini menambah bukti dari sisi suite bahwa skenario eskalasi bertahap memang tak punya test sama sekali. Tapi keduanya adalah **keputusan angka**, bukan bug: `NO_HISTORY_WPM`, `SPIKE_FRACTION`, dan `HISTORY_WINDOW` harus ditentukan dari distribusi `net_wpm` install ini sendiri, persis peringatan yang sudah ditulis `AntiCheatService::MAX_CHARS_PER_SECOND` dan `SoloSessionGuard`. Menebak angka baru sekarang hanya mengulangi kesalahan yang menaruh `IMPOSSIBLE_CONSISTENCY` di 97.

Ini juga satu paket dengan `KEYSTROKE_TIMING_ENFORCED = false`, yang punya **tanggal tinjau 2026-09-01**. Saran: kerjakan ketiganya bersama saat data main sudah ada, sebagai satu keputusan tentang lapisan §7.5 — bukan ditambal sepotong-sepotong.

**`race-arena.js` (±1.100 brs).** Belum pernah diaudit siapa pun dan tidak masuk lingkup `iter3.md`. Ia memikul invarian yang dipercaya `updateRaceProgress()`, dan H-1 justru membuatnya lebih relevan: jalur yang memicu fast-path penutupan race dikendalikan dari sana. Itu arah iterasi berikutnya kalau ada.
