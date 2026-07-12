# Fitur 5 — Level & EXP

**Logika utama:** [`App\Models\User`](../../app/Models/User.php) —
`addExp()`, `levelForXp()`, `xpToReachLevel()`, `levelData()`

---

## 1. Apa Ini

Sistem progresi pemain. Setiap sesi mengetik yang valid memberi **EXP** yang terakumulasi ke
`total_xp`. Level pemain **diturunkan** dari `total_xp` (tidak ada kolom `level` tersimpan).

## 2. Rumus

### 2.1 EXP per sesi (`addExp`)

```php
$accuracyMultiplier = 0.5 + 0.5 * (accuracy / 100);   // 0.5x .. 1.0x
$xpEarned = round(correctChars * 0.1 * $accuracyMultiplier);
```

- Basis: **jumlah karakter benar** × 0.1.
- Bonus akurasi: akurasi 100% = EXP penuh, akurasi 0% = setengah.

### 2.2 Kurva level (progresif)

```
EXP untuk naik dari level L ke L+1     = BASE × L           (BASE = 100)
EXP kumulatif untuk MENCAPAI level N   = BASE × N(N-1)/2
level dari xp (bentuk tertutup)        = floor((1 + √(1 + 8·xp/BASE)) / 2)
```

## 3. Keputusan Desain & Justifikasi

### 3.1 EXP berbasis VOLUME (karakter), bukan WPM

```php
// Basis volume (jumlah karakter benar) — SENGAJA bukan berbasis WPM: menghargai
// usaha/latihan, bukan bakat, dan tidak menghukum pengetik lambat.
```

**Justifikasi:** kalau EXP berbasis WPM, pemain cepat melesat jauh dan pemula merasa mustahil
mengejar — progresi jadi hukuman bagi yang lambat. Berbasis volume berarti **siapa yang berlatih
lebih banyak, naik lebih cepat**, terlepas dari bakat. Ini sejalan dengan tujuan game: latihan.

### 3.2 Bonus akurasi 0.5x–1.0x, bukan gerbang keras

**Justifikasi:** memberi *insentif* akurasi tanpa menghukum total. Pemain yang cepat-tapi-ceroboh
tetap dapat EXP (setengah), jadi tidak frustasi, tapi jelas kalah efisien dari yang akurat. Rumus
pengali ini **identik** dengan yang dipakai `ClanWarScorer`, menjaga konsistensi lintas fitur.

### 3.3 Level diturunkan dari `total_xp`, tidak disimpan

```php
// total_xp adalah SATU-SATUNYA sumber kebenaran; level selalu diturunkan dari sini.
```

**Justifikasi:** menghindari data ganda yang bisa tidak sinkron (mis. `total_xp` naik tapi
`level` lupa di-update). Dengan **bentuk tertutup** (rumus kuadrat terbalik), level dihitung
O(1) tanpa loop — cukup satu ekspresi matematis, tak perlu iterasi menaikkan level satu per satu.

### 3.4 Kurva progresif (makin tinggi makin lama)

**Justifikasi:** `BASE × L` berarti tiap level butuh lebih banyak EXP dari sebelumnya. Ini pola
progresi standar game: level awal cepat (memberi rasa kemajuan ke pemula), level tinggi jadi
pencapaian bermakna. Angka `BASE=100` boleh di-tuning; **polanya** yang dikunci.

### 3.5 `addExp()` sebagai satu sumber kebenaran

**Justifikasi:** dipanggil dari mode solo (`TypingEngine`), multiplayer (`MultiplayerLobby`), dan
rumus pengalinya dipakai ulang di Clan War. Memusatkan rumus di satu method menjamin **semua mode
menghitung EXP dengan cara yang sama** — tak ada dua definisi EXP yang bisa menyimpang.

## 4. Penyajian (`levelData()`)

Mengembalikan `{ level, total_xp, progress, needed, next_level }` — satu bentuk data yang dipakai
navigation bar, profil, halaman hasil, dan panel hasil multiplayer. `progress`/`needed` dipakai
untuk bar EXP ("120 / 300 menuju level berikutnya").
