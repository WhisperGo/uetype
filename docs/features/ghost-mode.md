# Fitur 3 — Ghost Mode

**Komponen:** [`App\Livewire\GhostPicker`](../../app/Livewire/GhostPicker.php)
**Terintegrasi di:** [`TypingEngine`](../../app/Livewire/TypingEngine.php)
**View:** [`resources/views/livewire/ghost-picker.blade.php`](../../resources/views/livewire/ghost-picker.blade.php)

---

## 1. Apa Ini

Mode di mana pemain "balapan" melawan **rekor** (bukan pemain live): rekor diri sendiri,
teman, atau entri leaderboard. Sebuah maskot "ghost" bergerak di sepanjang teks sesuai WPM
target, dan di akhir sesi pemain diberi tahu menang/kalah beserta selisih karakter.

**Hanya berlaku untuk mode Time & Words** (Survival dikecualikan).

## 2. Cara Kerja

1. Pemain membuka picker dan memilih lawan: **own** (rekor sendiri), **friend**, atau
   **leaderboard**.
2. `selectOpponent($type, $refId)` menurunkan **WPM target dari DB** lalu mengirim event
   browser `ghost-selected` dengan WPM & label.
3. Alpine menggerakkan maskot ghost berdasarkan WPM itu selama pemain mengetik.
4. Di akhir sesi, `TypingEngine::saveResult()` menerima `$ghostWpm`, `$ghostLabel`,
   `$ghostCharsAtFinish` dan menyusun hasil perbandingan (menang/kalah + selisih karakter).

## 3. Keputusan Desain & Justifikasi

### 3.1 WPM lawan SELALU diturunkan ulang dari DB, client hanya kirim identifier

```php
// Trust boundary: client hanya mengirim identifier (friendship_id/user_id),
// tak pernah WPM langsung.
'leaderboard' => TypingResult::where('user_id', $refId)
    ->where('mode', $this->mainMode)->where('mode_config', $this->subMode)->max('net_wpm'),
```

**Justifikasi:** kalau client boleh menetapkan WPM ghost, pemain bisa menaruh ghost 1 WPM dan
"menang" tanpa usaha, lalu (kalau ini dipakai untuk apa pun) menyalahgunakannya. Dengan
menurunkan WPM dari DB berdasarkan identifier yang divalidasi kepemilikannya, ghost selalu
merepresentasikan rekor nyata.

### 3.2 Validasi kepemilikan untuk tipe `friend`

`selectOpponent('friend', $refId)` memverifikasi bahwa `friendship_id` benar-benar milik user
yang sedang login **dan** berstatus `Accepted` sebelum mengambil WPM teman.

**Justifikasi:** mencegah enumerasi — user tak bisa menebak `friendship_id` orang lain untuk
mengintip WPM sembarang user yang bukan temannya.

### 3.3 Ghost hanya sah untuk Time/Words — di-*enforce* di banyak lapis

Cek `isGhostEligibleMode()` muncul di `GhostPicker`, di `TypingEngine::onGhostSelected()`,
di `saveResult()`, dan saat pindah mode `setMode()` memaksa `ghostActive = false`.

**Justifikasi:** ini **invariant server**, bukan sekadar event UI. Survival tak punya "target
WPM" yang bermakna (metriknya durasi). Meng-*enforce* di setiap titik masuk memastikan tak ada
jalur yang menyelipkan ghost ke mode yang salah, apa pun yang dikirim klien.

### 3.4 Hasil ghost bersifat efemeral (session-only), tidak ditulis ke DB

```php
$ghostResult = [
    'playerWon' => $correctChars > $ghostCharsAtFinish,
    'charDelta' => $correctChars - $ghostCharsAtFinish,
];
// disimpan ke session, bukan kolom DB
```

**Justifikasi:** ghost adalah *lapisan tampilan* di atas sesi biasa. Attempt yang mendasari
tetap tersimpan sebagai baris `time`/`words` normal (dengan EXP & potensi PB). Tidak menulis
data ghost ke DB menjaga skema tetap sederhana dan menghindari statistik menyesatkan (mis.
slice "ghost" 0% di distribusi mode — lihat [stats-achievements.md](stats-achievements.md)).

### 3.5 Deep-link ghost dari leaderboard (`?ghost=&mode=&config=`)

`TypingEngine::resolveGhostDeepLink()` memproses URL dari baris leaderboard, memakai **pola
yang sama** dengan `GhostPicker` (turunkan WPM dari DB), dan mengabaikan param liar (fail-safe).

**Justifikasi:** memungkinkan "tantang rekor ini" langsung dari leaderboard tanpa membuka
picker, sambil tetap menjaga trust boundary yang sama.

## 4. Komponen Terpisah — Kenapa?

`GhostPicker` sengaja **dipisah** dari `TypingEngine` dan dikoordinasikan lewat browser event
(`ghost-selected`/`ghost-cleared`).

**Justifikasi:** memisahkan tanggung jawab — `TypingEngine` fokus pada mesin ketik, `GhostPicker`
fokus pada pemilihan lawan (query teman/leaderboard yang cukup berat). Komunikasi lewat event
menjaga keduanya *loosely coupled*.
