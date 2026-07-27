# Rencana — Responsif & Mobile, Tahap 2–6: Target Sentuh, Navbar, Skala Tipe

**Dibuat:** 2026-07-26 · **Status:** kode selesai, **menunggu verifikasi perangkat**
**Cakupan:** target sentuh, affordance hover, navbar di layar sempit, skala tipe, satuan viewport.

> **Status per tahap:** 2–6 selesai; **869 test hijau**, Pint bersih pada berkas yang disentuh.
> Yang belum: **uji di HP sungguhan** ([`../mobile-test-checklist.md`](../mobile-test-checklist.md)
> bagian D & E). Tahap ini belum boleh disebut beres sebelum D3 dan E1 dijawab.

> Dokumen ini adalah **rencana kerja**, bukan deskripsi keadaan sekarang. Aturan permanen yang
> lahir dari tahap ini sudah dipindah ke
> [`../design-system.md`](../design-system.md) (target sentuh, `dvh` vs `vh`, tangga breakpoint).

Lanjutan dari [`mobile-tahap-1.md`](mobile-tahap-1.md), yang menutup blocker fungsional di input
balapan. Tahap ini menutup baris-baris di tabel penundaannya (§5).

---

## 1. Kenapa ini dikerjakan

User melaporkan: *"masih banyak bug di desain ketika aku pakai web ini di handphone, mulai dari
navbar, ada tombol yang tidak bisa dipencet, ukuran yang tidak sesuai."*

Ketiga keluhan itu nyata. **Tak satu pun disebabkan oleh layout.** Audit Tahap 1 (§1) sudah
menyimpulkan kerangka layout-nya responsif dan memperingatkan agar tidak menambal `sm:`/`md:`
di mana-mana; verifikasi ulang di tahap ini menegaskannya: 8 dari 8 tabel sudah dibungkus
`overflow-x-auto`, keempat hit `w-[≥100px]` adalah `max-w-[100px]` jinak pada span `truncate`,
hanya 3 `grid-cols-N` tanpa guard.

Statistik "59.5% berkas Blade tanpa prefix responsif" **bukan bukti kerusakan** — berkas-berkas
itu mengalir mobile-first dengan `flex`/`grid`. Angka itu godaan untuk mengerjakan hal yang salah.

Yang benar-benar rusak, empat kelas:

| # | Keluhan | Penyebab | Kelas |
|---|---|---|---|
| 1 | "tombol tak bisa dipencet" | Target sentuh 16–24px (WCAG 2.5.5 minta 44px). `chat-overlay:73` & `:126` literal **16×16px tanpa padding** | Kehilangan fungsi |
| 2 | "tombol tak bisa dipencet" (sebab kedua) | `friend-button:20` `opacity-0 group-hover:opacity-100` — tombol hapus-pertemanan **tak pernah terlihat** di layar sentuh | Kehilangan fungsi total |
| 3 | "navbar" | Nav hanya punya `sm:`, **nol `md:`** → dari 640px seluruh bar desktop muncul & meluber; username tanpa `min-w-0`; panel tak bisa ditutup | Terlihat di setiap halaman |
| 4 | "ukuran tak sesuai" | `lineHeight: '1'` pada 9 token fontSize → tinggi tombol = font-size + padding saja → `py-[5px] text-small` = **~23px** | Akar #1 dan #4 bertemu |

**Temuan #2 tak tercatat di audit mana pun sebelumnya.** Ia lebih parah dari #1: tombolnya bukan
kecil, melainkan tidak ada — dan ia tampak berfungsi bagi siapa pun yang menguji dengan mouse.

---

## 2. Yang dikerjakan per tahap

### Tahap 2 — Target sentuh & affordance hover

Komponen baru [`components/icon-button.blade.php`](../../resources/views/components/icon-button.blade.php):
`min-w-[44px] min-h-[44px]`, prop `tone` (`default`/`danger`), prop `as` (`button`/`a`), dan
**`label` wajib** (tanpa default) sehingga tombol tanpa nama aksesibel tak bisa dirender.

Dipilih di atas dua alternatif:

- **Edit per-lokasi (~18 tempat)** — ditolak. Itu justru kelas masalah yang
  `SharedComponentsTest` dibuat untuk mencegah; komentar pembukanya menyebut preseden yang sama
  (8 blok empty-state disalin lalu menyimpang jadi 4 varian, dan duplikasi avatar **sudah terbukti
  melahirkan defect** — dua `alt` hilang tepat di markup yang ditulis tangan).
- **Utility class `.tap-44`** — ditolak sebagai solusi utama. Ukuran bukan satu-satunya defect:
  tiap lokasi juga menulis sendiri warna dan `aria-label`. Utility hanya tersedia bagi yang ingat
  memakainya; komponen memberi *default*.

**Ukurannya sengaja TIDAK dibungkus `@media (pointer: coarse)`**, berbeda dari `.touch-only` di
`app.css`. Query itu benar untuk `.touch-only` karena ia memutuskan apakah elemen **ada** — isyarat
"tap untuk mengetik" memang omong kosong di desktop. Target 44px tak pernah salah di mana pun: WCAG
2.5.5 bukan kriteria khusus sentuh, mouse dengan tremor diuntungkan, dan perangkat hibrida (iPad +
trackpad) melapor `fine` padahal dipakai dengan jari. Yang menentukan: **proyek ini tak mengeksekusi
CSS di test**, jadi cabang media query akan lolos tanpa penjaga.

16 kontrol dikonversi di 9 berkas. Tiga yang **tidak** dipindah ke komponen, dengan alasan:

| Lokasi | Kenapa tetap manual |
|---|---|
| Kick spectator & member (`multiplayer-lobby:257`, `:292`) | Lingkaran-X-merah adalah affordance tersendiri; komponennya bernada netral. Yang dipinjam **aturannya**: lingkaran tetap 20/24px, kotak klik 44px. Pada `:292` kotak kliknya sengaja **menjorok keluar** batas kartu (`-top-1 -right-1`) — kartu di `grid-cols-2` cuma ~160px di HP, jadi lingkaran 44px akan menutupi seperempatnya. Kotak klik boleh melampaui yang terlihat. |
| Trigger menu pesan (`chat/message:169`) | Pil kecil di samping tiap bubble; 44px yang terlihat akan menyesaki thread. `-m-2.5` menyerap kotak kliknya. |
| Simpan-edit (`chat/message:87`) | `type="submit"`, bukan `button` — komponen ini tak meneruskan tipe submit. |

> **Tinggi & padding container TIDAK diubah — semuanya mengikuti prototype.** Tombol 44px akan
> menumbuhkan header chat dari ~52px ke ~76px, jadi tiap tombol menyerap kelebihan kotaknya dengan
> margin negatif sepadan padding induknya (`-my-3` di header `p-4`, `-my-2.5` di `p-3`). Hasilnya:
> kotak kliknya 44px untuk jari, barisnya tetap setinggi semula. Aturan ini sekarang tertulis di
> [`../design-system.md`](../design-system.md#target-sentuh).

`openClearModal` (hapus riwayat chat) dapat `tone="danger"` **dan** `ms-1`: ukuran memperbaiki "tak
bisa dipencet", jarak + warna memperbaiki "kepencet yang salah".

### Tahap 3 — Navbar

- Ambang desktop `sm:` → **`md:`** pada grup link, sisi kanan, hamburger, dan panel. 640px itu HP
  lanskap, bukan desktop.
- Wordmark `text-lg` → `text-base lg:text-lg`. Press Start 2P glyph-nya sangat lebar dan
  design-system melarangnya memenuhi layar. **Logo `h-12` dan `gap-3` tetap** — itu tinggi
  prototype. Hamburger dapat kotak klik 44px, yang lebih besar dari `p-2` sebelumnya tapi masih di
  bawah tinggi bar `h-16`, jadi bar-nya tak berubah.
- Username: `min-w-0` + `truncate max-w-[12ch]`. Item flex punya `min-width: auto`, jadi tanpa itu
  username panjang **tak bisa menyusut** dan mendorong bar melebihi viewport.
- Hamburger: `type="button"` (tanpa itu sebuah `<button>` di dalam `<form>` mem-submit — aman hari
  ini hanya karena **kebetulan**), `aria-expanded`/`aria-controls`/`aria-label`, kotak klik 44px.
  Bentuknya menyalin FAB chat-overlay supaya kontraknya sama.
- Panel tertutup lewat **empat** jalan: tombol, `@click.outside`, Escape, dan `livewire:navigating`.

Dua jebakan yang sudah ditangani:

> **`@click.outside` ada di `<nav>`, bukan di panel.** Panel tak memuat hamburger, jadi handler di
> panel akan menyala pada tap yang membukanya dan menutupnya di frame yang sama. `<nav>` membungkus
> keduanya — susunan yang sama dipakai `dropdown.blade.php`. Hamburger juga dapat `@click.stop`.

> **Escape & navigate ada di `nav-badges.js`, bukan Blade.** `RaceAssetTest` melarang `<script>` di
> Blade. Guard `&& this.open` load-bearing: tanpanya handler ini menelan Escape milik modal atau
> chat overlay yang sedang di atasnya.

**`dropdown.blade.php` yang men-shadow `open` sengaja TIDAK "diperbaiki".** Menghapus `x-data`
lokalnya akan membuat setiap `<x-dropdown>` berbagi satu `open` dengan induknya — memperbaiki satu
kebetulan dengan menciptakan kopling global. Niatnya didokumentasikan di komentar `navigation:93`.

`NavigationTest:94` diperbarui **secara sengaja** dari `sm:items-center` ke `md:items-center`, bukan
dilemahkan. Yang dijaga bukan nama breakpoint-nya melainkan bahwa `items-center` ada di breakpoint
tempat nav desktop hidup.

### Tahap 4 — Skala tipe

> **Perubahan `lineHeight` DIBATALKAN.** Sempat diubah ke per-token (judul 1.1–1.3, teks berjalan
> 1.4–1.5) untuk menyelesaikan tombol ~23px, lalu dikembalikan ke `1` seragam: skala tipe mengikuti
> prototype dan bukan milik kode untuk diubah. Konsekuensinya tetap dicatat di
> [`../design-system.md`](../design-system.md), dan tombol pendeknya diselesaikan di tempat yang
> benar — memberi kontrolnya ukuran eksplisit lewat `<x-icon-button>`, bukan melonggarkan token
> yang dipakai 126 kali.

Breakpoint `xs: '400px'` ditambahkan, dan **tangga lengkapnya ditulis di level `theme`, bukan di
`extend`**: key di dalam `extend` ditempel setelah default, jadi `xs` akan berurutan setelah `2xl`
dan media query-nya kalah dari semua breakpoint lain — gagal diam-diam tanpa satu kelas pun terlihat
salah. Sudah diverifikasi di CSS hasil build: urutannya 400 → 640 → 768 → 1024 → 1280 → 1536.

400px, bukan 480px: batasnya harus jatuh **di antara** HP kecil (360/390) dan HP besar (414/430).

**h1–h6 sengaja TIDAK dibuat fluid.** `text-h1` dan `text-h2` masing-masing dipakai **sekali** di
seluruh view layer — itu masalah dua tempat, bukan masalah token. Sementara `text-x-small` dipakai
62×; mengubah nilainya berarti satu commit yang menggeser tampilan setiap halaman sekaligus, tanpa
satu test pun yang bisa membuktikan hasilnya. `style-guide.blade.php:190` juga sudah menyatakan
kontraknya: *"Teks sekunder tetap pakai skala token."*

`typing-result:4` diberi `sm:px-6 lg:px-8` — ia satu-satunya halaman yang tertinggal di `px-4`
datar. Dua kartu hero-nya `px-8` → `px-5 xs:px-8` (64px padding di layar 360px menyisakan ~264px).

> **`w-max` di `typing-result:595` sengaja DIBIARKAN.** Rencana awal menyebutnya defect. Ia bukan:
> itu heatmap keyboard di dalam `overflow-x-auto`, dan `w-max` justru yang membuatnya bisa digulir
> horizontal alih-alih gepeng.

### Tahap 5 — Satuan viewport & input

> **Perubahan `dvh` DIBATALKAN.** Ketiga layout sempat dipindah ke `min-h-[100dvh]` untuk
> menghilangkan scroll palsu ~60–100px di iOS Safari, lalu dikembalikan ke `min-h-screen`: tinggi
> mengikuti prototype. Scroll palsu di iOS **masih ada** dan sekarang tercatat sebagai masalah
> terbuka di [`../design-system.md`](../design-system.md#satuan-viewport) — menanganinya butuh
> keputusan desain lebih dulu. `ViewportUnitTest` kini mengunci `vh` sebagai **larangan** terhadap
> penggantian satuan sepihak.

7 field dinaikkan ke `text-base` (16px). Di bawah itu iOS Safari memperbesar seluruh halaman saat
field difokus dan **tak mengembalikannya**. Dua di antaranya field konfirmasi exact-match yang sudah
dikeraskan di Tahap 1 — ironisnya justru di sana zoom paling mengganggu.

> **Koreksi terhadap rencana:** asumsi "perbaiki `<x-text-input>` dan 15 field ikut beres" **tidak
> berlaku** — komponen itu dipakai hanya **1×** sementara ada **22** `<input>` mentah. `text-base`
> tetap ditambahkan ke komponennya supaya pemakai baru mewarisinya, tapi ketujuh field diperbaiki
> satu per satu.

### Tahap 6 — `hoverOnlyWhenSupported`

Urutannya wajib, dan dijalankan sesuai peringatan `mobile-tahap-1.md:188`: **perbaiki dulu, baru
nyalakan.** Flag ini tak memperbaiki apa pun sendirian — ia membuat ketergantungan hover yang sudah
ada jadi **kelihatan**.

Yang diperbaiki lebih dulu: panel statistik live di `typing-engine:227` (`opacity-60
hover:opacity-100` → ditambah `[@media(pointer:coarse)]:opacity-100`), dan tombol hapus-pertemanan
(Tahap 2c). Sisa 30 `group-hover:` hanya memperindah elemen yang sudah terbaca saat diam
(`text-gold`, `scale-`), plus dua pasang tukar-label pada kartu slot kosong yang teks diamnya tetap
jelas — semuanya degradasi yang aman.

---

## 3. Yang sengaja TIDAK dikerjakan

| Ditunda | Kenapa tidak sekarang |
|---|---|
| `interactive-widget=resizes-content` | Mempengaruhi **semua** halaman sekaligus dan belum terverifikasi di perangkat ([`features/multiplayer-race.md`](../features/multiplayer-race.md) §3.17). Lebih menentukan: ia memecahkan masalah yang **belum selesai diukur** — A7 & A12 di checklist masih belum dijawab. Dipasang sekarang, hasil uji buruk jadi ambigu: jalur input baru atau meta tag ini? |
| `viewport-fit=cover` | Tanpa `env(safe-area-inset-*)` yang menyertainya ia justru **memindahkan** konten ke bawah notch. Ia separuh dari sepasang; keduanya butuh menyentuh `.notif-lane` (`top: 5rem`) dan FAB (`bottom-5 right-5`) yang dijaga test numerik. |
| Membuat h1–h6 fluid | Lihat Tahap 4. Dua pemakaian, bukan masalah token. |
| Sentralisasi 11 halaman ke `<x-page-container>` | Defect nyatanya (`typing-result`) sudah diperbaiki langsung. Sisanya penyeragaman kosmetik yang menyentuh 11 berkas tanpa memperbaiki gejala apa pun — regresinya lebih sulit dilacak daripada nilainya. |
| Konversi 22 `<input>` mentah ke `<x-text-input>` | Ambang 16px-nya sudah ditutup per-field. Konversinya soal arsitektur komponen, bukan mobile. |
| Menyatukan idiom prop `size` (`btn-gold` map vs `friend-avatar` string) | Perlu keputusan desain, bukan perbaikan bug. Wajib diputuskan **sebelum** ada prop responsif ditambahkan ke keduanya. |
| Lint debt `tests/Feature/ChatTest.php` | Pint gagal di berkas ini **sebelum** tahap ini dimulai dan tak disentuh sama sekali. Memperbaikinya di sini mencampur perubahan tak berhubungan. |

---

## 4. Cara memverifikasi

### Otomatis

```bash
php artisan test                 # 869 hijau
php artisan test --filter=TouchTarget
php artisan test --filter=NavigationResponsive
php artisan test --filter=TypeScale
php artisan test --filter=ViewportUnit
vendor/bin/pint --test
npm run build
```

Test baru: `TouchTargetTest` (7), `NavigationResponsiveTest` (6), `TypeScaleTest` (4),
`ViewportUnitTest` (4). `bladeViews()` dipindah dari `SharedComponentsTest` ke `tests/Pest.php`
karena `TouchTargetTest` membutuhkannya juga — menyalinnya akan mengulangi persis kesalahan yang
dijaga oleh test yang memakainya.

Yang paling mungkin patah tanpa disadari:

- `NavigationTest:94` — sudah diperbarui ke `md:items-center`. Kalau ambangnya bergeser lagi,
  assertion ini ikut bergeser, **bukan** dihapus.
- `ToastStackTest` — jangan ubah `h-16` navbar atau `top: 5rem` `.notif-lane`.
- `LangParityTest` — key `nav.toggle_menu` wajib ada di **kedua** sisi.
- `ViewportUnitTest` — mengunci bahwa chat **tetap** `vh`. Itu larangan, bukan kelalaian.

### Manual — tak tergantikan

Proyek ini tak mengeksekusi JavaScript maupun CSS di test, jadi seluruh test di atas **kontrak
markup, bukan bukti perilaku.** Checklist lengkap:
[`../mobile-test-checklist.md`](../mobile-test-checklist.md) bagian **D (navigasi)** dan
**E (target sentuh)**.

DevTools → device toolbar (Ctrl+Shift+M). Lebar wajib: **360, 390, 414, 640, 768, 1024**, plus
**360×640 lanskap**.

Ringkasnya:

1. Drawer chat: tiap tombol ikon bisa ditekan dengan ibu jari **sekali coba**; "clear chat" tak
   pernah kepencet saat bermaksud menutup; header tak tumbuh.
2. Di 640px & 768px: nav tak meluber, yang muncul hamburger — bukan bar desktop.
3. Panel nav tertutup saat menyentuh link, mengetuk di luar, dan menekan Escape.
4. Username panjang ter-truncate, tak melebarkan bar.
5. Bandingkan tiap halaman sebelum/sesudah Tahap 4 — cari baris tabel/badge/kartu yang tumbuh tak
   terduga. `stats.blade.php` paling padat (38 pemakaian token tanpa `leading-*` eksplisit).
6. Tombol hapus-pertemanan **terlihat** di HP.
7. Statistik live saat mengetik tak permanen redup di HP.
8. iOS: fokus ke field pencarian teman/klan → halaman **tidak** mem-zoom.

**Langkah 1, 5, dan 8 yang paling penting dilaporkan balik.** Langkah 5 karena Tahap 4 satu-satunya
yang bisa menggeser tampilan halaman yang sudah benar; langkah 8 karena zoom iOS tak bisa
direproduksi di DevTools.
