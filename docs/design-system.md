# UeType — Design System

Acuan tunggal token, tipografi, dan aturan UI. Tema default: **"moonlight"** (biru dominan,
emas hangat sebagai aksen, netral gelap untuk struktur).

Pratinjau hidup: jalankan lokal lalu buka **`/style-guide`** (hanya aktif di environment `local`).

## Arsitektur token (2 lapis)

1. **Primitive** — ramp warna mentah `1–10` per keluarga. Sumber kebenaran palette,
   didefinisikan di [`tailwind.config.js`](../tailwind.config.js). Pakai langsung **hanya** di
   style guide atau saat mendefinisikan token semantic — **bukan** di komponen biasa.
2. **Semantic** — token *peran* (`background`, `surface`, `brand`, dst). Inilah yang dipakai
   komponen. Nilainya berupa CSS variable di [`resources/css/app.css`](../resources/css/app.css)
   (`:root`), format channel `R G B` agar opacity Tailwind (`bg-surface/50`) tetap jalan.

Ganti tema = ganti blok `:root` saja; komponen tidak perlu disentuh.

## Palet primitive

| Step | primary (biru) | secondary (emas) | accent (netral) | tertiary (merah) | active (hijau) |
|------|----------------|------------------|-----------------|------------------|----------------|
| 1  | `#E8EAF4` | `#F9F5F0` | `#E8E9EA` | `#F8E8EA` | `#E8F5EE` |
| 2  | `#C7CDE5` | `#F1E8DB` | `#C8C9CB` | `#EEC8CC` | `#C7E7D6` |
| 3  | `#9BA5D1` | `#E6D6BE` | `#9C9FA3` | `#E09DA4` | `#9AD4B5` |
| 4  | `#6C7BBB` | `#DBC3A0` | `#6E7278` | `#D26F7A` | `#6CC093` |
| 5  | `#4054A6` | `#D0B083` | `#42474F` | `#C54452` | `#3FAD73` |
| 6  | `#162E93` | `#C69F68` | `#191F28` | `#B81B2C` | `#159B54` |
| 7  | `#13277D` | `#A88758` | `#151A22` | `#9C1725` | `#128447` |
| 8  | `#102168` | `#8D714A` | `#12161C` | `#83131F` | `#0F6E3C` |
| 9  | `#0D1A54` | `#715B3B` | `#0E1217` | `#690F19` | `#0C5830` |
| 10 | `#0A1542` | `#59482F` | `#0B0E12` | `#530C14` | `#094626` |

Pakai via class Tailwind, mis. `bg-primary-6`, `text-secondary-3`, `border-accent-5`.

## Token semantic

| Token | → Primitive | Peran | Contoh class |
|-------|-------------|-------|--------------|
| `background` | primary-10 | latar halaman | `bg-background` |
| `surface` | primary-9 | kartu / panel | `bg-surface` |
| `elevated` | primary-7 | panel terangkat / border aktif | `bg-elevated` |
| `foreground` | secondary-1 | teks utama | `text-foreground` |
| `muted` | accent-3 | teks sekunder | `text-muted` |
| `border` | accent-5 | garis / pemisah | `border-border` |
| `brand` | primary-6 | biru logo / maskot | `bg-brand` `text-brand` |
| `gold` | secondary-6 | highlight, koin, aksen hangat | `text-gold` |
| `active` | active-6 | online, siap, status aktif | `text-active` |
| `danger` | tertiary-6 | error / missed / loss | `bg-danger` |

> **State aktif / online / ready:** pakai `active`. Untuk highlight kemenangan atau skor utama,
> tetap pertimbangkan `gold` bila konteksnya reward, bukan status aktif.

## Tipografi

Tiga keluarga font, satu skala modular (~1.2):

| Token | px | Class |
|-------|----|-------|
| h1 | 47.78 | `text-h1` |
| h2 | 39.81 | `text-h2` |
| h3 | 33.18 | `text-h3` |
| h4 | 27.65 | `text-h4` |
| h5 | 23.04 | `text-h5` |
| h6 | 19.20 | `text-h6` |
| body | 16 | `text-body` |
| small | 13.33 | `text-small` |
| x-small | 11.11 | `text-x-small` |

| Font | Class | Pakai untuk |
|------|-------|-------------|
| JetBrains Mono | `font-mono` | font utama UI & area mengetik |
| Pixelify Sans | `font-pixel` | heading / aksen game (piksel mudah dibaca) |
| Press Start 2P | `font-display` | display / judul besar — **hemat**, demi kenyamanan baca |

> `lineHeight` token = `1` (sesuai Figma "100%"). Untuk paragraf panjang, longgarkan manual
> (mis. `leading-relaxed`).

## Aturan pakai

- **Komponen pakai token semantic**, bukan primitive atau hex mentah. Primitive hanya untuk
  mendefinisikan semantic / showcase.
- **Press Start 2P jangan memenuhi layar** — cukup untuk judul/aksen agar teks tetap nyaman.
- Butuh warna yang belum ada perannya? Tambahkan **token semantic baru** yang menunjuk primitive —
  jangan tulis hex langsung di komponen.

## Layering & anchor (elemen melayang)

Semua elemen `fixed` di aplikasi ini berbagi satu tumpukan. Nilainya bukan angka acak —
urutannya menyatakan siapa yang boleh menutupi siapa:

| z-index | Elemen | Berkas |
|---|---|---|
| 55 | Drawer chat | `livewire/chat-overlay.blade.php` |
| 56 | FAB chat (tombol bulat) | `livewire/chat-overlay.blade.php` |
| 57 | Modal clear-chat | `components/chat/clear-modal.blade.php` |
| 70 | Modal umum | `components/modal.blade.php` |
| 80 | `.notif-lane` (toast + undangan room) | `css/app.css` |

Notifikasi berada **di atas modal** dengan sengaja: ia kecil, transien, dan duduk di kanan
atas, sementara modal terpusat — jadi tak saling menutupi isi.

### Aturan: satu pojok, satu pemilik

- **Kanan bawah = *action corner*.** Kontrol permanen yang dituju pengguna dengan sengaja.
  Saat ini FAB chat, dan **hanya** FAB chat.
- **Kanan atas (di bawah navbar) = *notification lane* (`.notif-lane`).** Apa pun yang
  muncul tanpa diminta.

**Jangan pernah menambatkan elemen baru langsung ke pojok dengan `fixed bottom-* right-*`.**
Notifikasi baru cukup dijadikan anak `.notif-lane` di `layouts/app.blade.php` — ia akan
menumpuk otomatis lewat flex, tanpa koordinat sendiri.

**Kenapa aturan ini ada.** Dulu toast dan FAB chat sama-sama menambat ke kanan bawah. Toast
selebar 320px pada `right-5` sepenuhnya memuat tombol 56px pada `right-5`, dengan z lebih
tinggi — jadi setiap toast menutupi tombol chat **sekaligus memblokirnya dari klik** selama
6 detik, termasuk badge unread-nya. Kartu undangan room lalu menambal sendiri dengan
`bottom-24` hardcoded, tapi mengukurnya terhadap **toast**, bukan terhadap **FAB** —
sehingga lahir dua konvensi yang saling tidak tahu dan posisi notifikasi jadi tak bisa
diprediksi. Satu lane menutup seluruh kelas bug itu.

**Kenapa atas, bukan kiri bawah.** Kiri bawah juga memisahkan diri dari FAB (dan itu
spesifikasi snackbar Material Design — dipakai Gmail/Drive), tapi terbaca asing: mayoritas
aplikasi menaruh notifikasi di sisi kanan. Pindah ke **atas** mempertahankan sisi kanan yang
diharapkan orang sambil memisahkan diri dari FAB pada **sumbu berbeda** — bukan sekadar
jarak yang bisa termakan perubahan layout. Bonusnya, kasus khusus mobile jadi hilang: sisi
bawah diperebutkan, sisi atas tidak, jadi satu anchor cukup untuk semua ukuran layar.

**`top: 5rem` tak boleh jadi `top: 0`.** Sisi kanan navbar (`h-16` = 4rem) memuat dropdown
akun dan titik badge friend-request. Menutupinya hanya memindahkan bug aslinya dari satu
kontrol ke kontrol lain. Dijaga `ToastStackTest` sebagai perbandingan angka, bukan
pencocokan string.

**Menambat di atas juga menyelesaikan urutan tumpukan.** Toast transien yang ditambahkan
**di bawah** kartu undangan yang persisten tidak menggeser kartu itu. Waktu lane masih
menambat di bawah, tiap toast yang muncul mendorong undangan ke atas lalu menjatuhkannya
lagi saat kedaluwarsa.

**FAB chat tidak boleh dibuat draggable lagi.** Elemen melayang yang bisa dipindah membuat
tak ada elemen `fixed` lain yang bisa punya jarak aman yang benar, dan implementasi drag
sebelumnya mematikan akses keyboard ke chat (tombol hanya punya `@pointerdown`, sehingga
Enter/Space tak sampai ke mana-mana). Alasan lengkapnya ada di kepala
`resources/js/chat-dock.js`; `ChatOverlayTest` menjaganya agar tidak kembali.

### Micro-interaction: reaksi, bukan posisi

Budget "sensasi" ditaruh pada **reaksi** komponen, bukan pada **posisinya**. Posisi adalah
properti yang pengguna andalkan untuk stabil — memasang kejutan di situ melawan memori otot,
dan pada kasus FAB draggable ia menghabiskan akses keyboard sebagai gantinya.

Yang berlaku sekarang di FAB chat:

| Efek | Cara | Kenapa |
|---|---|---|
| Angkat saat hover | `hover:-translate-y-0.5 hover:shadow-2xl` | menegaskan ia bisa diklik |
| Mengecil saat ditekan | `active:scale-95` | mengganti isyarat taktil `active:cursor-grabbing` |
| Ikon miring saat drawer terbuka | `:class="open ? 'rotate-12' : ''"` | status, tanpa ikon kedua |
| Badge "pop" saat unread berubah | `wire:key` + `@keyframes chat-badge-pop` | menarik mata ke informasi baru |

Dua aturan yang mengikat:

1. **Satu kali jalan, jangan berulang.** Badge yang berdenyut terus berhenti terbaca sebagai
   "baru" dalam hitungan detik dan berubah jadi kebisingan visual di tiap halaman.
2. **Pemicunya sinyal yang benar, bukan yang mudah.** Badge di-key dengan jumlah unread dari
   server, jadi pesanmu sendiri dan percakapan yang sedang dibuka sudah terkecualikan oleh
   query yang sama yang menggambar angkanya. Mendengarkan event pesan mentah akan menyala
   pada kejadian yang keliru.

**Tak perlu guard `prefers-reduced-motion` per animasi** — blok global di akhir `app.css`
sudah meratakan seluruh `animation` dan `transition` sekaligus.

## Menambah token / tema

- **Token semantic baru:** tambah `--color-x` di `app.css` (`:root`) lalu daftarkan di
  `tailwind.config.js` (`x: token('--color-x')`).
- **Tema baru** (mis. Deep Blue / Midnight / Carbon): tambah blok
  `:root[data-theme="..."] { --color-...: ...; }` di `app.css`, set `data-theme` di `<html>`.

## Catatan

- Token lama `typing-*` (cyan) **deprecated** — masih dipakai view existing, akan dimigrasi
  per-halaman. Jangan pakai untuk kode baru.
- Build memakai **Tailwind v3** (`tailwind.config.js` + `@tailwind` di `app.css`).
