# Rencana — Responsif & Mobile, Tahap 1: Blocker Fungsional

**Dibuat:** 2026-07-26 · **Status:** kode selesai, **menunggu verifikasi perangkat**
**Cakupan:** input balapan multiplayer + dua konfirmasi yang tak bisa diisi di HP.

> **Status per langkah:** 0–8 selesai; 842 test hijau, Pint bersih pada berkas yang disentuh.
> Yang belum: **uji di HP sungguhan** ([`../mobile-test-checklist.md`](../mobile-test-checklist.md)).
> Tahap ini belum boleh disebut beres sebelum A7 & A12 di checklist itu dijawab.

> Dokumen ini adalah **rencana kerja**, bukan deskripsi keadaan sekarang. Untuk cara kerja
> fitur yang sudah jadi, rujuk [`docs/features/`](../features/README.md). Begitu tahap ini
> selesai, perilaku permanennya pindah ke
> [`features/multiplayer-race.md`](../features/multiplayer-race.md) §3.16 dan yang tersisa di
> sini hanya status.

---

## 1. Kenapa ini dikerjakan

Kita ingin UeType bisa dipakai di HP. Audit menyeluruh menemukan hal yang tak kami duga:
**kerangka layout-nya sudah responsif.** Grid utama sudah stacking
(`grid-cols-2 sm:grid-cols-5`), semua tabel sudah dibungkus `overflow-x-auto`, viewport meta
ada di ketiga layout, hamburger nav benar-benar berfungsi, dan tipografi kritis sudah
memakai `clamp()`. Tak ada satu pun fixed-width `px` yang merusak layout.

Jadi kalau kita mulai dengan menambal `sm:`/`md:` di sana-sini, kita akan menghabiskan waktu
memperbaiki hal yang sudah benar sambil membiarkan masalah aslinya hidup.

Masalah aslinya: **tiga fitur rusak secara fungsional di HP** — bukan sempit, bukan jelek,
tapi tak bisa dipakai sama sekali.

### 1.1 Akar yang sama

Input HTML di perangkat sentuh menyalakan **autocapitalize** dan **autocorrect** secara
default. Proyek ini sudah pernah menanganinya — tapi hanya di **satu** tempat, yaitu mesin
ketik solo ([`typing-engine.blade.php:331-338`](../../resources/views/livewire/typing-engine.blade.php)),
dan dikunci [`MobileTypingInputTest`](../../tests/Feature/MobileTypingInputTest.php).

Dari **18 field input** yang bisa disentuh user di seluruh aplikasi, **17 tak punya satu pun
atribut mobile.**

### 1.2 Tiga yang rusak

| Yang rusak | Apa yang dialami user di HP | Sebabnya |
|---|---|---|
| **Input balapan multiplayer** | Menekan huruf pertama sebuah kata, dan **tak terjadi apa-apa** | Huruf dikapitalisasi otomatis → gagal cek prefiks → dibuang word-lock ([`race-arena.js:425-437`](../../resources/js/race-arena.js)) |
| **Maju kata di balapan** | Terkunci selamanya di kata pertama, tanpa pesan error apa pun | Kata hanya maju lewat `@keydown.space`. Gboard melaporkan `keydown` sebagai `Unidentified`/keyCode 229 selagi menyusun kata → spasi mendarat sebagai karakter biasa → `"the "` bukan prefiks dari `"the"` → spasinya dibuang |
| **Bubarkan clan & hapus akun** | Nama yang diketik "salah" padahal sudah benar | Keduanya minta ketik ulang nama **persis** dan dibandingkan ketat ([`Clans.php:528`](../../app/Livewire/Clans.php), [`Settings.php:99`](../../app/Livewire/Settings.php)) — huruf pertama yang dikapitalisasi HP tak akan pernah cocok |

Fakta soal Gboard bukan tebakan: kita sudah mendokumentasikannya sendiri untuk mode solo di
[`typing-engine.md`](../features/typing-engine.md) §3.7.a. Yang terjadi adalah pelajarannya
tak ikut dibawa ke multiplayer.

### 1.3 Kenapa ini tak pernah dilaporkan sebagai bug

Ketiganya gagal **tanpa suara.** Word-lock memang dirancang menolak input diam-diam, jadi
bagi pemain ini tak terbaca sebagai kerusakan — melainkan "HP-ku lemot". Itu sebabnya
masalah sebesar ini bisa hidup lama tanpa ada yang mengeluh.

### 1.4 Hasil yang dituju

Balapan multiplayer **bisa dimainkan penuh** di HP, dan dua aksi irreversible itu bisa
diselesaikan dari HP. Tahap ini **tidak** menyentuh estetika atau kepadatan tampilan.

---

## 2. Keputusan yang sudah disepakati

| Keputusan | Alasan |
|---|---|
| Race di HP = **first-class**, bukan sekadar "bisa dibuka" | Ini fitur inti; membiarkannya rusak di mayoritas perangkat sama dengan tak punya fitur itu |
| Cakupan = input balapan + 2 konfirmasi rusak | Ketiganya kelas "fitur rusak". 15 input sisanya kelas "kurang nyaman" → Tahap 4 |
| Enter di balapan = **maju kata**, `enterkeyhint="next"` | Memberi pemain HP tombol maju yang besar dan pasti — sekaligus jaring pengaman kedua kalau jalur spasi bermasalah di perangkat tertentu |
| Verifikasi = **manual di perangkat nyata** | Proyek ini tak mengeksekusi JavaScript di test sama sekali; test hanya bisa mengunci kontrak, bukan membuktikan perilaku |

---

## 3. Rancangan inti: kenapa Race tak bisa menyalin Solo

Ini bagian yang paling penting dipahami sebelum menyentuh kode, karena "tiru saja yang di
solo" adalah jawaban yang salah.

**Solo** membatalkan `beforeinput` dan **selalu mengosongkan field**. Inputnya cuma pemanggil
keyboard; seluruh state ada di mesin ([`typing-game.js:730-764`](../../resources/js/typing-game.js)).

**Race** sebaliknya: `x-model="typedText"` berarti **field itu sendiri adalah state** kata
yang sedang diketik, dan harus mempertahankan nilainya. Menyalin pola solo berarti menulis
ulang seluruh lapisan input — backspace, caret, composition — tanpa satu pun test JS yang
bisa menangkap kesalahannya. `RaceStateResetTest.php:71` juga mensyaratkan `x-model="typedText"`.

### Pendekatan yang dipakai

**Deteksi spasi dari NILAI field, bukan dari event keydown.**

Dua jalur saling eksklusif **tanpa perlu flag**, karena `preventDefault()` pada fase
`keydown` membatalkan default action sehingga `beforeinput` / penyisipan / `input` tak pernah
terjadi sama sekali:

```
Desktop : keydown.space → preventDefault → spasi TAK PERNAH masuk field → jalur nilai buta
Gboard  : keydown tak cocok → tak ada preventDefault → spasi masuk field → jalur nilai jalan
```

Mekanismenya bukan hal baru: cabang penolakan karakter salah yang **sudah ter-ship**
(`race-arena.js:432`) sudah menulis balik `this.typedText` dengan cara yang persis sama.

### Aturan yang mudah salah

**"Potong di spasi pertama, buang sisanya" — bukan "hapus semua spasi".**

Kalau semua spasi dihapus, `"th e"` untuk target `"the"` akan **lolos** sebagai "diketik
persis benar", dan aturan word-lock berubah diam-diam.

Membuang sisa setelah spasi pertama juga menjamin **maksimum satu kata maju per event**.
Kalau sisanya disimpan, pemain bisa mem-paste seluruh paragraf lalu men-drain satu kata per
tap sembarang. Karena juara ditentukan **waktu selesai**, itu langsung jadi strategi optimal
— persis kelas bug yang word-lock diciptakan untuk membunuh
([`multiplayer-race.md`](../features/multiplayer-race.md) §3.2).

Perlu dicatat: **hari ini paste terlindungi secara kebetulan** — `checkInput()` menolaknya
karena bukan prefiks. Jalur nilai yang baru melemahkan perlindungan tak-disengaja itu, jadi
paste harus ditutup **secara sengaja** dengan `@paste.prevent`.

### Invarian yang tak boleh pecah

`advanceWord()` harus tetap **satu-satunya** penulis `+=` untuk `correctCharsFromPastWords`
(satu-satunya `=` lain adalah `restoreProgress()`, yang menurunkan ulang dari persen server).
Server menurunkan Net WPM dari `progress% × panjang teks` dan **tak pernah melihat teks yang
diketik** — word-lock di client adalah satu-satunya penegak invarian "progres hanya naik dari
karakter benar" ([`anti-cheat-wpm.md`](../features/anti-cheat-wpm.md) §5.1).

---

## 4. Langkah kerja

Urutannya sengaja memisahkan **refactor murni** (langkah 2–3) dari **jalur baru** (langkah 4).
Jalankan seluruh suite race di akhir langkah 3: kalau ada yang pecah di situ, penyebabnya
refactor, bukan fitur baru.

| # | Langkah | Berkas |
|---|---|---|
| 0 | Dokumen ini | `docs/plans/mobile-tahap-1.md` |
| 1 | Atribut platform + `aria-label` pada input balapan; lang key `input_aria` di **kedua** bahasa | `multiplayer-lobby.blade.php`, `lang/{en,id}/multiplayer.php` |
| 2 | Ganti helper test yang rusak dengan `raceMethodSource()` | `tests/Pest.php`, `RaceProgressIntegrityTest.php` |
| 3 | Ekstrak `advanceWord()`; `preventDefault()` jadi baris pertama | `race-arena.js` |
| 4 | Jalur nilai untuk spasi di `checkInput()` | `race-arena.js` |
| 5 | `@paste.prevent @drop.prevent`, ikat Enter ke `handleSpace` | `multiplayer-lobby.blade.php` |
| 6 | Atribut mobile pada dua konfirmasi exact-match | `clans.blade.php:510`, `settings.blade.php:140` |
| 7 | `RaceMobileInputTest.php` baru + tripwire di `RaceProgressIntegrityTest` | `tests/Feature/` |
| 8 | Dokumentasi §3.16 + checklist uji perangkat + Pint | `docs/` |

### Catatan per langkah yang mudah terlewat

**Langkah 1** — `text-base` (16px) sudah ada di input balapan; jangan diubah, tapi dikunci
test supaya tak hilang (di bawah 16px, iOS Safari memperbesar halaman saat input difokus).
`LangParityTest` membandingkan key ter-sort dengan `toBe`, jadi lupa satu sisi = merah.

**Langkah 2** — `handleSpaceSource()` yang ada sekarang memotong dari `handleSpace(` **sampai
akhir file**, jadi ia sebenarnya menguji "ada di suatu tempat di bawah sini". Ia juga memakai
kemunculan **pertama** nama itu, sehingga sebuah komentar yang menyebut nama metode bisa
menggeser jendela pemeriksaan tanpa satu pun test berubah warna. Kalau tak diganti lebih
dulu, refactor langkah 3 membuat kedua test gerbangnya **hijau palsu atau merah** tergantung
di mana `advanceWord()` diletakkan.

**Langkah 3** — `e.preventDefault()` harus jadi **baris pertama, tanpa syarat**. Hari ini
`return` di baris 614 mendahului `preventDefault()` di baris 618: kalau guard menyala, spasi
bocor ke field dan **kedua** jalur jalan. Sekarang tak berbahaya hanya karena input
di-`:disabled` dengan kondisi yang persis sama — kebetulan yang tak dijaga siapa pun.

**Langkah 4** — `return` **segera** setelah `advanceWord()`. `checkInput()` memegang
`targetWord` dari sebelum kata maju; jatuh terus akan mengevaluasi cek kata terakhir dengan
target basi dan mengirim `emitProgress` kedua. Juga: beri credit keystroke untuk kata yang
mendarat utuh (swipe mengirim `"the "` sekaligus) **hanya di cabang yang `return`** — kalau
ditaruh sebelum percabangan, `if (isNewChar)` akan menghitungnya dua kali. Tanpa credit itu,
pemain swipe selalu tampil akurasi 100% sementara pemain desktop membayar tiap typo.

---

## 5. Yang sengaja TIDAK dikerjakan di tahap ini

Bagian ini yang menahan scope agar tak melebar di tengah sesi pair programming.

| Ditunda | Ke tahap | Kenapa tidak sekarang |
|---|---|---|
| Estetika & kepadatan (lane balapan, paragraf `text-xl` tetap, 14 teks < 11px) | 4 | Kelas masalah berbeda; butuh keputusan desain, bukan perbaikan bug |
| 15 input lain + zoom iOS pada field < 16px | 4 | Menyentuh ~12 file; regresinya lebih sulit dilacak kalau digabung |
| `dvh` untuk `h-[70vh]` di chat | 2 | Perlu hasil uji perangkat dulu — `dvh` yang berubah saat address bar muncul bisa bikin layout berdenyut |
| `hoverOnlyWhenSupported` | 3 | Flag ini tak memperbaiki apa pun sendirian; ia **membuat masalahnya kelihatan**, jadi harus datang bersama pasangannya (statistik live yang sekarang `opacity-60 hover:opacity-100` akan permanen redup di HP) |
| Buang `x-model` demi arsitektur `:value` satu arah | — | Lebih bersih, tapi menulis ulang lapisan input tanpa test JS, dan melanggar `RaceStateResetTest.php:71`. Dicatat sebagai pekerjaan masa depan |

---

## 6. Cara memverifikasi

### Otomatis — harus hijau seluruhnya sebelum uji perangkat

```bash
php artisan test tests/Feature/RaceMobileInputTest.php
php artisan test --filter=Race
php artisan test tests/Feature/LangParityTest.php
php artisan test                 # suite penuh
vendor/bin/pint --test
```

Test yang paling mungkin patah tanpa disadari:

- `RaceLiveWpmTest.php:119-125` — `substr_count` `clearInterval` harus tetap **2**; jangan
  tambahkan pembersihan ticker di `advanceWord()`.
- `RaceProgressIntegrityTest.php:52-62` — identifier `missingCount` / `wordHadError` tak
  boleh hidup lagi.
- `RaceAssetTest.php:14` — Blade tak boleh memuat `<script`; semua kode baru masuk
  `race-arena.js`.

### Manual — tak tergantikan

Proyek ini tak mengeksekusi JavaScript di test sama sekali, jadi seluruh test di atas adalah
**kontrak markup & modul, bukan bukti perilaku.** Butuh minimal dua HP: satu Android/Gboard,
satu iOS/Safari. Checklist lengkap ada di
[`docs/mobile-test-checklist.md`](../mobile-test-checklist.md).

Ringkasnya:

1. Buat room dari desktop, join dari HP, mulai balapan.
2. Ketik kata pertama huruf demi huruf → **huruf pertama harus masuk**.
3. Spasi → kata maju. Ulangi dengan swipe-typing → **satu** kata maju, bukan dua.
4. Enter → kata maju, keyboard **tidak** tertutup.
5. Sengaja salah ketik → karakter ditolak, dan **catat apakah sinyal merahnya terlihat**.
6. Paste satu paragraf → tak ada yang masuk.
7. Selesaikan balapan → WPM & akurasi masuk akal, bukan 100% gratis.
8. Dari HP: hapus akun uji, dan bubarkan clan → nama yang diketik **cocok**.

**Langkah 5 dan 7 adalah yang paling penting dilaporkan balik.** Di HP, mata pemain ada di
keyboard dan setengah layar tertutup — kemungkinan besar sinyal merah `justBlocked` tak
pernah terlihat, sehingga penolakan tetap terasa seperti "HP-ku ngelag". Apakah perlu isyarat
tambahan **diputuskan dari perangkat, bukan dari kode.**
