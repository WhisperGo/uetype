# Audit: Bot Mengetik "Manusiawi" — Celah yang Tersisa

**Status:** 📋 Analisis / catatan desain (belum diimplementasikan)
**Dibuat:** 2026-07-25
**Konteks:** Lanjutan diskusi "bisakah memblok script yang mengetik dari console?".
Jawaban singkatnya **tidak** — klien tak bisa dipercaya, titik. Dokumen ini memetakan
apa yang sudah dijaga, lalu **satu kelas serangan yang masih lolos**: bot yang sengaja
mengetik pada kecepatan & akurasi manusiawi.

---

## 1. Premis: klien tidak bisa dipercaya

Console (DevTools) adalah bagian dari browser milik pengguna. Script yang diketik di sana
berjalan di **origin yang sama** dengan kode kita, dengan **hak yang sama** — bisa mengisi
`input.value`, memicu event `keydown`, memanggil fungsi Alpine/Livewire kita, dan mematikan
"penjaga" JS apa pun yang kita pasang (penjaganya pun jalan di tempat yang sama).

Konsekuensinya, pertahanan yang benar **bukan** "cegah scriptnya berjalan" (mustahil) tapi
**"tolak hasil yang tak masuk akal di server"**. Itu sudah jadi arsitektur proyek ini.

Yang **jangan** dilakukan (rasa aman palsu): blokir DevTools, obfuscate JS, atau menjadikan
`event.isTrusted` sebagai satu-satunya gerbang — semuanya trivial dilewati dari console dan
malah mengganggu pengguna jujur.

## 2. Yang SUDAH dijaga (dan kenapa efektif)

| Lapis | Mekanisme | Serangan yang dimatikan |
|-------|-----------|--------------------------|
| **Server hitung ulang WPM** | [`AntiCheatService::check()`](../app/Services/AntiCheatService.php) — `net_wpm` dari `correctChars`/durasi, angka WPM klien diabaikan | "Kirim saja `wpm: 250`" |
| **Plafon fisik** | `MAX_HUMAN_WPM = 300` (solo), `MAX_RACE_WPM = 240` (race) | Teleport ke 100%, WPM mustahil |
| **Durasi & jumlah char dari server** | [`SoloSessionGuard`](../app/Services/SoloSessionGuard.php) — `resolveDuration()`, `maxPlausibleChars()`, `matchesIssuedSession()` | "1495 char dalam 60s = 299 WPM" palsu; replay sesi; klaim durasi 9999s |
| **Konsistensi lintas-metrik** | `raceResultReasons()` — progress tinggi + akurasi mustahil rendah ditolak | Fast-garbage (200 WPM @ 3%) |
| **Rate-limit** | 20 update/dtk/pemain (race), `MAX_RESULTS_PER_MINUTE` (solo) | Flood payload untuk cari yang lolos |
| **AFK gap** | [`isAfkSession()`](../app/Livewire/TypingEngine.php) — jeda terpanjang antar-keystroke | "Ketik 2 huruf lalu tinggalkan" |

Kesimpulan lapisan ini: **angka setinggi apa pun yang mustahil secara fisika akan ditolak.**
Script yang mencoba WPM 500 tak ada gunanya.

## 3. Celah yang tersisa: bot berkecepatan manusiawi

Bayangkan script yang **tidak** serakah. Ia mengetik teks yang benar, karakter demi karakter,
pada **120 WPM dengan akurasi 100%** — nilai elit tapi sepenuhnya mungkin bagi manusia. Semua
gerbang di §2 **lolos**, karena tak ada satu pun angka yang mustahil:

- WPM 120 < 300/240 → lolos plafon.
- Akurasi 100% pada progress 100% → konsisten, lolos.
- Jumlah char sesuai teks yang di-*issue* → lolos `maxPlausibleChars`.
- Durasi wajar → lolos guard.
- Tak ada jeda panjang → lolos AFK.

Hasilnya masuk sebagai PB/EXP/leaderboard yang "sah". **Inilah batasnya**: begitu penyerang
memilih target angka yang manusiawi, gerbang berbasis *besaran* tak bisa membedakannya dari
pemain elit sungguhan **hanya dari angka akhir**.

### 3.1 Kenapa ini kelas serangan yang berbeda

Serangan sebelumnya kalah karena **melampaui fisika**. Serangan ini menghormati fisika di
level *besaran* — tapi mengkhianatinya di level **pola waktu**. Yang membedakan manusia dari
bot bukan seberapa cepat, tapi seberapa **tidak beraturan**:

- **Manusia** punya varians ritme: jeda mikro sebelum kata sulit, *burst* pada kata familiar,
  koreksi (backspace), dan akselerasi/deselerasi bertahap. Interval antar-keystroke tersebar.
- **Bot** cenderung memuntahkan keystroke pada interval **nyaris konstan** (mis. tepat 100ms
  tiap huruf), tanpa koreksi, tanpa jeda-berpikir — sebuah *metronome*.

Sinyal untuk mendeteksinya **sudah tiba di server**, tapi belum dipakai untuk anti-cheat.

## 4. Sinyal yang tersedia tapi belum dipakai

[`SoloSessionPayload`](../app/Support/SoloSessionPayload.php) sudah membawa timing agregat:

- **`wpmHistory`** — WPM per detik sepanjang sesi.
- **`maxIdleMs`** — jeda terpanjang (kini hanya untuk AFK).
- **`computeConsistency()`** ([`TypingEngine`](../app/Livewire/TypingEngine.php)) sudah menghitung
  kestabilan WPM dari `wpmHistory` — **tapi komentarnya jelas menyebut "Presentation only, not
  anti-cheat."**

Ironi yang berguna: skor konsistensi yang sekarang cuma dipajang justru **sinyal utama** untuk
kelas serangan ini — hanya arah pemakaiannya terbalik. Konsistensi **terlalu sempurna** itu
mencurigakan, bukan membanggakan.

**Catatan penting:** server **tidak** melihat keystroke individual (lihat komentar di
`isAfkSession`), hanya agregat per detik. Jadi deteksi timing harus berbasis `wpmHistory`
(varians per detik), bukan distribusi interval antar-tombol — kecuali kita mulai mengirim
histogram interval (lihat §6).

## 5. Yang bisa dilakukan (dan trade-off-nya)

Diurutkan dari paling murah & aman ke paling ambisius. **Belum ada yang diimplementasikan** —
ini peta pilihan, bukan resep wajib.

| Opsi | Cara | Kelebihan | Risiko / kekurangan |
|------|------|-----------|----------------------|
| **A. Flag konsistensi ekstrem** | Kalau `computeConsistency()` di atas ambang sangat tinggi (mis. varians ~0) **dan** WPM tinggi, tandai untuk review — **jangan** langsung tolak | Memakai sinyal yang sudah ada; nol perubahan payload | Ambang sulit dikalibrasi; pemain sangat mahir bisa sangat konsisten → **false positive** |
| **B. Histogram interval keystroke** | Klien kirim distribusi jeda antar-tombol (bukan tiap event — cukup histogram/beberapa statistik); server cek "terlalu seragam" | Sinyal jauh lebih kuat daripada WPM per detik | Menambah field payload; **tetap bisa dipalsukan** klien (bot bisa mengirim histogram "manusiawi" bohongan) |
| **C. Server terima timestamp keystroke** | Server yang menilai varians, bukan klien | Klien tak bisa memilih statistik yang menguntungkan | Payload berat; privasi (pola ketik = biometrik); klien tetap bisa fabrikasi timestamp |
| **D. Tantangan tak terduga** | Sesekali sisipkan variasi yang butuh reaksi (mis. teks berubah, prompt) yang sulit di-otomasi | Menyerang otomasi, bukan angka | Mengganggu UX pemain jujur; rumit |

### 5.1 Batas keras yang harus disadari

**Semua opsi di atas bisa dikalahkan oleh bot yang cukup canggih.** Bot yang menambahkan
varians acak realistis + koreksi sesekali + jeda-berpikir akan lolos deteksi timing —
karena pada titik itu ia **menyamai distribusi statistik manusia**, dan tak ada sinyal
klien yang bisa membedakan "manusia" dari "bot yang meniru manusia dengan sempurna".

Ini bukan alasan untuk tak berbuat apa-apa; ini alasan untuk **menyesuaikan ambisi**:

- Deteksi timing **menaikkan biaya** menyontek (bot naif tertangkap; hanya bot canggih yang
  lolos) — itu kemenangan nyata, bukan kekalahan.
- Tapi jangan pernah menjualnya sebagai "anti-cheat yang menutup celah". Tak ada yang begitu.

## 6. Rekomendasi

1. **Jangan** membangun deteksi DevTools/obfuscation — buang waktu, ganggu pengguna jujur.
2. **Pertahankan** gerbang besaran yang sudah ada — itu sudah mematikan seluruh kelas serangan
   "angka mustahil", yang merupakan **mayoritas** upaya nyata.
3. **Kalau** kelas "bot manusiawi" jadi masalah nyata (ada bukti, bukan hipotesis), mulai dari
   **Opsi A** (paling murah, memakai sinyal yang sudah dihitung) dalam mode **flag-untuk-review**,
   bukan penolakan otomatis — karena false-positive di sini berarti **menghukum pemain elit
   yang jujur**, kerugian yang lebih buruk daripada meloloskan segelintir bot.
4. Terima bahwa **titik henti** ada: pada bot yang meniru manusia sempurna, pertahanan klien
   habis. Sisa mitigasi hanya di ranah non-teknis (moderasi, laporan, reputasi).

---

## 7. Ringkasan satu kalimat

Yang mustahil sudah ditolak dengan kokoh; yang manusiawi-tapi-robotik **bisa dipersempit**
lewat sinyal timing yang sudah tiba di server (`wpmHistory`/konsistensi), tapi **tak akan
pernah tertutup sepenuhnya** — jadi bangun untuk *menaikkan biaya*, bukan untuk *kemenangan
mutlak*, dan utamakan tidak menghukum pemain jujur.
