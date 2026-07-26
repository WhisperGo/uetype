# Checklist Uji Perangkat — Mobile

**Kenapa dokumen ini ada:** proyek ini **tidak mengeksekusi JavaScript di test sama sekali**
(lihat [`RaceAssetTest`](../tests/Feature/RaceAssetTest.php)). Seluruh test mobile yang ada —
[`MobileTypingInputTest`](../tests/Feature/MobileTypingInputTest.php),
[`RaceMobileInputTest`](../tests/Feature/RaceMobileInputTest.php) — adalah **kontrak markup &
modul**: ia mengunci keberadaan jalurnya, bukan membuktikan keyboardnya bekerja.

Jadi hijau di CI **bukan** bukti fiturnya jalan di HP. Checklist ini yang membuktikannya.

## Perangkat minimum

| Perangkat | Kenapa wajib |
|---|---|
| Android + **Gboard** | Keyboard yang melaporkan `keydown` sebagai `Unidentified`/229 selagi menyusun kata — sumber seluruh masalah |
| Android + **SwiftKey** | Perilaku composition berbeda dari Gboard; menutup asumsi "semua Android sama" |
| iOS **Safari** | Aturan zoom-on-focus & `focus()` harus di dalam gestur, keduanya khas Safari |

Uji dalam **portrait dan landscape**. Landscape penting karena keyboard memakan porsi layar
jauh lebih besar.

---

## A. Balapan multiplayer (prioritas utama)

Butuh dua perangkat: buat room dari desktop, join dari HP.

| # | Langkah | Hasil yang diharapkan | Kalau gagal |
|---|---|---|---|
| A1 | Buka `/multiplayer` di HP, masukkan kode room 6 digit | Keyboard muncul, keenam kotak terisi | Kotak kode belum punya atribut mobile — **diketahui, masuk Tahap 4** |
| A2 | Mulai balapan, sentuh field ketik | Keyboard layar terbuka | Cek `x-ref="typeInput"` benar-benar terender |
| A3 | Ketik **huruf pertama** kata pertama, huruf demi huruf | Hurufnya **masuk** dan kata aktif berubah | Ini bug utama yang diperbaiki. Cek `autocapitalize="off"` sampai ke HTML |
| A4 | Selesaikan kata, tekan **spasi** | Kata maju, field kosong | Jalur nilai spasi tak jalan |
| A5 | Ketik kata berikutnya dengan **swipe-typing** lalu spasi | **Satu** kata maju — bukan dua | Kalau lompat >1 kata: sisa setelah spasi tidak dibuang. **Ini lubang anti-cheat, hentikan rilis** |
| A6 | Ketik kata, tekan **Enter** (tuts aksi kanan bawah) | Kata maju, keyboard **tetap terbuka** | Label `next` ada tapi binding-nya tidak |
| A7 | Sengaja salah ketik satu huruf | Huruf **tak masuk**; ada sinyal merah | Lihat catatan penting di bawah |
| A8 | Tekan spasi saat kata belum selesai | Kata **tak maju**; ada sinyal merah | Word-lock bocor |
| A9 | Tekan spasi berkali-kali saat field kosong | Tak ada yang maju | — |
| A10 | Salin satu paragraf, coba paste ke field | **Tak ada** yang masuk | `@paste.prevent` tak terpasang |
| A11 | Biarkan autocorrect mengganti kata (ketik kata mirip) | Kata pengganti ditolak, field kembali ke prefiks benar | `autocorrect="off"` tak sampai |
| A12 | Selesaikan balapan sampai garis akhir | Layar hasil muncul, WPM & akurasi **masuk akal** | Akurasi tepat 100% padahal ada typo = credit keystroke swipe tak jalan |
| A13 | Putar layar ke landscape di tengah balapan | Teks & lintasan tetap terbaca, ketikan tak hilang | — |

### ⚠ Dua langkah yang paling penting dilaporkan balik

**A7 — apakah sinyal merahnya benar-benar terlihat?**
Di HP, mata pemain ada di keyboard dan setengah layar tertutup. Penolakan karakter ditandai
lewat warna merah pada kata aktif dan field — keduanya berada **di atas** keyboard. Sangat
mungkin pemain tak pernah melihatnya, sehingga penolakan tetap terasa seperti "HP-ku ngelag".

Catat jawabannya: **terlihat jelas / samar / tak terlihat sama sekali.** Kalau bukan yang
pertama, kita perlu isyarat tambahan (getaran? sinyal di dekat field?) — dan itu **diputuskan
dari perangkat, bukan dari kode**.

**A12 — akurasi pemain swipe.**
Kata yang mendarat utuh dalam satu event diberi credit keystroke supaya akurasinya jujur.
Bandingkan: main sekali dengan mengetik huruf demi huruf, sekali dengan swipe, dengan jumlah
typo yang mirip. Angkanya harus sebanding. Kalau swipe selalu 100%, credit-nya tak jalan.

### Yang tak bisa ditutup dari HTML — amati khusus

- **IME masih menyusun saat kata maju.** Spasi biasanya mengakhiri composition, jadi jendelanya
  sempit — tapi kalau kamu melihat huruf sisa tertinggal di field setelah kata maju, catat
  keyboard dan langkahnya persis.
- **Gboard "dobel spasi jadi titik".** Tekan spasi dua kali cepat. Titik yang muncul harus
  ditolak seperti karakter salah biasa, bukan membuat kata maju atau field macet.

---

## B. Mengetik solo

Sudah diperbaiki sebelumnya; ini uji regresi.

| # | Langkah | Hasil yang diharapkan |
|---|---|---|
| B1 | Buka `/typing`, sentuh area teks | Keyboard terbuka **tanpa** perlu menekan tombol petunjuk |
| B2 | Ketik beberapa kata | Karakter masuk, caret bergerak, tak ada yang terhitung dua kali |
| B3 | Tekan Backspace | Mundur satu karakter, bukan dua |
| B4 | Swipe-typing satu kata | Masuk per karakter, statistiknya wajar |
| B5 | Fokuskan field, perhatikan halaman | Halaman **tidak** ter-zoom (iOS) |
| B6 | Tekan restart, lalu coba ketik lagi | **Diketahui:** keyboard tertutup, harus sentuh teks lagi. Konfirmasi masih begitu |

---

## C. Konfirmasi exact-match

| # | Langkah | Hasil yang diharapkan |
|---|---|---|
| C1 | Dari HP, buka Settings → hapus akun (**pakai akun uji**), ketik username persis | Diterima — huruf pertama **tidak** dikapitalisasi otomatis |
| C2 | Dari HP, sebagai leader clan uji, buka Clans → bubarkan, ketik nama clan persis | Diterima |

> C1 menghapus akun sungguhan. Pakai akun uji, atau `/dev-login` di environment local.

---

## Cara melaporkan

Untuk tiap baris: **✅ lolos** / **❌ gagal** + perangkat + keyboard + versi OS. Untuk yang
gagal, sertakan apa yang **terlihat di layar**, bukan hanya "tidak jalan" — di kelas bug ini
bedanya "tak ada reaksi" dan "reaksi salah" menunjuk ke jalur kode yang berbeda.

A7 dan A12 mohon dijawab walaupun lolos, karena keduanya menentukan pekerjaan berikutnya.
