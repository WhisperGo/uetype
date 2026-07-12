# WPM Tinggi, Akurasi Rendah — Apakah Hasilnya Valid?

**Status:** Draf diskusi tim — belum diimplementasikan
**Dibuat:** 2026-07-12
**Konteks:** Ditemukan saat review sistem multiplayer race, tapi berlaku juga untuk mode solo.

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

## 4. Kondisi Kode Saat Ini (sudah dicek langsung)

Ternyata **mode solo dan mode multiplayer sudah tidak konsisten** satu sama lain:

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

### Mode Multiplayer — belum punya perlindungan ini

- [`MultiplayerLobby::updateRaceProgress()`](../app/Livewire/MultiplayerLobby.php#L293) menyimpan
  `wpm` **langsung dari client** (`int $liveWpm` — parameter dari browser pemain sendiri), tanpa
  dihitung ulang server dan **tanpa validasi**.
- Tidak ada pembagian `net_wpm` vs `raw_wpm` — hanya satu kolom `wpm`.
- [`finalizeRace()`](../app/Livewire/MultiplayerLobby.php#L188) memang sudah memanggil `addExp()`
  dengan `accuracy` sebagai pengali (jadi EXP multiplayer sudah "adil" seperti solo) — **tapi**
  urutan menang (`place`) dan `wpm` yang tersimpan di riwayat pertandingan tidak melalui
  Net WPM/anti-cheat sama sekali.
- Artinya: di multiplayer, pemain bisa mengetik ngasal, dapat `wpm` tinggi yang **client-supplied**
  dan tak diverifikasi, dan itu tercatat di `match_result`/riwayat sebagai angka resmi.

## 5. Opsi Perbaikan

| Opsi | Cara Kerja | Kelebihan | Kekurangan |
|---|---|---|---|
| **A. Net WPM** (disarankan) | Selaraskan multiplayer dengan pola yang sudah ada di solo: hitung ulang `net_wpm` di server dari `correctChars`/waktu, itulah yang disimpan sebagai `wpm` & dipakai untuk PB/leaderboard | Konsisten dengan mode solo, tidak butuh ambang batas arbitrer, hasil pemain tetap tersimpan (tak dibuang) | Perlu server menghitung `correctChars`, bukan hanya menerima `wpm` dari client |
| **B. Minimum accuracy gate** | PB/leaderboard hanya menerima hasil dengan akurasi ≥ ambang batas (mis. 90%) | Simpel untuk diimplementasi | Hasil di bawah ambang batas "hilang" begitu saja meski WPM tinggi; ambang batas terasa sewenang-wenang |
| **C. Skor gabungan** | Ranking pakai `score = wpm * (accuracy / 100)` atau fungsi weighted lain | Fleksibel untuk tuning leaderboard | WPM asli tetap perlu ditampilkan terpisah agar tidak membingungkan pemain; berpotensi predictable-gaming kalau bobotnya salah |

### Rekomendasi: Opsi A, dikombinasikan dengan pola anti-cheat yang sudah ada

1. **Samakan multiplayer dengan solo**: `updateRaceProgress()`/finalisasi race hitung ulang WPM di
   server dari `correctChars` (bisa diturunkan dari `progress_percent × panjang teks`, pola yang
   sama persis yang sudah dipakai `finalizeRace()` untuk EXP) dan durasi race — bukan menerima
   `wpm` mentah dari client.
2. Simpan sebagai Net WPM (karakter benar saja) — konsisten dengan `highest_wpm` di mode solo,
   supaya PB lintas mode punya arti yang sama.
3. Opsional: terapkan `AntiCheatService` yang sudah ada juga ke jalur multiplayer, supaya WPM di
   atas batas manusiawi (>300) atau throughput mustahil ditolak di kedua mode dengan aturan yang
   sama persis — tidak perlu servis baru, tinggal dipanggil ulang.
4. Akurasi tetap ditampilkan terpisah di UI sebagai info tambahan (sudah begitu sekarang), bukan
   disembunyikan di balik satu angka gabungan — pemain tetap bisa lihat "aku cepat tapi banyak
   salah" secara eksplisit.

## 6. Yang Perlu Didiskusikan Tim

- Apakah PB/leaderboard multiplayer perlu diselaraskan dengan solo (Net WPM), atau tim mau
  pendekatan berbeda untuk multiplayer (mis. karena real-time race punya dinamika sosial yang
  beda dari solo practice)?
- Kalau Opsi A dipilih: apakah `place` (urutan menang) race tetap berdasarkan
  `finished_time_seconds` (siapa tercepat selesai, sudah begitu sekarang — lihat
  `finalizeRace()`), atau ingin ikut mempertimbangkan akurasi juga?
- Apakah anti-cheat gate (menolak sesi) juga diinginkan di multiplayer, atau cukup Net WPM saja
  tanpa penolakan?

---

*Dokumen ini murni analisis & proposal — belum ada perubahan kode yang dibuat.*
