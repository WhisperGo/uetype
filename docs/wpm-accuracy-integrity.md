# WPM Tinggi, Akurasi Rendah — Apakah Hasilnya Valid?

**Status:** ✅ Diimplementasikan (commit `f37cb9c` — "feat (multiplayer): reject invalid race results from stats")
**Dibuat:** 2026-07-12 · **Diimplementasikan:** 2026-07-13
**Konteks:** Ditemukan saat review sistem multiplayer race. Tim memilih Opsi A (Net WPM,
diselaraskan dengan mode solo) dari analisis di bawah, ditambah anti-cheat gate.

---

## 1. Pertanyaan

WPM dihitung dari kecepatan mengetik, akurasi dihitung dari `karakter benar / total karakter diketik`.
Kalau seorang pemain mengetik **cepat tapi ngasal** (WPM tinggi, akurasi rendah), hasil itu saat ini
tetap dianggap valid dan bisa masuk sebagai *best record*/leaderboard/EXP pemain tersebut.

**Apakah ini valid?**

## 2. Jawaban Singkat

**Secara matematis valid, tapi secara desain game berbahaya.** WPM dan akurasi memang dua metrik
independen — itu bukan bug. Masalahnya ada di **apa yang boleh disebut "prestasi terbaik"**. Kalau
sistem membiarkan WPM mentah (ngasal) disimpan sebagai PB, pemain akan optimal dengan cara *spam*
huruf acak secepat mungkin — berlawanan dengan tujuan game latihan mengetik.

## 3. Kenapa Ini Masalah Nyata

- Pemain bisa mengetik ngasal secepat mungkin, dapat WPM tinggi dengan akurasi 20–30%, lalu itu
  tersimpan sebagai PB — padahal bukan pencapaian mengetik yang sebenarnya.
- Kalau dipakai untuk leaderboard/EXP, strategi optimal jadi **"ketik secepat mungkin tanpa peduli
  benar"**, bukan "ketik dengan benar secepat mungkin".
- Membandingkan dua pemain dengan WPM sama tapi akurasi 95% vs 40% sebagai "setara" tidak adil.

## 4. Kondisi Sebelum Perbaikan (temuan awal)

Ternyata **mode solo dan mode multiplayer tidak konsisten** satu sama lain:

### Mode Solo — sudah punya sebagian besar solusinya

- [`AntiCheatService::check()`](../app/Services/AntiCheatService.php) menghitung ulang **Net WPM**
  dan **Raw WPM** secara terpisah di server (tidak percaya angka dari client):
  - `raw_wpm` = seluruh karakter yang diketik (termasuk yang salah) / menit
  - `net_wpm` = **hanya karakter benar** / menit → ini yang dipakai sebagai skor akhir
  - Sesi yang tidak masuk akal (WPM > 300, throughput terlalu rendah, dsb) **ditolak**, tidak disimpan.
- [`TypingEngine::saveResult()`](../app/Livewire/TypingEngine.php#L384) memakai `net_wpm` hasil
  hitung ulang server sebagai `highest_wpm` (PB) — bukan raw WPM.
- [`User::addExp()`](../app/Models/User.php#L110) sudah memberi bobot: EXP dikalikan
  `0.5 + 0.5 * (accuracy / 100)`, jadi akurasi 100% = EXP penuh, akurasi 0% = EXP setengah dari
  karakter benar yang sama.

Jadi **mode solo pada dasarnya sudah menerapkan "Net WPM" dan sudah punya anti-cheat gate.**

### Mode Multiplayer — belum punya perlindungan ini (sebelum `f37cb9c`)

- `MultiplayerLobby::updateRaceProgress()` menyimpan `wpm` **langsung dari client**
  (`int $liveWpm` — parameter dari browser pemain sendiri), tanpa dihitung ulang server dan
  **tanpa validasi**.
- Tidak ada pembagian `net_wpm` vs `raw_wpm` — hanya satu kolom `wpm`.
- `finalizeRace()` memang sudah memanggil `addExp()` dengan `accuracy` sebagai pengali (jadi EXP
  multiplayer sudah "adil" seperti solo) — **tapi** urutan menang (`place`) dan `wpm` yang
  tersimpan di riwayat pertandingan tidak melalui Net WPM/anti-cheat sama sekali.
- Artinya: di multiplayer, pemain bisa mengetik ngasal, dapat `wpm` tinggi yang **client-supplied**
  dan tak diverifikasi, dan itu tercatat di `match_result`/riwayat sebagai angka resmi.

## 5. Opsi yang Dipertimbangkan

| Opsi | Cara Kerja | Kelebihan | Kekurangan |
|---|---|---|---|
| **A. Net WPM** (disarankan) | Selaraskan multiplayer dengan pola yang sudah ada di solo: hitung ulang `net_wpm` di server dari `correctChars`/waktu, itulah yang disimpan sebagai `wpm` & dipakai untuk PB/leaderboard | Konsisten dengan mode solo, tidak butuh ambang batas arbitrer, hasil pemain tetap tersimpan (tak dibuang) | Perlu server menghitung `correctChars`, bukan hanya menerima `wpm` dari client |
| **B. Minimum accuracy gate** | PB/leaderboard hanya menerima hasil dengan akurasi ≥ ambang batas (mis. 90%) | Simpel untuk diimplementasi | Hasil di bawah ambang batas "hilang" begitu saja meski WPM tinggi; ambang batas terasa sewenang-wenang |
| **C. Skor gabungan** | Ranking pakai `score = wpm * (accuracy / 100)` atau fungsi weighted lain | Fleksibel untuk tuning leaderboard | WPM asli tetap perlu ditampilkan terpisah agar tidak membingungkan pemain; berpotensi predictable-gaming kalau bobotnya salah |

Tim memilih **Opsi A**, dikombinasikan dengan anti-cheat gate ala mode solo. Opsi B dan C tidak
dipakai: B membuang hasil pemain di bawah ambang batas begitu saja, C butuh tuning bobot yang
rawan predictable-gaming.

## 6. Implementasi (`f37cb9c`)

### a. Net WPM otoritatif dihitung server, bukan dari client

[`updateRaceProgress()`](../app/Livewire/MultiplayerLobby.php#L351) tidak lagi memakai `$liveWpm`
dari client untuk angka resmi. Parameter itu tetap ada di signature (kompatibilitas payload) tapi
diabaikan untuk perhitungan:

1. `correctChars` diturunkan dari `progress_percent × panjang teks` — otomatis "net" karena
   progress hanya naik dari karakter yang diketik **benar** (karakter salah tak menambah progres).
2. `durationSeconds` dihitung dari `race_starts_at` sampai sekarang, di server.
3. `AntiCheatService::check($correctChars, $correctChars, $durationSeconds)` dipanggil dengan
   `totalChars == correctChars` — rumus **persis sama** dengan mode solo
   (`TypingEngine::saveResult()`), satu sumber kebenaran untuk Net WPM di kedua mode.
4. Hanya sinyal curang murni (`wpm_too_high`, `char_count_inconsistent`) yang menolak angka WPM
   (jadi `0`) — throughput rendah/durasi pendek **tidak** dianggap curang (itu wajar untuk pemain
   lambat atau awal race), sesuai temuan di §3 bahwa tim tidak ingin gerbang yang terlalu agresif.

### b. Hasil akhir digerbang sebelum masuk riwayat & EXP

[`finalizeRace()`](../app/Livewire/MultiplayerLobby.php#L195) memanggil
[`isValidRaceResult()`](../app/Livewire/MultiplayerLobby.php#L261) (memakai `AntiCheatService`
yang sama) sebelum menulis hasil:

- **Valid** → EXP diberikan (`addExp()`, sudah dibobot akurasi seperti sebelumnya) dan baris baru
  ditulis ke `MultiplayerMatchHistory` (riwayat pertandingan permanen).
- **Tidak valid** → **tidak** dapat EXP, **tidak** ditulis ke `MultiplayerMatchHistory` sama
  sekali — jadi rata-rata WPM pemain di statistik tidak bisa dirusak oleh satu hasil ngasal.
- DNF/menyerah tetap tercatat seperti biasa (throughput rendah bukan sinyal curang, sesuai poin a).

### c. Kolom & UI baru

- Migrasi `..._add_result_recorded_to_room_members_table.php` menambah kolom boolean
  `room_members.result_recorded`, di-cast di [`RoomMember`](../app/Models/RoomMember.php).
- Layar hasil pertandingan menampilkan catatan **"Hasil ini tidak lolos validasi dan tidak dicatat
  ke statistikmu"** (`multiplayer.result_invalid`, en+id) kalau `result_recorded === false` untuk
  pemain yang sedang login — transparan ke pemain, bukan penolakan diam-diam.

### d. Cakupan

- [`tests/Feature/MultiplayerStatsTest.php`](../tests/Feature/MultiplayerStatsTest.php) menguji
  jalur gagal (WPM mustahil → tidak masuk `MultiplayerMatchHistory`, EXP 0, `result_recorded`
  false) dan jalur normal/DNF tetap tercatat.

## 7. Jawaban atas Pertanyaan Diskusi Sebelumnya

- **PB/leaderboard multiplayer diselaraskan dengan solo?** Ya — Net WPM dengan rumus
  `AntiCheatService` yang sama persis.
- **`place` tetap berdasarkan `finished_time_seconds`?** Ya, tidak diubah — urutan menang tetap
  murni siapa tercepat selesai; akurasi tidak ikut memengaruhi `place`, hanya memengaruhi apakah
  hasilnya *tercatat* (valid/tidak) dan besaran EXP.
- **Anti-cheat gate juga diinginkan?** Ya — diimplementasikan sebagai gerbang di `finalizeRace()`
  (bukan menolak real-time saat race berlangsung, supaya race tidak terganggu di tengah jalan;
  gerbang hanya berlaku saat hasil akhir difinalisasi).

---

*Status akhir: selesai diimplementasikan & diuji. Dokumen ini kini berfungsi sebagai catatan
desain, bukan lagi proposal terbuka.*
