# Fitur 12 — Multi-bahasa (Konten & UI)

**UI locale:** [`LocaleController`](../../app/Http/Controllers/LocaleController.php),
[`App\Support\Locale`](../../app/Support/Locale.php) — `POST /locale`
**Bahasa konten ketik:** [`App\Support\TypingLanguage`](../../app/Support/TypingLanguage.php)
**File terjemahan:** [`lang/`](../../lang/)

---

## 1. Apa Ini

Dua sumbu bahasa yang **terpisah**:

1. **Bahasa UI** — teks antarmuka (tombol, label, pesan). Diatur `Locale` + file `lang/`.
2. **Bahasa konten ketik** — bahasa kata-kata yang diketik di mesin ketik (Inggris/Indonesia).
   Diatur `TypingLanguage` + wordlist JSON di `database/data/`.

Keduanya mendukung **`en`** dan **`id`** (`en` default).

## 2. Keputusan Desain & Justifikasi

### 2.1 Bahasa UI dan bahasa konten SENGAJA dipisah

**Justifikasi (penting):** seorang pengguna bisa ingin antarmuka berbahasa Indonesia **tapi berlatih
mengetik teks Inggris** (atau sebaliknya). Menyatukan keduanya memaksa pilihan yang salah. Di
`TypingEngine`, keduanya dipulihkan dari session **secara terpisah** — bahkan saat mode dikunci
Clan War (yang mengunci mode/config), bahasa konten tetap bebas diganti karena tak memengaruhi skor.

### 2.2 Whitelist bahasa + fallback ke default (`resolve()`)

```php
public static function resolve(?string $lang): string {
    return self::isSupported($lang) ? $lang : self::DEFAULT;
}
```

**Justifikasi:** kode bahasa berasal dari input pengguna/URL/session. `resolve()` adalah satu titik
yang memetakan nilai liar ke default aman — jadi tak ada kode bahasa tak dikenal yang bisa membuat
`wordlistPath()` menunjuk file yang tak ada. Pola yang sama dengan whitelist mode di
[typing-engine.md](typing-engine.md#32-whitelist-mode-di-server-allowed_submodes--normalizemode).

### 2.3 Wordlist sebagai file JSON per bahasa

```php
private const WORDLIST_FILES = ['en' => 'english.json', 'id' => 'indonesian.json'];
```

**Justifikasi:** memisahkan kata per bahasa ke file terpisah membuat penambahan bahasa baru cukup
dengan menambah file JSON + satu entri di peta — tak perlu ubah logika perakitan teks. Konten
(kata-kata) dipisah dari kode.

### 2.4 Preferensi locale disimpan di session DAN preferensi user

```php
$request->session()->put('locale', $locale);
$request->user()?->setPreference('locale', $locale);
```

**Justifikasi:** session membuat pilihan langsung berlaku untuk request berikutnya (termasuk tamu).
`setPreference` menyimpan pilihan **user login** secara persisten, jadi bahasa tetap sama saat login
lagi di perangkat lain. Tamu cukup session; user login dapat persistensi — keduanya terpenuhi tanpa
memaksa login.

## 3. Menambah Bahasa Baru

1. **UI**: tambah folder/file di `lang/<kode>/` dan daftarkan di `Locale::SUPPORTED`.
2. **Konten ketik**: tambah `database/data/<bahasa>.json` (struktur `{ "words": [...] }`) dan entri
   di `TypingLanguage::WORDLIST_FILES` + `SUPPORTED`.

Tak ada perubahan pada logika mesin ketik yang diperlukan — desain ini memang dibuat *extensible*.
