# Audit Code Quality — UeType (iter2, independen)

**Tanggal:** 2026-08-04 · **Branch:** `development` @ `d5292d5` · **Sifat:** read-only, tidak ada kode aplikasi yang diubah.
**Instruksi:** [`iter2.md`](iter2.md)
**Laporan pembanding:** [`iter2-report.md`](iter2-report.md) (audit rekan tim, ditulis pada `3e330f4`)

---

## 0. Catatan Cakupan yang Harus Dibaca Lebih Dulu

Tiga hal tentang premis instruksi ini, karena keduanya mengubah apa yang layak diaudit.

**0.1 — `docs/iteration/iter1-report.md` tidak ada.** Yang ada di repo adalah
`iter1-report-verified.md` di root, dan file itu **sedang terhapus tapi belum di-commit**
(`git status` menunjukkan ` D`). Saya mengambilnya dari `git show HEAD:iter1-report-verified.md`.
Kalau penghapusan itu tidak disengaja, kembalikan dengan `git restore iter1-report-verified.md` —
dokumen itu memuat justifikasi 25 perbaikan yang tidak tercatat di tempat lain.

**0.2 — Area yang diminta iter2.md sebagian besar SUDAH diaudit.** Instruksi ini menyebut
"area yang tercatat belum dibaca di iter1 §5", dengan daftar: MultiplayerLobby, otorisasi
chat/DM, otorisasi clan, Blade. Tapi daftar itu berasal dari `iter1-report.md` **versi lama**
(yang dianalisis di Bagian A dokumen verified). `iter1-report-verified.md` §B.5 mencatat sudah
membaca `Clans.php` jalur otorisasi penuh, `ManagesChatConversation`, `GuardsChatAccess`,
`ChatController`, dan `MultiplayerLobby` (mount/kick/invite/chat/updateRaceProgress/giveUp).
§C mencatat **seluruh** temuannya sudah diperbaiki di `d5292d5`.

Yang **benar-benar** belum dibaca menurut §B.5 adalah: `FinalizesRace`, `ReadsRoomState`,
`ManagesRoomMembership`, `ClanWarResolver`, `ClanWarScorer`, `race-arena.js`, dan mayoritas
Blade di luar aspek XSS. Audit ini memprioritaskan daftar itu, lalu memverifikasi ulang
area iter2.md di HEAD (62 file berubah sejak `3e330f4`).

**Hasilnya membenarkan pergeseran prioritas itu:** temuan terberat di dokumen ini ada di
`ClanWarResolver` — file yang tidak disebut iter2.md sama sekali, dan yang §B.5 tandai sebagai
belum dibaca.

**0.3 — Suite tidak dijalankan.** Berbeda dari iter1, saya **tidak** menjalankan Pest. §B.5
iter1 mendokumentasikan bahwa menjalankan suite berurutan pernah menghapus database dev; itu
sudah diperbaiki (`phpunit.xml` mematok `uetype_test`), tapi audit read-only tidak butuh suite
untuk membuktikan temuan-temuan di bawah — semuanya dibuktikan dari pembacaan kode, dan tiap
temuan menyebutkan test yang seharusnya menangkapnya tapi tidak ada.

---

## 1. Ringkasan Eksekutif

Jalur race — yang iter2.md tandai prioritas tertinggi — **tidak menyimpan satu pun temuan
keamanan.** `FinalizesRace` menentukan validitas sebelum podium dibagikan, `closeRaceNow` dan
`startSuddenDeathIfNeeded` memakai conditional update yang atomik, dan tiga deadline berbeda
menuju satu definisi "race selesai". Otorisasi clan dan chat sama rapinya seperti dicatat iter1;
`ChatAccess` yang lahir dari M-3 iter1 sekarang benar-benar menjadi satu sumber kebenaran.

Temuan terberat justru di tempat yang tak diminta: **`ClanWarResolver::resolveFinishedWars()`
menerapkan perubahan Elo tanpa transaksi maupun penjaga idempotensi**, padahal ia dipanggil dari
`ClanWar::mount()` — artinya setiap pembukaan halaman oleh anggota clan mana pun memicunya. Dua
anggota membuka halaman bersamaan sudah cukup untuk menerapkan delta power **dua kali** ke kedua
clan. Ini menyimpang dari pola yang proyek ini sendiri sudah kuasai: `closeRaceNow()`,
`startSuddenDeathIfNeeded()`, dan `attachToWarClaim()` semuanya memakai conditional update untuk
persis masalah ini.

Temuan kedua adalah **channel Reverb chat yang publik dengan ID berurutan** — sama dengan H-3 di
laporan rekan, terkonfirmasi masih ada, dengan dua penajaman: enkripsi `body` at-rest tidak
memberi perlindungan apa pun di jalur ini, dan kebocorannya meluas ke `friends.{userId}` yang
membawa **kode room** — kredensial yang justru menjadi dasar klaim "channel race aman".

Selain itu: `startRace()` adalah satu-satunya aksi host-only di file itu yang **tidak** memeriksa
status room, sehingga ia bisa memutar ulang race dengan teks yang sama dan melewati regenerasi
teks yang justru menjadi alasan `playAgain()` ada.

---

## 2. Tabel Temuan

### Critical

Tidak ada.

### High

| # | Lokasi | Temuan | Mengapa masalah | Saran |
|---|---|---|---|---|
| **H-1** | [`ClanWarResolver.php:37-79`](app/Services/ClanWarResolver.php#L37-L79), khususnya [`:67-75`](app/Services/ClanWarResolver.php#L67-L75); pemicu di [`ClanWar.php:60-63`](app/Livewire/ClanWar.php#L60-L63) | **Resolusi Clan War tidak atomik dan tidak idempoten.** `resolveFinishedWars()` membaca semua war `Ongoing`, lalu untuk tiap war: menghitung Elo, `$war->challenger->increment('power', …)`, `$war->opponent->increment('power', …)`, **baru** `$war->update(['status' => Finished])`. Tidak ada `DB::transaction`, tidak ada `lockForUpdate`, dan status Finished ditulis **setelah** power diubah — jadi ia bukan penjaga. | Jendela balapannya lebar dan pemicunya sangat umum. `mount()` memanggil `resolveDue()` **setiap kali** siapa pun membuka `/clan-war` — dan momen paling mungkin dua orang membukanya bersamaan adalah persis saat war berakhir (`ends_at` lewat, atau notifikasi masuk). Dua request paralel keduanya membaca war yang sama sebagai `Ongoing`, keduanya menghitung delta yang identik, dan keduanya memanggil `increment()` → **power bergeser 2×**, `notifyResult()` menyiarkan toast dua kali, dan `challenger_power_delta` yang tersimpan tidak lagi cocok dengan perubahan power sebenarnya. Karena `power` adalah metrik peringkat `ClanLeaderboard` dan tidak pernah dihitung ulang dari riwayat, **kerusakannya permanen dan tak terdeteksi** — tidak ada error, hanya angka yang salah. Perhatikan ini bukan kelemahan teoretis: `increment()` justru dipilih (bukan read-modify-write) karena atomik per-kolom, tapi atomisitas per-kolom tidak mencegah **dua eksekusi penuh**. Proyek ini sudah menguasai obatnya — [`closeRaceNow():1431`](app/Livewire/MultiplayerLobby.php#L1431) dan [`startSuddenDeathIfNeeded():189`](app/Livewire/Concerns/FinalizesRace.php#L189) keduanya memakai conditional update untuk masalah yang sama persis, dengan docblock yang menjelaskan alasannya. Resolver-nya saja yang tidak ikut. Tidak tertutup test: `ClanWarEarlyFinishTest` memanggil `resolveDue()` secara berurutan, tak pernah bersamaan. | Klaim war-nya lebih dulu, dengan pola yang sudah dipakai di tempat lain: `$claimed = ClanWar::where('id', $war->id)->where('status', ClanWarStatus::Ongoing)->update(['status' => ClanWarStatus::Finished]); if (! $claimed) continue;` — lalu baru hitung Elo dan `increment()` di dalam `DB::transaction`, dan tulis `result`/`power_delta` sesudahnya. Yang menang update itulah satu-satunya yang menerapkan power. Test yang mengunci: panggil `resolveDue()` **dua kali berturut-turut** pada war yang sama dan pastikan `power` hanya bergeser sekali — itu sudah cukup, karena bug-nya adalah panggilan kedua yang tidak ditolak, bukan konkurensi yang sesungguhnya. |
| **H-2** | [`DirectMessageSent.php:21,36`](app/Events/DirectMessageSent.php#L21), [`ClanMessageSent.php:21,35`](app/Events/ClanMessageSent.php#L21), [`MessageEdited.php:22-23,35`](app/Events/MessageEdited.php#L22-L23), `MessageDeleted.php:22-23`, [`RoomInvitationSent.php:24,40`](app/Events/RoomInvitationSent.php#L24), [`routes/channels.php`](routes/channels.php) | **Isi chat privat & kode room disiarkan lewat channel publik ber-ID berurutan.** Keempat belas event memakai `new Channel(...)`; tak satu pun `PrivateChannel`. `chat.{recipient_id}` dan `clan-chat.{clan_id}` membawa `body`; `friends.{userId}` membawa `roomCode`. `routes/channels.php` hanya memuat scaffolding `App.Models.User.{id}` yang tak dipakai event mana pun. `VITE_REVERB_APP_KEY` memang publik ([`echo.js:13`](resources/js/echo.js#L13)), jadi kredensial berlangganan tersedia untuk siapa saja. | *(Sepakat dengan H-3 di [`iter2-report.md`](iter2-report.md); tiga penajaman.)* **(a) Enkripsi `body` tidak menolong di sini, dan itu memperparah.** [`Message.php:38`](app/Models/Message.php#L38) memakai cast `'body' => 'encrypted'`, tapi cast Laravel mendekripsi saat atribut diakses — jadi `$this->message->body` di baris 36 menyiarkan **plaintext**. Proyek membayar seluruh biaya enkripsi at-rest (tak bisa di-`LIKE`, tak bisa di-index, semua pesan mati kalau `APP_KEY` hilang) sambil membocorkan isi yang sama lewat WebSocket: dua kontrol yang saling meniadakan. **(b) Cakupannya lebih luas dari chat, dan menyentuh klaim yang dipakai untuk membenarkan channel lain.** `RoomInvitationSent` menyiarkan `roomCode` ke `friends.{userId}` — public, ID berurutan. Jadi `Echo.channel('friends.7').listen('.room.invitation', e => e.roomCode)` memanen kode yang melindungi `room.{code}`/`race.{code}`. Kerahasiaan channel race **diturunkan dari** channel yang bocor; ini kebocoran kredensial, bukan sekadar kebocoran data. `PresenceUpdated` dan `FriendshipUpdated` di channel yang sama membocorkan grafik sosial & status online. **(c) Ongkos perbaikannya lebih murah dari yang diperkirakan** — lihat kolom saran. | Ubah keempat event chat **dan** `RoomInvitationSent` ke `PrivateChannel`, daftarkan otorisasinya di `routes/channels.php`, ganti `Echo.channel` → `Echo.private` di [`toasts.js:143,176`](resources/js/toasts.js#L143) (dan `:81` untuk `friends.*`). **Prasyaratnya sudah terpasang, berbeda dari yang ditulis laporan rekan:** [`bootstrap/app.php:14`](bootstrap/app.php#L14) mengoper `channels:` ke `withRouting()`, yang memanggil `withBroadcasting()` → `Broadcast::routes()` (`ApplicationBuilder.php:180-181`), dan meta `csrf-token` sudah ada di kedua layout. Jadi tidak ada infrastruktur baru yang perlu dibuat. `room.*`/`race.*` sendiri boleh tetap publik **setelah** kode room berhenti bocor lewat `friends.*` — spectator & deep-link undangan bergantung pada sifat publiknya. Test: user B gagal otorisasi `chat.{A}` dan `friends.{A}`. |

### Medium

| # | Lokasi | Temuan | Mengapa masalah | Saran |
|---|---|---|---|---|
| **M-1** | [`MultiplayerLobby.php:1518-1556`](app/Livewire/MultiplayerLobby.php#L1518-L1556) | **`startRace()` adalah satu-satunya aksi host-only di file ini yang tidak memeriksa status room.** Gate-nya hanya `! $room \|\| $room->host_id !== Auth::id()` lalu jumlah pemain. Bandingkan tetangganya: [`setRaceLang():262`](app/Livewire/MultiplayerLobby.php#L262) memeriksa `status !== Waiting`, [`kickMember():696`](app/Livewire/MultiplayerLobby.php#L696) juga, `toggleSpectator()` juga. | Dua akibat, dan yang kedua menyentuh data permanen. **(a) Reset di tengah race.** Host memanggilnya saat balapan berjalan → seluruh `progress_percent`, `wpm`, `finished_time_seconds`, `place` kembali nol dan `countdown_started_at` di-null-kan, mematikan sudden death yang sedang berjalan. Ini griefing di room sendiri, bukan eskalasi. **(b) Yang lebih serius: melewati regenerasi teks.** `text_to_type` hanya ditulis di tiga tempat — [`:274`](app/Livewire/MultiplayerLobby.php#L274) (ganti bahasa), [`:385`](app/Livewire/MultiplayerLobby.php#L385) (buat room), [`:1482`](app/Livewire/MultiplayerLobby.php#L1482) (`playAgain`). `startRace()` tidak menyentuhnya. Jadi dari room berstatus `finished`, host bisa memanggil `startRace()` langsung alih-alih `playAgain()` dan mengulang balapan **dengan teks yang persis sama** — sementara `xp_earned` di-reset ke `null` sehingga XP dan baris `multiplayer_match_history` ditulis lagi. Regenerasi teks di `playAgain()` ada justru untuk mencegah hafalan; ini pintu di sebelahnya yang tidak mengunci. Host + satu teman bisa mengulangi teks yang sudah dihafal berkali-kali, dan WPM hasilnya masuk riwayat permanen serta memberi makan tab multiplayer di halaman Stats. | Tambahkan `\|\| $room->status !== RoomStatus::Waiting` ke gate baris 1522 — satu kondisi, sejajar dengan tiga aksi tetangganya. Itu menutup keduanya sekaligus: rematch jadi wajib lewat `playAgain()`, yang memang sudah meregenerasi teks. Test: host memanggil `startRace()` pada room `finished` tidak mengubah `text_to_type` dan tidak mengembalikan status ke `racing`. |
| **M-2** | [`GuardsChatAccess.php:74-84`](app/Livewire/Concerns/GuardsChatAccess.php#L74-L84) | `sendClanMessageAs(int $clanId, …)` menerima `$clanId` sebagai argumen dan langsung `Message::create()` **tanpa memverifikasi** bahwa pemanggil anggota aktif clan itu. Keamanannya sepenuhnya bergantung pada satu-satunya pemanggil, [`ManagesChatConversation.php:197`](app/Livewire/Concerns/ManagesChatConversation.php#L197), yang mengoper `$this->myClan->id`. | *(Sepakat dengan M-6 di [`iter2-report.md`](iter2-report.md).)* Asimetri di dalam trait yang justru bernama `Guards…`: pasangannya `sendDmMessage()` di [`:58-62`](app/Livewire/Concerns/GuardsChatAccess.php#L58) memanggil `isAcceptedFriend()` **di dalam dirinya sendiri**. Bukan kerentanan hari ini, tapi ini jenis method yang pemanggil berikutnya akan anggap aman karena nama dan lokasinya menjanjikan begitu — dan trait keamanan adalah tempat asumsi seperti itu paling mungkin dibuat. | Penajaman dari saran laporan rekan: tidak perlu query manual, `ChatAccess::activeClan(Auth::id())` sudah ada sejak perbaikan M-3 iter1. Verifikasi clan itu sama dengan `$clanId` sebelum `create()`, lalu `return`. Satu panggilan, konsisten dengan `sendDmMessage()`. |
| **M-3** | [`ClanWar.php:449-470`](app/Livewire/ClanWar.php#L449-L470), [`Clan.php:115-123`](app/Models/Clan.php#L115-L123) | **`challengeClan()` memakai read-then-write tanpa constraint pendukung.** Gate-nya `! $this->myActiveWar` + `$opponent->activeWar() !== null`, keduanya `SELECT`, lalu `ClanWarModel::create()`. Tidak ada unique constraint di `clan_wars` yang mencegah dua war aktif untuk pasangan clan yang sama. | Dua leader menantang satu sama lain (atau satu leader men-double-click) dalam jendela yang sama → keduanya lolos gate → dua baris `Pending`. Kalau keduanya diterima, ada dua war `Ongoing` untuk clan yang sama; `Clan::activeWar()` memakai `->first()`, jadi UI hanya menampilkan salah satunya sementara yang lain tetap hidup, mengunci kedua clan dari war baru dan — begitu `ends_at`-nya lewat — memberi **dua** perubahan Elo dari satu pertandingan. Berinteraksi langsung dengan H-1: kedua war itu diresolusi di loop yang sama. Kemungkinannya kecil, dampaknya sama dengan H-1. | Constraint di DB adalah obat yang benar di sini karena gate-nya inheren balapan: partial unique index atas `(challenger_clan_id, opponent_clan_id)` untuk status aktif tidak portabel di MySQL, jadi pilihan yang lebih sederhana adalah membungkus gate + `create()` dalam `DB::transaction` dengan `lockForUpdate()` pada kedua baris clan. Alternatif termurah: terima kemungkinan itu tapi buat `activeWar()` deterministik (`orderBy('id')`) dan tambahkan `clan-war:resolve` yang menutup duplikat. |

### Low

| # | Lokasi | Temuan | Saran |
|---|---|---|---|
| **L-1** | [`ManagesChatConversation.php:144`](app/Livewire/Concerns/ManagesChatConversation.php#L144), [`:253`](app/Livewire/Concerns/ManagesChatConversation.php#L253), [`ChatController.php:33`](app/Http/Controllers/ChatController.php#L33) | Batas panjang pesan `2000` sebagai literal di tiga tempat, tanpa konstanta. `Message` sudah punya preseden (`EDIT_WINDOW_MINUTES` di [`:19`](app/Models/Message.php#L19)). | `Message::MAX_BODY_LENGTH`, dirujuk dari ketiganya termasuk aturan validasi controller. *(Sepakat dengan L-9 laporan rekan.)* |
| **L-2** | [`routes/channels.php:5-7`](routes/channels.php#L5-L7) | Satu-satunya otorisasi terdaftar (`App.Models.User.{id}`) adalah scaffolding Laravel — tidak ada event yang menyiarkan ke sana. | Hapus **bersamaan** dengan H-2, bukan terpisah, supaya file ini berubah sekali dari "kosong" jadi "berisi otorisasi nyata". |
| **L-3** | [`ProfileController.php:36-45`](app/Http/Controllers/ProfileController.php#L36-L45), [`profile/show.blade.php:76-77`](resources/views/profile/show.blade.php#L76-L77) | Profil publik menerima **model `User` utuh** ke view, dengan `email` hanya dijaga `@if(! $isPublic)` di Blade. Gerbangnya di lapisan presentasi, bukan di data. | Tidak ada kebocoran sekarang — saya periksa seluruh view dan `email` hanya muncul di baris 77 di dalam guard. Tapi payload publik idealnya tidak memuat field yang tak boleh dilihat. Kalau mau dikeraskan: kirim array eksplisit untuk cabang publik alih-alih model utuh. Prioritas rendah; catat agar audit berikutnya tak melaporkannya sebagai kebocoran aktif. |
| **L-4** | [`MultiplayerLobby.php:590-608`](app/Livewire/MultiplayerLobby.php#L590-L608) | `toggleReady()` tidak memeriksa status room, jadi `is_ready` bisa di-toggle saat `racing`/`finished`. | Tidak ada dampak: `startRace()` tidak membaca `is_ready` sama sekali (tombolnya saja yang di-gate di view), dan kolom itu di-reset oleh `playAgain()`. Dicatat sebagai inkonsistensi guard, bukan kerentanan — perbaiki bersamaan dengan M-1 kalau menyentuh file ini. |
| **L-5** | [`ClanWarResolver.php:84-88`](app/Services/ClanWarResolver.php#L84-L88) | String notifikasi hasil war di-hardcode dalam **Bahasa Indonesia** (`'Clan-mu MENANG Clan War'`), tidak lewat `__()`, padahal proyek punya sistem lokalisasi en/id yang dipakai di seluruh UI lain. | Pindahkan ke `lang/{en,id}/clan.php`. Perhatikan ini pesan **broadcast**, jadi locale-nya harus locale penerima, bukan locale request yang kebetulan memicu resolusi — pola yang sama dengan notifikasi clan lain. Sejalan dengan L-5 iter1 (log berbahasa campur) yang sudah diperbaiki; ini kemunculan yang sama di jalur berbeda. |

---

## 3. Quick Wins

1. **H-1 — klaim war sebelum menerapkan Elo.** Empat baris (conditional update + `continue`) plus memindahkan blok ke `DB::transaction`. Ini satu-satunya temuan yang merusak data secara permanen, dan test yang menguncinya hanya butuh dua panggilan `resolveDue()` berurutan.
2. **M-1 — satu kondisi status di `startRace()`.** Menutup reset di tengah race **dan** pengulangan teks sekaligus, dan menyamakan gate-nya dengan tiga aksi host-only tetangganya.
3. **M-2 — gerbang keanggotaan di `sendClanMessageAs()`.** Satu panggilan `ChatAccess::activeClan()`.
4. **L-1 — satukan `MAX_BODY_LENGTH`.** Mekanis, tiga call site.
5. **H-2 — privatkan channel chat + `RoomInvitationSent`.** Paling besar dari lima ini, tapi prasyarat infrastrukturnya sudah ada, jadi ruang lingkupnya benar-benar hanya lima event + dua closure + tiga baris JS + test.

Nomor 1-4 muat dalam satu sesi. **Kerjakan H-1 lebih dulu**: H-2 membocorkan data, tapi H-1 merusaknya, dan tidak ada jalan memulihkan power yang sudah bergeser dua kali karena `clans.power` tidak pernah dihitung ulang dari riwayat war.

---

## 4. Catatan Positif

- **Jalur finalisasi race adalah bagian terkuat dari yang saya baca di iterasi ini** — dan itu berarti sesuatu, karena inilah yang iter2.md tandai prioritas tertinggi. Validitas ditentukan **sebelum** podium dibagikan ([`FinalizesRace.php:92-108`](app/Livewire/Concerns/FinalizesRace.php#L92-L108)), dengan komentar yang memikirkan **korbannya**: pemain curang yang mengambil posisi 1 mendorong pemain jujur ke posisi 2 *di riwayat permanen orang itu*. Menulis gate dari sudut pandang yang dirugikan, bukan hanya dari sudut pandang penyerang, jarang terjadi.
- **`orderByRaw('finished_time_seconds IS NULL')` sebagai kunci sortir pertama** ([`:87`](app/Livewire/Concerns/FinalizesRace.php#L87)) dengan komentar yang mengakui ini "fragility, not a live bug" — MySQL menaruh NULL di depan pada ASC, jadi tanpa baris itu pemain yang tak pernah selesai bisa mendarat di posisi 1. Menutup celah yang **belum** bisa terjadi, dan mengatakan sejujurnya bahwa itu belum bisa terjadi.
- **Idempotensi dipisahkan dari atomisitas secara sadar** ([`:36-37`](app/Livewire/Concerns/FinalizesRace.php#L36-L37)): transaksi melindungi dari tulis separuh, penjaga `xp_earned IS NULL` melindungi dari panggilan ganda — *"different roles, not duplication"*. Achievement sengaja **di luar** transaksi dengan biaya yang dihitung. Ini persis penalaran yang absen di `ClanWarResolver` (H-1), yang membuat kontrasnya menarik: polanya ada di rumah, hanya tidak dipakai di satu tempat.
- **Tiga deadline menuju satu `closeRaceNow()`** ([`:1431`](app/Livewire/MultiplayerLobby.php#L1431)), dengan alasan tertulis: sudden death, start grace, dan hard ceiling "must not drift into three subtly different definitions of *the race is over*". Dan asimetri `$includeStartGrace` dijelaskan sebagai **keputusan**, bukan optimisasi — hanya arena yang menyaksikan pemain diam yang berhak menjatuhkan vonis atas satu orang.
- **Ceiling mundur saat sudden death berjalan, start grace tidak** ([`:1360-1380`](app/Livewire/MultiplayerLobby.php#L1360-L1380)). Dua aturan yang tampak sejenis diberi perlakuan berbeda, masing-masing dengan alasannya: ceiling ada untuk menangkap race yang menggantung dan sudden death adalah **bukti** race itu tidak menggantung; sementara "dua puluh detik harus berarti dua puluh detik, atau ia bukan aturan yang bisa dipakai pemain".
- **`ClanWarScorer` menjelaskan mengapa `$durationOverride` tidak diterapkan ke `TypingResult`** ([`:41-44`](app/Services/ClanWarScorer.php#L41-L44)): memendekkan `duration_seconds` justru **menaikkan** WPM, dan `AntiCheatService` membaca kolom itu — 900 karakter/55 detik akan terbaca 196 WPM dan ditolak sebagai mustahil. Rekor solo tetap jujur, hanya kredit war yang dibatasi. Menyadari bahwa dua sistem membaca kolom yang sama dengan arti berbeda adalah bentuk kewaspadaan yang sulit.
- **`ClanWarResolver::clanHasNothingLeftToPlay()`** ([`:120-170`](app/Services/ClanWarResolver.php#L120-L170)) mengoreksi aturan lama "9 dari 9 submitted" dengan menyebut siapa yang dirugikan: clan yang sudah melakukan segalanya dengan benar dipaksa menunggu tiga hari penuh karena lawannya menyusut. *"The penalty landed on the side at no fault."* Logika keadilannya benar sekalipun jalur atomisitas di file yang sama tidak (H-1).
- **Otorisasi clan memakai rank gate, bukan sekadar cek peran.** [`Clans.php:292`](app/Livewire/Clans.php#L292) menolak aksi ke atas atau setara, dan [`changeRole():466`](app/Livewire/Clans.php#L466) **menegaskan** peran asal (`->where('role', $from)`) alih-alih sekadar memakainya untuk lookup — halaman basi tak bisa mempromosikan orang yang sudah co-leader. Semua target discope ke `where('clan_id', $this->myClan->id)`.
- **`ChatAccess` benar-benar menjadi satu sumber kebenaran**, dan docblock-nya ([`:12-29`](app/Support/ChatAccess.php#L12-L29)) menjelaskan **arah kegagalannya**: perketat aturan di satu sisi dan sisi lain tetap memakai kunci lama — *"Nothing errors; there is simply a second door with the old lock."* Perbaikan M-3 iter1 juga menutup celah yang tak diminta: balasan ke pesan yang sudah ditarik pengirimnya kini ditolak di jalur yang dilewati **setiap** balasan, bukan hanya di `startReply()`.
- **XSS bersih di 86 file Blade.** Empat `{!! !!}` semuanya `number_format()`/`e()`. Setiap interpolasi `{{ }}` di dalam atribut Alpine yang saya periksa berisi nilai server-controlled (kunci kategori, nama mode, ID, hitungan); **setiap** input user memakai `@js()`. Yang paling menonjol: [`clan-war.blade.php:177-180`](resources/views/livewire/clan-war.blade.php#L177-L180) menjelaskan mengapa `{{ }}` **terpaksa** dipakai di dalam atribut tag komponen (Blade tak mengompilasi direktif di sana) — jadi baris 181 dan 188 yang tampak tidak konsisten sebenarnya menghadapi konteks berbeda. *(Laporan rekan mencatat ini sebagai L-8 "inkonsistensi gaya" dan menyarankan menyeragamkan ke `@js()`; menerapkan saran itu pada baris 181 akan mengirim direktif mentah ke Alpine dan mematikan tombolnya. Kodenya sudah benar.)*

---

## 5. Cakupan & Batasan

**Dibaca penuh:** [`FinalizesRace.php`](app/Livewire/Concerns/FinalizesRace.php) (261), [`ManagesRoomMembership.php`](app/Livewire/Concerns/ManagesRoomMembership.php) (26), [`RoomMembershipService.php`](app/Services/RoomMembershipService.php) (282), [`ClanWarResolver.php`](app/Services/ClanWarResolver.php) (201), [`ClanWarScorer.php`](app/Services/ClanWarScorer.php) (79), [`EloCalculator.php`](app/Services/EloCalculator.php) (25), [`GuardsChatAccess.php`](app/Livewire/Concerns/GuardsChatAccess.php) (85), [`ChatAccess.php`](app/Support/ChatAccess.php) (112), [`ChatController.php`](app/Http/Controllers/ChatController.php) (94), [`ProfileController.php`](app/Http/Controllers/ProfileController.php), [`routes/channels.php`](routes/channels.php), [`bootstrap/app.php`](bootstrap/app.php), seluruh 14 file di [`app/Events/`](app/Events/) (channel + payload).

**Dibaca terarah:** `MultiplayerLobby.php` — `startRace`, `playAgain`, `closeRaceNow`, `resolveRaceDeadlinesIfElapsed`, `resolveSuddenDeathIfElapsed`, `checkRaceDeadline`, `checkSuddenDeath`, `toggleReady`, `toggleSpectator`, `kickMember`, `leaveRoom`, `render` (±550 dari 1569 baris). `ManagesChatConversation.php` — jalur kirim/edit/hapus/clear (brs 135-340 dari 465). `Clans.php` — seluruh jalur otorisasi (approve/reject/kick/leave/transfer/promote/demote/disband + kedua predikat `canManage*`). `ClanWar.php` — `mount`, `claimMode`, `startAttempt`, `cancelClaim`, `challengeClan`, grid status. `Message.php` — gerbang edit/hapus & cast. Blade — disapu **terarah untuk XSS** (`{!! !!}`, interpolasi di dalam atribut Alpine, `email`/field privat di view bersama) atas 86 file.

**Belum dibaca — prioritas iter3:**
- **[`resources/js/race-arena.js`](resources/js/race-arena.js) (±1100 brs)** — prioritas tertinggi berikutnya, dan sudah ditandai §B.5 iter1. Word-lock klien inilah yang menjadi dasar invarian "progress hanya naik dari karakter benar" yang dipercaya `updateRaceProgress()`. Kalau invarian itu bocor, gerbang WPM race ikut bocor — dan tak satu pun temuan di dokumen ini menyentuhnya.
- **[`ReadsRoomState.php`](app/Livewire/Concerns/ReadsRoomState.php) (406 brs)** — read-model race; dipetakan lewat pemanggilnya saja. Tidak menulis, jadi risikonya lebih rendah, tapi `syncRaceOutcomeFromDb()` dan cache room belum diperiksa.
- **`MultiplayerLobby.php` brs 151-590 & 736-950** — `mount`, `joinRoomByCode`, `roomUpdated`, `createRoom`, `invitePlayer`, `updateRaceProgress`. Sebagian sudah dibaca iter1; saya **tidak** memverifikasi ulang `updateRaceProgress` baris per baris di HEAD meski ia berubah di `d5292d5`.
- **[`ClanWarAttempt.php`](app/Services/ClanWarAttempt.php)** — buku besar attempt & semantik jam; hanya dibaca lewat pemanggilnya di resolver dan `ClanWar`. Ini permukaan integritas Clan War yang tersisa.
- **Blade di luar XSS** — N+1 di dalam loop view, logika presentasi, kebocoran non-`email`. 86 file, hanya disapu untuk tiga pola.
- **`typing-game.js` (1355 brs)** di luar bagian yang sudah dibaca iter1.

**Tidak dilakukan:** menjalankan suite (lihat §0.3), `EXPLAIN` atas query mana pun, dan pengujian konkurensi nyata untuk H-1/M-3 — keduanya dibuktikan dari struktur kode (tidak adanya transaksi/constraint), bukan dari reproduksi.

---

## 6. Verdict

**Request changes**, karena H-1.

Berbeda dari H-2 yang membocorkan data, **H-1 merusaknya, dan kerusakannya tidak bisa dipulihkan**: `clans.power` tidak pernah dihitung ulang dari riwayat war, jadi begitu sebuah delta diterapkan dua kali tidak ada sumber kebenaran untuk membetulkannya. Pemicunya adalah hal yang paling wajar dilakukan pemain — dua orang membuka halaman Clan War saat war mereka baru berakhir.

Sisanya tidak memblokir. Jalur race, yang seharusnya jadi temuan terbesar menurut prioritas di `iter2.md`, justru bersih dan merupakan kode terbaik yang saya baca di proyek ini. Otorisasi clan dan chat tetap rapi setelah perbaikan iter1, dan pertahanan XSS utuh di 86 file Blade.

Satu pola yang layak diperhatikan tim: **H-1, M-1, dan M-3 adalah kesalahan yang sama dalam tiga baju** — gerbang state ditulis sebagai baca-lalu-tulis, padahal proyek ini sudah punya jawabannya dalam bentuk conditional update dan memakainya dengan benar di `closeRaceNow()`, `startSuddenDeathIfNeeded()`, dan `attachToWarClaim()`. Yang hilang bukan pengetahuan, melainkan penerapannya di jalur yang ditulis belakangan. Saat memperbaiki ketiganya, salin **beserta docblock-nya** — komentar di `startSuddenDeathIfNeeded()` menjelaskan justru bug yang H-1 alami sekarang.

---

*Bagian 1-6 ditulis read-only pada `d5292d5`. Bagian 7 mencatat perbaikannya, dikerjakan sesudahnya.*

---

# 7. Status Perbaikan

Seluruh temuan sudah dikerjakan. Setiap perbaikan didahului test yang **gagal lebih dulu**, sesuai TDD.

## 7.1 Ringkasan per temuan

| # | Perbaikan | Test yang mengunci |
|---|---|---|
| **H-1** | `ClanWarResolver::settleWar()` — conditional update `where('status', Ongoing)` mengklaim war **sebelum** Elo diterapkan, seluruhnya dalam satu `DB::transaction`; broadcast dipindah ke luar transaksi. Pola yang sama persis dengan `closeRaceNow()` dan `startSuddenDeathIfNeeded()`. Sekalian null-safe terhadap clan yang sudah bubar, karena begitu war diklaim, crash berarti war Finished tanpa hasil dan tanpa jalan kembali. | `ClanWarResolutionIdempotencyTest` (4) |
| **H-2** | 8 event dipindah ke `PrivateChannel`: keempat event chat, `RoomInvitationSent`, `FriendshipUpdated`, `PresenceUpdated`, `ClanUpdated`. Tiga event `room.*`/`race.*` sengaja **tetap publik**. `toasts.js` beralih ke `Echo.private()` di keempat langganan. | `PrivateChannelAuthorizationTest` (5) |
| **M-1** | `startRace()` mendapat `status !== RoomStatus::Waiting`, menyamakannya dengan `setRaceLang()`/`kickMember()`/`toggleSpectator()`. Menutup reset di tengah race **dan** rematch berteks sama. | `RaceStartGuardTest` (4) |
| **M-2** | `sendClanMessageAs()` memverifikasi keanggotaan sendiri lewat `ChatAccess::activeClan()`, simetris dengan `sendDmMessage()`. | `ChatSendGuardTest` (3) |
| **M-3** | `challengeClan()` mengunci kedua baris clan (`lockForUpdate`, urut id untuk menghindari deadlock) lalu **membaca ulang di dalam kunci** alih-alih memakai computed property yang sudah basi. | `ClanWarChallengeUniquenessTest` (6) |
| **L-1** | `Message::MAX_BODY_LENGTH`, dirujuk ketiga call site. | `ChatSendGuardTest` |
| **L-2** | `routes/channels.php` berisi otorisasi nyata; scaffolding `App.Models.User.{id}` dihapus. | `PrivateChannelAuthorizationTest` |
| **L-3** | `email` diselesaikan di controller (`null` untuk cabang publik) alih-alih dijaga `@if` di dalam view bersama. | `ProfilePrivateFieldTest` (3) |
| **L-4** | `toggleReady()` mendapat guard status yang sama. | `RaceStartGuardTest` |
| **L-5** | Tiga kalimat hasil war pindah ke `lang/{en,id}/clan.php`. | `LangParityTest` |

## 7.2 Satu keputusan desain yang perlu diketahui

**Aturan otorisasi channel tidak diuji lewat `POST /broadcasting/auth`.** Suite berjalan dengan `BROADCAST_CONNECTION=null`, dan `NullBroadcaster::auth()` adalah **no-op yang mengembalikan 200 untuk siapa pun — termasuk guest**. Test yang menembak endpoint itu akan hijau tanpa membuktikan apa pun, yang lebih berbahaya daripada tak punya test sama sekali.

Aturannya karena itu diangkat ke [`App\Support\ChannelAccess`](app/Support/ChannelAccess.php) dan diuji langsung — pola yang sama persis dengan `ChatAccess` di iter1, dan dengan alasan yang sejenis: aturan yang tak bisa dijangkau test adalah aturan yang diam-diam berhenti berlaku. `routes/channels.php` mendelegasi ke sana, dan satu test terpisah memverifikasi keempat channel benar-benar **terpasang** — karena aturan yang benar tapi tak terdaftar tidak melindungi apa pun.

## 7.3 Dua test rapuh yang ikut ketahuan (bukan regresi)

Suite penuh awalnya merah di dua tempat yang **tak tersentuh perubahan mana pun** di atas. Keduanya lolos saat diisolasi dan gagal di bawah beban paralel — sisa H-4 iter1, yang §C.6-nya sendiri mengakui sapuannya belum tuntas (*"saya memperbaiki yang gagal, bukan yang berpotensi gagal"*).

1. **`MultiplayerLobbyTest`** — `finished_time_seconds` diturunkan dari `now() − race_starts_at`, diassert dengan rentang 29–31; beban paralel menghasilkan 32. **`freezeSecond()`, bukan `freezeTime()`**: `race_starts_at` menempuh kolom DATETIME yang membuang pecahan detik, jadi beku pada `12:00:00.9` menulis `11:59:30` sementara `now()` tetap `.9` — selisih 30,9 yang terbaca 31. Ambangnya lalu dikembalikan ke nilai persis (`toBe(30)`).
2. **`ClanWarAttemptResumeTest`** — marginnya hanya 2 detik dari 30. Pembekuannya dinaikkan ke **`beforeEach`** untuk seluruh berkas: tiga test sudah memanggilnya sendiri-sendiri, dan yang gagal justru yang **tidak** — persis cara pengaman per-test membiarkan test berikutnya lahir tanpa perlindungan yang sama.

Keduanya diperbaiki dengan aturan proyek sendiri: bekukan jamnya, jangan longgarkan ambangnya.

## 7.4 Verifikasi

- **Pint:** `{"tool":"pint","result":"passed"}`
- **Vitest:** 5 berkas, 30 test, hijau
- **Pest:** **1162 passed, 3487 assertions, 0 gagal**, `--parallel` — **tiga run berturut-turut memberi angka identik** (sebelumnya 1136; 26 test baru di 6 berkas)

## 7.5 WAJIB dilakukan saat deploy

**`npm run build` harus dijalankan ulang.** `toasts.js` berubah dari `Echo.channel()` ke `Echo.private()`, dan bundel JS di-*bake* saat build. Tanpa rebuild, klien lama tetap mencoba berlangganan channel publik yang **sudah tidak disiarkan lagi** — dan gejalanya persis jebakan yang sudah tercatat di [`PROJECT_OVERVIEW.md`](docs/PROJECT_OVERVIEW.md) §12: notifikasi, toast chat, dan undangan room berhenti muncul **tanpa satu pun error**.

## 7.6 Yang sengaja tidak dikerjakan

**M-8 iter2-report rekan (memecah `MultiplayerLobby`, 1569 brs).** Sama dengan keputusan iter1 §C.3, dan alasannya menguat: refactor terbesar di proyek sebaiknya tidak ditumpuk di atas sepuluh perbaikan yang baru saja mendarat. Suite-nya sekarang stabil dan bisa menopangnya — tapi itu keputusan tim, bukan efek samping dari pekerjaan lain.

**Locale penerima untuk notifikasi broadcast.** L-5 memindahkan tiga kalimat ke berkas terjemahan, tapi `__()` merender dalam locale permintaan yang **kebetulan** memicu resolusi, bukan locale penerima. Itu berlaku untuk **seluruh** notifikasi clan (`Clans::notify()` selalu begitu), jadi memperbaikinya hanya di sini akan membuat keduanya berbeda. Perlu satu perubahan lintas semuanya — dicatat di docblock `notifyResult()`.

**Prioritas iter3 tidak berubah:** `race-arena.js` (±1100 brs) masih memikul invarian yang dipercaya `updateRaceProgress()`, dan tak satu pun temuan di dokumen ini menyentuhnya.
