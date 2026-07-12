# Fitur 4 — Multiplayer Race (Real-time)

**Komponen:** [`App\Livewire\MultiplayerLobby`](../../app/Livewire/MultiplayerLobby.php)
(Volt route `multiplayer-lobby`)
**Model:** [`Room`](../../app/Models/Room.php), [`RoomMember`](../../app/Models/RoomMember.php)
**Events:** `RoomUpdated`, `RaceProgressUpdated`, `SuddenDeathTriggered`
**Route:** `/multiplayer`

---

## 1. Apa Ini

Balapan mengetik **real-time** untuk hingga **5 pemain** dalam satu room. Alur:
buat/gabung room (kode 6 karakter) → semua siap → host mulai → countdown 3-2-1 → balapan →
sudden death → hasil. Progres tiap pemain (maskot yang bergerak) tampil live ke semua peserta.

## 2. State Machine Room

```
waiting  ──startRace()──►  racing  ──semua finish / sudden death──►  finished
   ▲                                                                     │
   └──────────────────────── playAgain() ────────────────────────────────┘
```

`step` di komponen (`choose` → `waiting` → `racing`) mengikuti `room.status`, disinkronkan lewat
event `RoomUpdated`.

## 3. Keputusan Desain & Justifikasi

### 3.1 Dua saluran broadcast berbeda: gerakan vs lifecycle

| Event | Isi | Kenapa dipisah |
|-------|-----|----------------|
| `RaceProgressUpdated` | posisi/WPM/akurasi maskot | payload ringan langsung ke Alpine store, **tanpa re-render Livewire** — gerakan maskot harus mulus |
| `RoomUpdated` | perubahan status/badge/modal | memicu **re-render Livewire** (perubahan struktural yang butuh HTML baru) |

**Justifikasi:** memisahkan "gerakan sering & ringan" dari "perubahan struktur jarang" mencegah
Livewire me-*morph* DOM tiap kali maskot bergeser (yang akan berat & bikin patah-patah).

### 3.2 Net WPM otoritatif dihitung server, `$liveWpm` client diabaikan

```php
// $liveWpm dari client SENGAJA tidak dipakai untuk angka resmi.
$correctChars = (int) round(($progressPercent / 100) * $textLength);
$wpmCheck = app(AntiCheatService::class)->check($correctChars, $correctChars, $durationSeconds);
```

**Justifikasi:** menyelaraskan multiplayer dengan mode solo — WPM tak bisa dipompa dengan ketik
ngasal. Progres hanya naik dari karakter benar, jadi angkanya otomatis "net". `$liveWpm` tetap
diterima di signature hanya demi kompatibilitas payload client lama. Lihat
[anti-cheat-wpm.md](anti-cheat-wpm.md) dan [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).

### 3.3 Countdown sinkron pakai durasi RELATIF, bukan jam server absolut

```php
// getRaceStartsInMsProperty(): sisa milidetik menuju start, dihitung SERVER saat render.
return (int) round((float) now()->diffInMilliseconds($room->race_starts_at, false));
```

**Justifikasi (penting & non-obvious):** membandingkan jam server absolut dengan `Date.now()`
klien akan **menghitung latensi jaringan sebagai selisih jam**, dan `toIso8601String()` memotong
milidetik (galat hingga 1 detik). Dengan mengirim **durasi relatif** ("hitung mundur sekian ms
sejak halaman ini diterima"), jam & zona waktu klien tak lagi relevan — semua klien countdown-nya
sinkron tanpa peduli seberapa akurat jam mereka.

### 3.4 Sudden Death (15 detik) setelah pemain pertama finish

Saat pemain pertama menyentuh 100%, `countdown_started_at` di-set dan `SuddenDeathTriggered`
disiarkan dengan **timestamp akhir yang sama** ke semua klien.

**Justifikasi:** race tak boleh menggantung menunggu pemain lambat/AFK selamanya. Sudden death
memberi jendela adil (15 detik) bagi yang tersisa untuk menyelesaikan, lalu race ditutup. Timestamp
akhir yang seragam menjaga countdown mundur sinkron di semua layar; server tetap gerbang final
lewat `checkSuddenDeath()` (idempoten meski dipicu beberapa klien).

### 3.5 Urutan menang (`place`) berdasarkan waktu selesai, bukan WPM

```php
->orderBy('finished_time_seconds', 'asc')
->orderBy('progress_percent', 'desc')
->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
```

**Justifikasi:** ini **balapan** — yang menang adalah yang **sampai finish duluan**, bukan yang
WPM-nya tertinggi. Tie-break: progres lebih jauh, lalu yang belum selesai ditaruh di belakang.
Pemain yang menyerah (`giveUp`) atau DNF diberi `finished_time_seconds = 999` sebagai penanda.

### 3.6 EXP diberikan sekali per pemain (`xp_earned` null-guard)

```php
if (is_null($member->xp_earned) && $member->user) { /* addExp lalu set xp_earned */ }
```

**Justifikasi:** `finalizeRace()` bisa terpanggil dari beberapa jalur (fast-path "semua finish",
`giveUp`, `checkSuddenDeath`). Guard `xp_earned IS NULL` membuat pemberian EXP **idempoten** —
aman dari double-award. EXP dihitung dengan rumus yang **sama persis** dengan solo (`User::addExp`).

### 3.7 Ketahanan terhadap host keluar / room hilang

Beberapa lapis penjaga (`roomUpdated()`, `getRoomDataProperty()`, dan penjaga terakhir di
`render()`) mengembalikan pemain ke halaman "choose" kalau room-nya sudah tak ada.

**Justifikasi:** tanpa ini, pemain yang tersisa akan terjebak di `step='racing'` tanpa data →
semua blok view gagal syarat → **halaman kosong**. Ini pertahanan berlapis (*defense in depth*)
terhadap kondisi race yang tak terhindarkan di sistem real-time.

### 3.8 Broadcast dibungkus `SafeBroadcast` & `toOthers()`

- `SafeBroadcast::run(...)` — kegagalan Reverb tak menggagalkan aksi race.
- `->toOthers()` pada `startRace()` — host sudah masuk `racing` secara lokal; tanpa `toOthers()`
  host menerima `RoomUpdated`-nya sendiri → re-render di tengah countdown → arena di-morph →
  countdown Alpine mulai ulang dari awal.

**Justifikasi:** kedua hal ini menutup bug real-time yang halus namun terasa jelas oleh pemain
(countdown yang "melompat" atau race yang gagal karena WebSocket sesaat down).

## 4. Batasan Saat Ini

- Teks race berbahasa campuran (kalimat default + kata Indonesia), belum multi-bahasa penuh
  seperti mode solo.
- WPM/place tersimpan di `room_members` tidak melalui jalur PB/leaderboard global — race adalah
  event sosial, bukan sumber rekor pribadi. Lihat diskusi di
  [`../wpm-accuracy-integrity.md`](../wpm-accuracy-integrity.md).
