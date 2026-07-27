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
| A14 | Lihat panel **LIVE STANDINGS** | Tiap lane: nama + WPM di baris atas, **lintasan penuh** di baris bawah. Maskot & bendera finis terpisah jelas | Kalau maskot masih menempel di bendera, lane belum membungkus |
| A15 | Ketik terus sampai melewati baris **kedua** paragraf | Baris 1 & 2 **diam sama sekali**; paragraf baru **menggeser sendiri** satu baris tepat saat kata aktif masuk baris **ketiga**, lalu kata aktif duduk di baris **tengah** (satu baris konteks di atas, satu lookahead di bawah) | Kalau tak bergeser, `syncWordScroll()` tak terpanggil. Kalau bergeser sejak baris kedua, rumusnya bukan rumus solo |
| A16 | Sepanjang balapan, tanpa menggulir sama sekali | Paragraf **dan** field ketik terlihat bersamaan | Kalau field tertutup keyboard, kita perlu `interactive-widget=resizes-content` |
| A17 | Reload halaman di tengah balapan | Paragraf langsung terposisi di kata tempat kamu berhenti, bukan di kata pertama | `syncWordScroll()` di `init()` tak jalan |
| A18 | Ketik **melewati baris ketiga** sampai akhir tanpa berhenti, sementara lawan juga aktif | Paragraf hanya bergeser **turun**, satu baris per lompatan, dan **tak pernah melompat balik ke atas** | Kalau kotaknya melompat tiba-tiba: `wire:ignore` pada kartu paragraf hilang → morph Livewire (dari emit progres kita sendiri, ~8x/detik) menghapus inline `transform`-nya. Lihat `docs/features/multiplayer-race.md` |

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

## D. Navigasi (Tahap 3)

Lebar yang wajib dicek: **360, 390, 414, 640, 768, 1024**. Yang paling menentukan **640 dan 768** —
di sanalah dulu seluruh bar desktop muncul sekaligus lalu meluber.

| # | Langkah | Hasil yang diharapkan | Kalau gagal |
|---|---|---|---|
| D1 | Buka halaman apa pun di 640px dan 768px | Yang muncul **hamburger**, bukan bar desktop. Tak ada elemen nav yang terpotong atau menumpuk | Ambang `md:` hilang, kembali ke `sm:` |
| D2 | Login dengan username panjang (>12 karakter), lihat nav di 640px | Username **ter-truncate** dengan elipsis; bar tak melebihi lebar layar, tak ada scroll horizontal | `min-w-0` atau `truncate` hilang |
| D3 | Buka panel hamburger, lalu sentuh salah satu link | Panel **tertutup**, halaman baru terlihat penuh tanpa panel menutupinya | `livewire:navigating` di `nav-badges.js` tak jalan |
| D4 | Buka panel, lalu ketuk di area **luar** panel | Panel tertutup | `@click.outside` pindah dari `<nav>` ke panel — ia akan menutup panel di frame yang sama saat dibuka |
| D5 | Buka panel, tekan Escape (keyboard eksternal / emulator) | Panel tertutup | — |
| D6 | Buka modal atau drawer chat **di atas** panel yang tertutup, tekan Escape | Yang tertutup **modal/drawer**, bukan sesuatu yang lain | Guard `&& this.open` hilang → handler nav menelan Escape milik lapisan di atasnya |
| D7 | Ketuk hamburger dua kali cepat | Panel buka lalu tutup, tidak "nyangkut" terbuka | `@click.stop` hilang |
| D8 | Muat ulang halaman di HP, perhatikan frame pertama | Panel **tak pernah berkedip terbuka** | `x-cloak` hilang |
| D9 | Buka dropdown akun di jendela sempit (~400px, desktop) | Panel dropdown tetap **di dalam** layar, tak menjorok keluar tepi kanan | Clamp `max-w-[calc(100vw-2rem)]` hilang |

---

## E. Target sentuh & hover (Tahap 2 & 6)

Uji dengan **ibu jari**, bukan telunjuk, dan **bukan** dengan mouse di DevTools — ukuran target
hanya terasa dengan jari sungguhan.

| # | Langkah | Hasil yang diharapkan | Kalau gagal |
|---|---|---|---|
| E1 | Buka drawer chat, tekan tiap tombol di header (kembali, buka penuh, hapus riwayat, tutup) | Tiap tombol kena **sekali coba** | Kotak klik < 44px |
| E2 | Bermaksud **menutup** drawer, tekan tombol tutup 5× berturut-turut | Modal "hapus riwayat" **tak pernah** muncul | Jarak/warna tombol destruktif kurang — ini bug paling merugikan di kelas ini |
| E3 | Bandingkan tinggi header drawer chat, header chat halaman penuh, dan bar navigasi dengan prototype | **Sama persis** seperti sebelumnya; ruang daftar pesan tak berkurang | Margin negatif penyerap kotak klik hilang → tombol 44px mendorong barisnya |
| E4 | Sebagai host di lobby, tekan tombol kick (X merah) di kartu member | Kena sekali coba, dan **tidak** memicu tap kartunya sendiri | Kotak klik menabrak area kartu |
| E5 | Buka profil teman, lihat tombol hapus pertemanan | Tombol **terlihat** tanpa perlu hover | Pola `opacity-0 group-hover:` kembali — di HP artinya tombolnya tak pernah ada |
| E6 | Mulai tes ketik solo, ketik beberapa detik, lihat panel statistik (WPM/timer) | Statistik **terbaca jelas**, tidak permanen redup | Pemulihan `pointer: coarse` hilang; `hover:` tak berlaku di layar sentuh |
| E7 | Tekan tombol apa pun, lalu geser jari menjauh | Tombol **tidak** tertinggal dalam keadaan ter-hover | `hoverOnlyWhenSupported` mati |
| E8 | Tekan tombol tutup pada toast notifikasi yang muncul | Kena sekali coba | Toast muncul tanpa diminta; tombolnya dulu 16px |

### Zoom iOS — hanya bisa diuji di iPhone sungguhan

Tak bisa direproduksi di DevTools. Fokuskan ke tiap field ini di **iOS Safari** dan pastikan
halaman **tidak** membesar sendiri:

| # | Field |
|---|---|
| E9 | Pencarian teman (`/friends`) dan pencarian clan (`/clans`) |
| E10 | Username di Settings |
| E11 | Chat di dalam room multiplayer |
| E12 | Konfirmasi hapus akun & bubarkan clan (yang sama dengan C1/C2) |
| E13 | Jumlah hari di modal hapus riwayat chat |
| E14 | Nama, tag, dan deskripsi di form **buat clan** & **edit clan** (`/clans`) — dulu `text-sm`, dinaikkan ke `text-base` |

Kalau halaman mem-zoom, ia **tak kembali sendiri** — user terjebak sampai mencubitnya. Laporkan
field mana yang memicunya.

---

## F. Section clan — pass responsif (layout HP)

Section clan sudah mostly mobile-first; pass ini menutup pelanggaran konvensi + merapikan
baris yang sempit di HP. Uji terutama di **360 dan 390** (HP sempit) dan bandingkan di **414**
(tempat label status pending kembali muncul di ≥400px/`xs`).

| # | Langkah | Hasil yang diharapkan | Kalau gagal |
|---|---|---|---|
| F1 | Sebagai leader/co-leader, buka roster clan, tekan tombol **kebab (⋮)** aksi member dengan ibu jari | Kena **sekali coba** (kotak klik 44px lewat `<x-icon-button>`) | Kembali ke tombol mentah `p-1.5` (~28px) |
| F2 | Buka clan sendiri di 360px, lihat **toolbar hero** (Clan War / Chat / Leaderboard / Manage) | Toolbar menempati **baris penuh sendiri** di bawah identitas, rapi — bukan berdesakan di samping | `w-full sm:w-auto` hilang; tombol wrap 3–4 baris mepet identitas |
| F3 | Sebagai leader dengan join-request masuk, lihat baris request di 360px | Nama tetap terbaca; **Accept + Reject** turun ke baris sendiri, tak menghimpit username jadi 3–4 huruf | Grup aksi tak `w-full sm:w-auto`, atau baris tak `flex-wrap` |
| F4 | Buka tab **browse**, temukan clan berstatus "pending", lihat di 360px lalu 414px | Di <400px hanya tombol **Cancel** (label "request sent" tersembunyi); di ≥400px label muncul kembali | `hidden xs:inline` hilang; label menghimpit nama clan |
| F5 | Buka Clan War yang sedang berjalan dengan clan lawan **bernama panjang tanpa spasi** | Nama lawan **membungkus / tak meluber**; tak ada scroll horizontal | `min-w-0`/`break-words` di blok lawan hilang |

> Catatan: F1–F5 semuanya **kontrak markup** yang sudah dikunci
> [`ClanResponsiveTest`](../tests/Feature/ClanResponsiveTest.php); yang diverifikasi di sini
> adalah hasil **visual/sentuh sungguhan di perangkat**, yang tak bisa dibuktikan test.

---

## Cara melaporkan

Untuk tiap baris: **✅ lolos** / **❌ gagal** + perangkat + keyboard + versi OS. Untuk yang
gagal, sertakan apa yang **terlihat di layar**, bukan hanya "tidak jalan" — di kelas bug ini
bedanya "tak ada reaksi" dan "reaksi salah" menunjuk ke jalur kode yang berbeda.

A7 dan A12 mohon dijawab walaupun lolos, karena keduanya menentukan pekerjaan berikutnya.
