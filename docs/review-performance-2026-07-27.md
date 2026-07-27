# Review Performa — UeType (Refresh & Perluasan)

**Tanggal:** 2026-07-27
**Menggantikan/melanjutkan:** [`review-performance.md`](review-performance.md) (2026-07-22) — dokumen itu
dipertahankan sebagai **arsip**; dokumen ini memverifikasi ulang temuannya terhadap kode terkini
dan menambah kasus serupa yang baru ditemukan.
**Ruang lingkup:** Frontend (typing engine solo, survival, ghost, multiplayer race, bundling)
**dan** sisi backend/server (query, biaya render Livewire, biaya per-request).
**Sifat dokumen:** Review saja. **Tidak ada kode yang diubah.** Setiap temuan berisi *lokasi*,
*penyebab*, *dampak*, *cara verifikasi*, dan *arah perbaikan*.

---

## Ringkasan Eksekutif

Dokumen 2026-07-22 **belum usang**: dari 8 temuan, **7 masih ada persis** dan hanya 1 yang
sebagian beres. Jadi hampir seluruh akar masalah lag di Linux + "timer freeze" Survival belum
tersentuh.

Perluasan ke seluruh codebase menghasilkan dua kesimpulan penting:

1. **Masalah performa didominasi frontend** — biaya paint/reaktivitas per-keystroke (Temuan 1),
   ukuran bundle (Temuan 4 + F-2), dan biaya render server dari markup per-karakter (F-3/F-4).
2. **Backend justru sudah rapi.** Hotspot yang biasanya bermasalah (Stats, leaderboard, daftar
   Friends/Clans) **sudah** dioptimasi: query agregat satu tembakan, `joinSub`, eager-loading,
   dan batching — beberapa bahkan punya komentar eksplisit "dulu N+1, kini 1 query". **Tidak
   ditemukan N+1.** Ini ditegaskan agar tim tidak salah mengejar optimasi DB padahal biaya
   nyatanya ada di render/paint klien.

| # | Temuan | Severity | Status vs 2026-07-22 | File utama |
|---|--------|----------|----------------------|------------|
| 1 | `:class` reaktif per-karakter (O(n)/keystroke) | 🔴 Tinggi | **Masih ada** | `typing-engine.blade.php` |
| 2 | `dt` drain Survival tak di-clamp | 🔴 Tinggi | **Masih ada** | `typing-game.js` |
| 3 | Refill per-char vs drain per-detik | 🟠 Sedang | **Masih ada** (desain) | `typing-game.js` |
| 4 | Chart.js di bundle utama | 🟠 Sedang | **Masih ada** | `app.js` / `vite.config.js` |
| 5 | `wpmHistory`/`rawHistory` unbounded + spread `Math.max` | 🟡 Rendah | **Sebagian beres** | `typing-game.js` |
| 6 | 16 sel stamina reaktif | 🟡 Rendah | **Masih ada** | `typing-engine.blade.php` |
| 7 | `will-change:transform` permanen (caret) | 🟡 Rendah | **Masih ada** | `typing-engine.blade.php` |
| 8 | Overlay radial-gradient fullscreen selalu di DOM | 🟡 Rendah | **Masih ada** | `typing-engine.blade.php` |
| **F-1** | **Layout thrashing di ghost rAF loop** | 🟠 Sedang | **BARU** | `typing-game.js` |
| **F-2** | **Tidak ada code-splitting (bundle 359 KB ke semua halaman)** | 🟠 Sedang | **BARU** | `app.js` / `vite.config.js` |
| **F-3** | **`wire:key` memuat seluruh paragraf per kata** | 🟠 Sedang | **BARU** | `typing-engine.blade.php` |
| **F-4** | **Render span per-karakter server-side (kembar #1)** | 🟠 Sedang | **BARU** | `typing-engine.blade.php` |
| **B-1** | **Query badge di layout tiap page-load** | 🟡 Rendah | **BARU** | `navigation.blade.php` |
| **B-2** | **Biaya re-render/morph Livewire komponen typing** | 🟡 Rendah | **BARU** | `TypingEngine` + view |

Bundle terbuild saat ini: **`public/build/assets/app-*.js` ≈ 359 KB** (satu file, tanpa
code-split).

---

## Status Implementasi (diperbarui 2026-07-27)

Seluruh temuan *actionable* di dokumen ini **sudah diimplementasikan** pada tanggal yang sama,
diverifikasi dengan **887 test PEST lulus** dan build Vite bersih di tiap tahap. Ringkasan:

| Temuan | Status | Perubahan | File utama |
|---|---|---|---|
| 1 | ✅ Implemented | `:class` span karakter hanya bergantung `inputResults[i]`, bukan `currentIndex` → re-eval per keystroke **O(n) → O(1)** (provably equivalent) | `typing-engine.blade.php` |
| 2 | ✅ Implemented | `dt` di-clamp `Math.min(…, 0.25)` di `staminaTick` → hilangkan game-over mendadak yang tampak seperti "freeze" | `typing-game.js` |
| 3 | ✅ Implemented | **Burst shield**: tiap karakter benar menabung shield-time (cap 1.4s), drain ×0.35 selama shield aktif → mengetik cepat konsisten lebih aman; ramp akselerasi tetap menjamin game-over. *Perlu playtest tuning + keputusan komparabilitas leaderboard.* | `typing-game.js` |
| 4 / F-2 | ✅ Implemented | Chart.js lazy via `window.ensureChart()` (dynamic import → chunk Vite terpisah) → **bundle utama 359 KB → 153 KB** (gzip 118→48 KB) | `app.js`, `stats.blade.php`, `typing-result.blade.php`, `NoExternalCdnTest.php` |
| 5 | ⏸️ Tidak diubah | Dampak kecil; bagian spread `Math.max` sudah hilang saat sparkline dihapus. Pertumbuhan array dibiarkan (payload masih wajar) | — |
| 6 | ✅ Implemented | 16 sel `x-for` reaktif → **satu** elemen fill (width snap 16-langkah + warna) dengan overlay gradient segmen → 1 binding reaktif, bukan 16 | `typing-engine.blade.php` |
| 7 | ✅ Implemented | `[will-change:transform]` caret hanya saat `isTyping` (bukan permanen) | `typing-engine.blade.php` |
| 8 | ✅ Implemented | Overlay radial-gradient survival diberi `x-show="currentMain === 'survival'"` → keluar dari paint tree di mode time/words | `typing-engine.blade.php` |
| F-1 | ✅ Implemented | Cache posisi karakter (`buildCharPositionCache`/`cachedCharPosition`); ghost rAF loop baca dari memori, bukan reflow per-frame. Invalidate saat reset & resize | `typing-game.js` |
| F-3 | ✅ Implemented | `wire:key` kata pakai `crc32($textToType)`, bukan seluruh paragraf per kata → HTML + morph jauh lebih ramping | `typing-engine.blade.php` |
| F-4 | ✅ Implemented | Ikut selesai via Temuan 1 (biaya reaktif klien). Span per-karakter tetap ada (dibutuhkan untuk geometri caret/ghost) | `typing-engine.blade.php` |
| B-1 | ⏸️ Tidak diubah | Dampak rendah (satu `->count()` ter-index di layout) | — |
| B-2 | ✅ Ikut terpangkas | Biaya morph/re-render typing berkurang otomatis lewat F-3 + F-4 | — |
| B-3 | ⏸️ Tidak diubah | Heartbeat presence sudah hemat by-design | — |

**Catatan verifikasi yang tersisa (manual, karena repo tak menjalankan test JS):**
- **Temuan 3** — playtest keseimbangan shield (konstanta `SHIELD_PER_CHAR_MS` / `SHIELD_MAX_MS` /
  `SHIELD_DRAIN_FACTOR` di `typing-game.js`) + keputusan reset/segmentasi rekor Survival.
- **Temuan 6/7/8** — cek visual bar stamina & efek paint di mode Survival di browser.

---

# BAGIAN A — Verifikasi 8 Temuan Lama

Lokasi baris sudah bergeser sejak 2026-07-22 (banyak commit); baris di bawah dikonfirmasi ulang
terhadap kode 2026-07-27.

## 🔴 Temuan 1 — `:class` reaktif per-karakter → **MASIH ADA**

**Lokasi kini:** [`typing-engine.blade.php:413-419`](../resources/views/livewire/typing-engine.blade.php#L413-L419)

Persis seperti laporan lama. Tiap karakter tetap dirender `<span>` dengan 4 ekspresi `:class`
yang membaca `currentIndex` + `inputResults[...]`:

```blade
<span id="char-{{ $charPointer }}" class="char-element relative inline-block"
    :class="{
        'text-foreground': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === true,
        'text-danger':     {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === false,
        'text-muted':      {{ $charPointer }} >= currentIndex || (...'skipped'),
        'border-b-2 border-danger': ...'skipped'
    }">
```

`currentIndex` berubah **setiap keystroke** → Alpine mengevaluasi ulang `:class` untuk **seluruh**
span (ratusan–ribuan di Words-100 / Time-120) tiap huruf → Recalculate Style + Layout + Paint
blok teks penuh per keystroke.

**Dampak:** akar lag lintas-OS; jauh lebih terasa di **Linux** (GPU accel sering off → paint tak
"disembunyikan"). Makin panjang teks makin parah. Memperbesar durasi frame → memperparah Temuan 2.

**Verifikasi:** DevTools → Performance saat mengetik; kalau **Recalculate Style / Paint** dominan
(bukan Scripting), terkonfirmasi. Bandingkan Words-10 vs Words-100.

**Arah perbaikan:** hentikan reaktivitas yang bergantung `currentIndex` di semua span. Perbarui
class hanya pada span yang benar-benar berubah, atau pisahkan "kata aktif" dari "kata beku".
**Prior art ada di repo sendiri** — lihat **F-4** (view multiplayer race sudah per-kata).

## 🔴 Temuan 2 — `dt` drain Survival tak di-clamp → **MASIH ADA**

**Lokasi kini:** [`typing-game.js:397-417`](../resources/js/typing-game.js#L397-L417) (dulu ~349-365);
loop dijadwalkan `setInterval(..., 100)` di [`typing-game.js:794`](../resources/js/typing-game.js#L794).

```js
staminaTick() {
    if (this.currentMain !== 'survival' || this.isFinished || !this.startTime) return;
    const now = Date.now();
    const dt = this.lastTickTime ? (now - this.lastTickTime) / 1000 : 0; // ← tetap TIDAK dibatasi
    this.lastTickTime = now;
    if (dt <= 0) return;
    ...
    this.stamina = Math.max(0, this.stamina - drainPerSec * dt);
    if (this.stamina <= 0) this.survivalGameOver();
}
```

**Penyebab & dampak:** `setInterval` tidak menjamin 100ms. Saat main thread sibuk (paint berat
Temuan 1, GC, tab throttle), tick molor; `dt` melonjak (mis. 0.5) → drain satu tick berlipat →
stamina terjun ke 0 → `survivalGameOver()` → `finish()` → `clearInterval(timerInterval)`
([`typing-game.js:962`](../resources/js/typing-game.js#L962)) → **angka timer berhenti = terlihat
"freeze"**. Paling sering saat **burst tinggi** (beban paint puncak) dan **di Linux**.

**Verifikasi:** pantau `isFinished` jadi `true` tepat saat "freeze"; Performance record → jarak
antar `staminaTick` melar jauh dari 100ms.

**Arah perbaikan:** `dt = Math.min(dt, 0.25)`. Perbaikan kecil, langsung menghapus game-over
mendadak tanpa mengubah keseimbangan drain normal. Idealnya digabung dengan Temuan 1.

## 🟠 Temuan 3 — Refill per-char vs drain per-detik → **MASIH ADA** (keputusan desain)

**Lokasi kini:** refill [`typing-game.js:390-394`](../resources/js/typing-game.js#L390-L394),
drain [`typing-game.js:397-417`](../resources/js/typing-game.js#L397-L417).

Drain berbasis **waktu** (`drainPerSec * dt`), refill berbasis **jumlah karakter** (`+refill` per
huruf benar). Burst-lalu-jeda → refill berhenti, drain jalan → "ngebut lalu jeda" bisa menjatuhkan
stamina, sehingga mengetik cepat tak selalu lebih aman. Ini memperkuat gejala Temuan 2.

**Arah perbaikan (opsional, milik produk):** normalisasi refill terhadap waktu, atau kurangi
`drainPerSec` sebagai fungsi WPM live. **Keputusan gameplay** — jangan diubah otomatis.

## 🟠 Temuan 4 — Chart.js di bundle utama → **MASIH ADA**

**Lokasi kini:** [`app.js:3`](../resources/js/app.js#L3) + [`app.js:13`](../resources/js/app.js#L13);
[`vite.config.js`](../vite.config.js) tanpa code-split.

```js
import Chart from 'chart.js/auto';
window.Chart = Chart;
```

`chart.js/auto` menarik seluruh Chart.js ke `app.js`. Chart hanya dipakai di `stats.blade.php` &
`typing-result.blade.php`, tapi setiap halaman (termasuk `/typing`) mengunduh + parse + compile-nya.
Lihat **F-2** untuk versi umum masalah ini (bukan cuma Chart.js).

**Arah perbaikan:** dynamic import Chart.js hanya di halaman yang butuh; impor modul Chart yang
dipakai saja (bukan `chart.js/auto`).

## 🟡 Temuan 5 — `wpmHistory`/`rawHistory` unbounded + spread `Math.max` → **SEBAGIAN BERES**

**Perubahan sejak 2026-07-22:** **live WPM sparkline sudah dihapus** (lihat catatan di header
[`typing-game.js:14-18`](../resources/js/typing-game.js#L14-L18)). Konsekuensinya `sparklinePoints()`
beserta `Math.max(...h)`/`Math.min(...h)` **hilang** — bagian anti-pola spread argumen **teratasi**.

**Sisa yang masih ada (dampak kecil):** `wpmHistory`/`rawHistory` tetap di-`push` tiap detik tanpa
batas ([`typing-game.js:814-818`](../resources/js/typing-game.js#L814-L818)) dan dikirim penuh ke
server di `saveResult` ([`typing-game.js:999-1000`](../resources/js/typing-game.js#L999-L1000)).
Untuk Time-120 hanya ~120 entri — kecil, tapi payload tumbuh linear dengan durasi.

**Arah perbaikan:** batasi/downsample history untuk sesi panjang bila payload jadi perhatian.

## 🟡 Temuan 6 — 16 sel stamina reaktif → **MASIH ADA**

**Lokasi kini:** [`typing-engine.blade.php:282-290`](../resources/views/livewire/typing-engine.blade.php#L282-L290).
16 sel `x-for`, tiap sel `:style` bergantung `staminaPct` (berubah tiap keystroke refill + tiap tick
~100ms) → 16 evaluasi style + potensi paint per perubahan. `syncStaminaPct()`
([`typing-game.js:420-425`](../resources/js/typing-game.js#L420-L425)) sudah membatasi update hanya
saat persen (bulat) berubah — meredam sebagian, tapi burst tetap sering mengubahnya.

**Arah perbaikan:** ganti pewarnaan per-sel dengan satu bar (width/scaleX), atau warna via class
yang berubah hanya di ambang (25/50).

## 🟡 Temuan 7 — `will-change:transform` permanen (caret) → **MASIH ADA**

**Lokasi kini:** [`typing-engine.blade.php:366`](../resources/views/livewire/typing-engine.blade.php#L366).
`will-change` mempromosikan caret ke layer compositing **permanen**. Di Linux tanpa GPU accel,
promosi layer permanen bisa menambah overhead memori/compositing. Efek kecil, searah gejala Linux.

**Arah perbaikan:** terapkan `will-change` hanya saat `isTyping`, atau hapus.

## 🟡 Temuan 8 — Overlay radial-gradient fullscreen selalu di DOM → **MASIH ADA**

**Lokasi kini:** [`typing-engine.blade.php:32-41`](../resources/views/livewire/typing-engine.blade.php#L32-L41).
Elemen fullscreen dengan radial-gradient yang opacity-nya di-recompute reaktif (`staminaPct`,
`drainFlash`). Selalu di DOM (hanya `x-cloak`, tanpa `x-if`/`x-show`). Radial-gradient fullscreen =
paint mahal, terutama software rendering, dan justru saat stamina rendah (fase paling butuh
responsif).

**Arah perbaikan:** `display:none`/`x-if` saat tak aktif (bukan sekadar opacity 0), atau efek yang
lebih murah.

---

# BAGIAN B — Temuan Serupa BARU (Frontend)

## 🟠 F-1 — Layout thrashing di ghost rAF loop

**Lokasi:** [`typing-game.js:490-529`](../resources/js/typing-game.js#L490-L529) (`updateGhostPosition`)
memanggil [`getCharPosition`](../resources/js/typing-game.js#L470-L485) yang membaca
`offsetLeft`/`offsetTop`.

**Penyebab:** saat ghost aktif, `startGhostAnimationLoop()`
([`typing-game.js:532-545`](../resources/js/typing-game.js#L532-L545)) menjalankan
`updateGhostPosition()` **tiap frame (~60fps)** via `requestAnimationFrame`. Tiap frame memanggil
`getCharPosition()` hingga **3×** (`flooredIndex`, `flooredIndex+1`, dan cabang selesai), dan setiap
panggilan membaca `offsetLeft`/`offsetTop` — properti yang **memaksa synchronous layout (reflow)**.
Bila digabung dengan re-style per-karakter Temuan 1 (yang meng-invalidate layout tiap keystroke),
pembacaan offset ini terjadi tepat setelah layout dikotori → **layout thrashing** klasik.

**Dampak:** biaya per-frame tambahan **hanya saat ghost aktif** (deep-link `?ghost=...` dari
leaderboard/teman). Menumpuk di atas Temuan 1; di Linux/perangkat lemah paling terasa.

**Verifikasi:** Performance record saat mengetik dengan ghost aktif vs tanpa ghost → bandingkan
waktu "Layout"/"Recalculate Style". Kolom "forced reflow" di DevTools menandai `getCharPosition`.

**Arah perbaikan:** **cache posisi karakter** ke dalam array (di-invalidate hanya saat re-wrap),
lalu interpolasi ghost dari cache tanpa DOM read per frame. Pola snapshot ini **sudah dipakai
race arena** — `_lineOf`/`syncWordScroll` di
[`race-arena.js:722-774`](../resources/js/race-arena.js#L722-L774) mengukur layout **sekali** lalu
membaca dari map. Jadikan itu model.

## 🟠 F-2 — Tidak ada code-splitting sama sekali (generalisasi Temuan 4)

**Lokasi:** [`app.js:1-11`](../resources/js/app.js#L1-L11) (import statis), [`vite.config.js`](../vite.config.js)
(tanpa `manualChunks`/dynamic import).

**Penyebab:** `app.js` meng-import **statis** semuanya ke satu bundle:
- `chart.js/auto` (besar) — hanya untuk stats/result,
- `typing-game.js` (**≈43 KB**) — hanya untuk `/typing`,
- `race-arena.js` (**≈43 KB**) — hanya untuk `/multiplayer`,
- `chat-runtime.js` (**≈13 KB**), toasts, nav-badges, dll.

Hasilnya **seluruh ≈359 KB dikirim ke setiap halaman**. Membuka `/typing` tetap mengunduh +
parse + compile race-arena & Chart.js yang tak dipakai; halaman non-typing memuat typing-game.
Di **server lambat**, ini biaya transfer **dan** parse/compile main-thread di setiap kunjungan.

**Verifikasi:** DevTools → Coverage saat buka `/typing` → lihat porsi `app.js` "unused"; Network →
ukuran `app-*.js` (359 KB).

**Arah perbaikan:** dynamic import per-fitur (Chart.js hanya di stats/result; race-arena di-import
saat komponen arena mount; typing-game saat halaman typing). Atau pecah lewat
`build.rollupOptions.output.manualChunks` di Vite. **Prioritas tinggi** untuk kondisi server lambat.

## 🟠 F-3 — `wire:key` memuat seluruh paragraf per kata

**Lokasi:** [`typing-engine.blade.php:411`](../resources/views/livewire/typing-engine.blade.php#L411).

```blade
<div class="flex" wire:key="word-{{ $loop->index }}-{{ $textToType }}">
```

**Penyebab:** `$textToType` (teks penuh — bisa ~500–600 karakter di Words-100) ditempel ke
`wire:key` **tiap kata**. Untuk Words-100 → ~100 salinan teks penuh muncul di HTML yang
dirender, dan setiap kunci itu ikut dibandingkan saat Livewire mem-*morph* DOM pada tiap
re-render server (mis. `setMode`, `restart`, atau round-trip lain).

**Dampak:** membengkakkan ukuran HTML render **dan** biaya diff morph di sisi server/klien — sejalan
dengan kondisi server lambat. Tujuan `wire:key` (memaksa remount saat teks berganti) bisa dicapai
tanpa mengulang seluruh teks.

**Verifikasi:** View-source `/typing` di Words-100 → cari pengulangan teks penuh pada atribut
`wire:key`. Ukur ukuran payload HTML komponen.

**Arah perbaikan:** kunci ringkas — mis. `word-{{ $loop->index }}-{{ crc32($textToType) }}` atau
`-{{ strlen($textToType) }}`, atau pindahkan pemaksa-remount ke satu `wire:key` di kontainer teks
(bukan per kata).

## 🟠 F-4 — Render span per-karakter server-side (kembar Temuan 1 di sisi Blade)

**Lokasi:** [`typing-engine.blade.php:410-446`](../resources/views/livewire/typing-engine.blade.php#L410-L446).

**Penyebab:** selain biaya reaktif klien (Temuan 1), markup itu sendiri membuat **satu `<span>` per
karakter** dengan 4 ekspresi `:class`. Untuk Words-100 (~600 karakter) itu ~600 elemen yang harus
**dirender server** dan di-morph tiap kali `TypingEngine` re-render (kait ke F-3 dan B-2).

**Prior art perbaikan — di repo sendiri:** view multiplayer race merender **per-kata**, bukan
per-karakter — [`multiplayer-lobby.blade.php:775-810`](../resources/views/livewire/multiplayer-lobby.blade.php#L775-L810):

```blade
<template x-for="(word, wIdx) in words" :key="wIdx">
    <span :data-word-index="wIdx" ...
        :class="{
            'text-active': wIdx < currentWordIndex,
            ... wIdx === currentWordIndex && (hasError || justBlocked),
            'text-muted': wIdx > currentWordIndex
        }" x-text="word"></span>
</template>
```

Di sini `:class` bergantung `currentWordIndex` (berubah **per-kata**, bukan per-keystroke) dan
render per-kata (O(kata), bukan O(karakter)). Inilah arah konkret untuk membenahi Temuan 1 & F-4
sekaligus: pisahkan kata aktif (reaktif, per-karakter) dari kata beku (statis) — mengikuti pola yang
sudah terbukti di arena.

---

# BAGIAN C — Sisi Backend / Server

## Yang SUDAH baik (jangan salah kejar)

Diverifikasi langsung ke kode — hotspot umum ternyata sudah dioptimasi:

- **`Stats`** ([`app/Livewire/Stats.php`](../app/Livewire/Stats.php)): agregat solo via satu
  `selectRaw` (5 angka/1 query, bukan 5 query — [`Stats.php:243-251`](../app/Livewire/Stats.php#L243-L251)),
  agregat multiplayer 1 query ([`Stats.php:160-168`](../app/Livewire/Stats.php#L160-L168)), series
  di-`take(200)` ([`Stats.php:86-88`](../app/Livewire/Stats.php#L86-L88)). **Sudah baik.**
- **Leaderboard** ([`leaderboard.blade.php:93-136`](../resources/views/livewire/leaderboard.blade.php#L93-L136)):
  `joinSub` (best-per-user) + `join users`, satu query untuk top-10 + map relasi. **Sudah baik.**
- **`Friends`** ([`app/Livewire/Friends.php`](../app/Livewire/Friends.php)): eager-load
  `with(['requester','addressee'])`, `ghostConfigsFor()` batched (1 query untuk seluruh daftar
  teman — [`Friends.php:188-216`](../app/Livewire/Friends.php#L188-L216)), `relationMapFor()` batched
  untuk hasil search — **dengan komentar eksplisit bahwa dulu ini N+1 dan sudah diperbaiki**
  ([`Friends.php:261-264`](../app/Livewire/Friends.php#L261-L264)). **Sudah baik.**
- **`Clans`** ([`app/Livewire/Clans.php`](../app/Livewire/Clans.php)): `orderedActiveMembers()`
  eager-load `->with('user')` ([`Clan.php:107-112`](../app/Models/Clan.php#L107-L112)), `browseClans`
  pakai `withCount` + membership batched ([`Clans.php:661-674`](../app/Livewire/Clans.php#L661-L674)).
  `isOnline()` diturunkan dari `last_seen_at` (in-memory, bukan query). **Sudah baik.**

**Kesimpulan: tidak ditemukan N+1.** Beban DB per halaman tergolong ringan dan terbatas.

## 🟡 B-1 — Query badge di layout tiap page-load

**Lokasi:** [`navigation.blade.php:14-18`](../resources/views/layouts/navigation.blade.php#L14-L18).

Untuk user login, tiap render halaman menjalankan `Friendship::where(...)->count()` (badge
permintaan teman pending). Ringan (indexed count) dan wajar untuk baseline saat load, tapi ini biaya
**tetap × setiap page-load**; di server lambat setiap navigasi membayarnya. Karena navigasi banyak
memakai full-load (mis. transisi `/typing`), ini tidak selalu di-*cache* antar halaman.

**Arah perbaikan (opsional):** andalkan badge live dari Alpine/echo (sudah ada `navBadges`) dan
kurangi frekuensi hitung server, atau cache pendek per-request. Dampak rendah — dicatat untuk
kelengkapan.

## 🟡 B-2 — Biaya re-render/morph Livewire komponen typing

**Lokasi:** [`app/Livewire/TypingEngine.php`](../app/Livewire/TypingEngine.php) + viewnya.

Interaksi non-ketik pada halaman typing (`setMode`, `setContentLang`, `restart`, `clearGhost`)
memicu **full server render** komponen `TypingEngine`, termasuk menghasilkan ulang ~600 span
per-karakter (F-4) dengan `wire:key` beruntai teks penuh (F-3), lalu morph di klien. Di server lambat
round-trip ini terasa.

**Arah perbaikan:** kurangi berat markup (F-4), ringkaskan `wire:key` (F-3), dan pastikan aksi yang
sebetulnya bisa klien-saja tidak memicu round-trip. Terkait erat dengan F-3/F-4 — memperbaiki
keduanya otomatis memangkas B-2.

## 🟡 B-3 — Heartbeat presence & volume broadcast (catatan, bukan cacat)

**Lokasi:** [`app.blade.php:90-120`](../resources/views/layouts/app.blade.php#L90-L120).

Heartbeat `POST /heartbeat` tiap **30 detik**, **dijeda saat tab tersembunyi**, dan server hanya
broadcast pada transisi offline→online — **sudah hemat by design**. Dicatat hanya sebagai komponen
beban baseline yang berskala dengan jumlah user aktif (relevan saat menaksir kapasitas server
Reverb + web), bukan bug.

---

# Kaitan Antar-Temuan (mengapa Linux + burst = paling parah)

```
Temuan 1 (paint per-keystroke O(n))  ── F-4 (markup per-karakter, render server)
        │  memperbesar durasi tiap frame                 │  memperbesar HTML + morph
        ▼                                                ▼
Main thread sibuk saat burst tinggi (15+ keystroke/dtk) ─ F-1 (reflow ghost/frame, jika ghost aktif)
        │  setInterval 100ms tertunda
        ▼
Temuan 2 (dt tak di-clamp) → satu tick drain berlebihan
        ▼
stamina → 0 → survivalGameOver → finish() → timer berhenti  ==>  terlihat "TIMER FREEZE"

Beban load awal (semua halaman): F-2 (bundle 359 KB) + Temuan 4 (Chart.js) + B-1/B-2 (round-trip).
```

Linux memperparah seluruh rantai karena paint lebih lambat (GPU accel sering off). Temuan 6/7/8
menambah beban paint Survival.

---

# Rekomendasi Prioritas

1. **Temuan 1 + F-4** — akar lag lintas-OS. Terapkan pola per-kata milik arena (pisahkan kata
   aktif dari kata beku). Dampak terbesar.
2. **Temuan 2** — `dt = Math.min(dt, 0.25)`. Kecil, langsung menghapus "freeze mendadak".
3. **F-2 (+ Temuan 4)** — code-splitting/dynamic import. Menurunkan berat load setiap halaman;
   penting untuk server lambat.
4. **F-3** — ringkaskan `wire:key`; memangkas HTML + morph (dan B-2).
5. **F-1** — cache posisi karakter untuk ghost (pakai pola snapshot arena).
6. **Temuan 3** — keputusan desain refill vs drain (persetujuan produk).
7. **Temuan 6/7/8** — pemolesan paint Survival.
8. **Temuan 5, B-1, B-2, B-3** — kebersihan & baseline, dampak kecil.

---

# Langkah Verifikasi Umum (sebelum menyentuh kode apa pun)

- `chrome://gpu` di mesin Linux yang lag → konfirmasi software rendering.
- DevTools **Performance** saat mengetik (Words-100 & Survival burst; sekali dengan ghost aktif) →
  cek dominasi Paint vs Scripting, jarak antar `staminaTick`, dan "forced reflow" pada
  `getCharPosition`.
- DevTools **Coverage** di `/typing` → konfirmasi Chart.js & race-arena unused.
- View-source `/typing` Words-100 → konfirmasi pengulangan teks penuh di `wire:key`.
- Bandingkan Words-10 vs Words-100 → konfirmasi skala lag terhadap jumlah karakter.

---

*Dokumen ini murni hasil pemeriksaan. Tidak ada kode yang diubah. Setiap perbaikan sebaiknya
dilakukan sebagai langkah terpisah dengan verifikasi before/after. Untuk konteks arsitektur &
fitur, lihat [`PROJECT_OVERVIEW.md`](PROJECT_OVERVIEW.md) dan
[`features/typing-engine.md`](features/typing-engine.md).*
