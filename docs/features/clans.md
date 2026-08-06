# Fitur 9 — Clan

**Komponen:** [`App\Livewire\Clans`](../../app/Livewire/Clans.php) — route `/clans`,
[`ClanShow`](../../app/Livewire/ClanShow.php) — `/clans/{clan}`,
[`ClanLeaderboard`](../../app/Livewire/ClanLeaderboard.php) — `/clan-leaderboard`
**Model:** [`Clan`](../../app/Models/Clan.php), [`ClanMember`](../../app/Models/ClanMember.php)
**Enum:** `ClanRole` (Leader/CoLeader/Member), `ClanMemberStatus` (Pending/Active)

---

## 1. Apa Ini

Grup pemain. Fitur: **buat clan** (nama, tag, emblem, warna, deskripsi), **cari & gabung**
(kirim permintaan), **approve/reject** permintaan, **kick** member, **keluar** clan,
**promote/demote** co-leader, **transfer** kepemimpinan, **ubah identitas** clan, dan
**bubarkan** clan.
Leaderboard clan mengurutkan berdasarkan `power` (dari Clan War).

Batas: **20 member aktif** per clan (`MAX_MEMBERS`).

## 2. Keputusan Desain & Justifikasi

### 2.1 Keanggotaan lewat pivot `clan_members`, bukan kolom di `users`

`User::clan` adalah **accessor** yang menurunkan clan dari baris `clan_members` berstatus Active.

```php
// Tak ada kolom clan_id di users -- keanggotaan diturunkan lewat pivot clan_members.
public function getClanAttribute(): ?Clan { return $this->clanMembership()?->clan; }
```

**Justifikasi:** pivot mendukung **status** (pending vs active) dan **role** (leader/member) dalam
satu tempat, dan memungkinkan seorang user punya banyak baris (mis. permintaan pending ke beberapa
clan) tanpa mengotori tabel `users`. Accessor (bukan relasi) dipakai karena Eloquent memaksa
`$user->clan` menjadi lookup relasi — detail teknis dijelaskan di komentar `getClanAttribute()`.

### 2.2 Status Pending vs Active dalam satu tabel

**Justifikasi:** permintaan bergabung dan keanggotaan aktif adalah tahap dari **entitas yang sama**.
Menyimpannya sebagai `status` pada satu baris (bukan dua tabel terpisah "requests" & "members")
menyederhanakan alur approve — cukup `update(['status' => Active])`, bukan pindah baris antar tabel.

### 2.3 Dua tingkat wewenang: roster vs kepemilikan

`ClanRole` memisahkan wewenang jadi dua lewat dua predikat, bukan lewat pengecekan role yang
tersebar:

```php
public function canManageMembers(): bool { return $this !== self::Member; }   // leader + co-leader
public function canManageWar(): bool     { return $this !== self::Member; }   // leader + co-leader
public function canManageClan(): bool    { return $this === self::Leader; }   // leader saja
```

**Justifikasi:** co-leader berbagi tugas harian (approve, reject, kick) tapi **tidak pernah**
menyentuh eksistensi atau kepemilikan clan (transfer, promote/demote, disband). Pemisahan inilah
yang membuat role co-leader aman dibagikan — seorang co-leader bisa membantu mengurus clan tanpa
bisa menghancurkannya.

**`canManageWar()` sengaja terpisah meski himpunannya sama persis dengan `canManageMembers()`.**
Keduanya menjawab pertanyaan berbeda — "boleh mengurus roster?" dan "boleh mengikat clan ke sebuah
war?" — dan kalau salah satunya memanggil yang lain, hari ketika satu bergeser akan menggeser yang
lain **diam-diam**. Duplikasi satu baris di sini lebih murah daripada perubahan kewenangan yang tak
disengaja.

> **Diubah 2026-08-06 — kewenangan war pindah dari leader-saja ke leader + co-leader.**
> Alasannya bukan "war ternyata tak sepenting itu", melainkan sebuah **ketiadaan**: tantangan
> hangus dalam `ACCEPT_WINDOW_HOURS` (1 jam), jadi leader yang kebetulan sedang offline satu jam
> itu membuat setiap tantangan lewat begitu saja dan **tak seorang pun** bisa berbuat apa-apa.
> Hidup-matinya war sebuah clan bergantung pada ketersediaan satu orang. Yang dipertaruhkan war
> adalah power Elo — yang dimainkan kembali — bukan clan itu sendiri, jadi ia lebih dekat ke kerja
> roster ketimbang ke kepemilikan. Lihat [clan-war.md](clan-war.md) §3.11.

**Gate peringkat.** Kick tidak cukup dijaga "apakah saya boleh kick", tapi juga *terhadap siapa*:

```php
if ($member->role->rank() <= $this->myMembership->role->rank()) return;
```

Tanpa ini seorang co-leader bisa mengeluarkan leader dan menyandera clan. Aksi hanya boleh
mengarah ke bawah, dan tak seorang pun bisa mengeluarkan dirinya sendiri.

**Scope clan.** Semua query di-scope ke `clan_id` clan saya sendiri, jadi menebak ID member clan
lain tak membuka jalur apa pun.

### 2.3.1 Jalan keluar leader: transfer & disband

Sebelumnya `leaveClan` memblokir leader dengan komentar "harus disband/transfer dulu", padahal
kedua jalur itu **tak pernah ada** — leader terkunci permanen di clannya.

**Transfer** memindahkan `role` di pivot **dan** `clans.leader_id` dalam **satu transaksi**.
Keduanya mencatat fakta yang sama (role menggerakkan permission, `leader_id` adalah alamat
notifikasi clan-war), jadi update sebagian akan menghasilkan clan yang "leader"-nya menurut satu
ukuran adalah member biasa menurut ukuran lain.

**Disband** dikonfirmasi dengan mengetik ulang nama clan — pola yang sama dengan penghapusan akun
di `Settings`, karena sama-sama tak bisa dibatalkan. **Diblokir selama war Pending/Ongoing:**
clan lawan sudah menanam klaim mode dan snapshot power, jadi membubarkan diri di tengah war akan
jadi cara gratis menghindari kekalahan Elo — dan cascade-nya ikut menghapus catatan war milik lawan.

### 2.4 Re-validasi server-side pada aksi

- `createClan` → blokir kalau sudah punya clan; validasi nama unik, tag ≤ 6, emblem/warna dari
  whitelist (`ClanEmblem::iconKeys()`/`colorKeys()`).
- `sendJoinRequest` → cegah kirim ulang kalau baris ke clan itu sudah ada.
- `cancelJoinRequest` → di-scope ke `Auth::id()` **dan** status Pending, jadi tak bisa dipakai
  membatalkan permintaan orang lain maupun jadi pintu belakang keluar dari keanggotaan aktif
  (itu `leaveClan`, yang punya guard leader sendiri). Baris **dihapus**, bukan ditandai, karena
  `sendJoinRequest` memblokir kalau baris apa pun ke clan itu masih ada — menandai akan membuat
  pemohon tak bisa mendaftar ulang. Sebelum ini permintaan hanya bisa hilang lewat accept/reject
  leader, jadi pemohon ke clan yang tak aktif tersangkut selamanya pada label mati "Permintaan
  Terkirim".

  **Tampilan:** status sebagai teks biasa, tombol "Batalkan" terpisah di sebelahnya — pasangan yang
  sama dengan permintaan terkirim di halaman Friends. Versi awal memakai satu kontrol yang bertukar
  label saat hover; itu menyembunyikan aksinya sama sekali di layar sentuh (tak ada hover di sana)
  dan membuat arti barisnya bergantung pada posisi pointer. Dua elemen yang keduanya selalu terlihat
  menyampaikan apa yang sedang terjadi dan apa yang bisa dilakukan sekaligus.
- `approveMember` → cek ulang batas 20 member **saat approve**, bukan hanya saat request.

**Justifikasi:** cek batas dilakukan di titik **approve** (bukan request), karena antara request dan
approve bisa ada perubahan jumlah member. Whitelist emblem/warna mencegah nilai liar merusak
tampilan.

### 2.5 Notifikasi real-time lewat `ClanUpdated` + `SafeBroadcast`

Setiap aksi menyiarkan ke channel `clan.{userId}` pihak terkait (dengan toast opsional). Body
listener `refreshClan()` kosong (pola yang sama dengan Friends/Chat).

**Justifikasi:** leader langsung melihat permintaan masuk, pemohon langsung tahu diterima/ditolak.
`SafeBroadcast` menjaga aksi tetap sukses meski Reverb down.

### 2.5.1 Aturan identitas dipusatkan, dipakai create & edit

Nama, tag, emblem, warna, dan deskripsi bisa diubah leader lewat `saveClanIdentity` — sebelumnya
identitas clan permanen sejak dibuat, jadi salah ketik nama tak bisa diperbaiki.

Aturan validasinya tinggal di **satu tempat**,
[`ClanEmblem::identityRules()`](../../app/Support/ClanEmblem.php), dipakai `createClan` maupun
`saveClanIdentity`.

**Justifikasi:** dua salinan aturan akan menyimpang — batas nama diperketat di satu tempat tapi
tidak di tempat lain berarti edit bisa menyimpan nilai yang create tolak. Satu beda yang memang
disengaja diparameterkan: `$ignoreClanId` membuat cek `unique` melewati clan yang sedang diedit,
tanpa itu menyimpan form tanpa mengubah nama akan bentrok dengan dirinya sendiri.

Markup formnya juga satu salinan
([`x-clan.identity-fields`](../../resources/views/components/clan/identity-fields.blade.php)),
dengan nama field di-pass sebagai prop karena kedua pemanggil terikat ke properti Livewire berbeda
(`new*` vs `edit*`). Form edit **menggantikan** hero card saat terbuka, bukan muncul di bawahnya:
form itu punya live preview sendiri, jadi menampilkan keduanya akan menaruh dua versi clan yang
sama di layar sekaligus.

### 2.6 Hapus akun diblokir untuk leader

[`Settings::deleteAccount`](../../app/Livewire/Settings.php) menolak user yang sedang memimpin clan.

**Justifikasi:** `clans.leader_id` memakai `onDelete('cascade')` ke `users`, jadi leader yang
menghapus akunnya **menghapus seluruh clan** — semua baris `clan_members` ikut ter-cascade, dan
lewat cascade kedua, catatan `clan_wars` milik **clan lawan** yang power-nya sudah terlanjur
berubah. Satu orang pergi, banyak data orang lain hancur sebagai efek samping.

Ditolak, bukan auto-transfer ke member terlama: menyerahkan clan diam-diam kepada orang yang tak
pernah memintanya adalah kejutan tersendiri. Leader diarahkan ke transfer atau disband (§2.3.1) —
keduanya pilihan eksplisit. Modal hapus akun menampilkan peringatan ini **sebelum** user mengetik
username-nya, jadi jalan buntunya tak datang mendadak; gate server tetap berlaku terlepas dari UI.

### 2.7 Clan kosong disembunyikan, bukan dihapus

Scope [`Clan::populated()`](../../app/Models/Clan.php) menyaring clan tanpa anggota aktif dari
**Browse**, **leaderboard**, dan **daftar clan yang bisa ditantang**.

**Justifikasi:** clan kosong adalah hantu — tak ada yang bisa menyetujui permintaan gabung, dan tak
ada yang bisa mengerjakan satu pun klaim mode, jadi menantangnya sama dengan Elo gratis selama 3
hari. Disembunyikan, bukan dihapus, karena penghapusan akan cascade ke catatan `clan_wars` milik
clan **lawan** yang power-nya sudah berubah — alasan yang sama dengan blokir disband saat war (§2.3.1).

**Gate diulang di titik aksi, bukan cuma di daftar.** `sendJoinRequest` dan `challengeClan`
memakai `Clan::populated()->find()`, bukan `Clan::find()`. Menyaring daftar hanyalah presentasi;
tanpa cek di aksi, ID-nya masih bisa dikirim langsung.

### 2.8 Konteks roster: online, kontribusi war, tanggal gabung

Tiap baris roster membawa apa yang dibutuhkan leader untuk menilainya. Sebelumnya leader diminta
memutuskan kick hanya dari nama dan level.

- **Online / terakhir aktif** — dari `last_seen_at` lewat `User::isOnline()`. Status online
  ditempelkan sebagai titik pada avatar (pola yang sama dengan daftar teman), dan "terakhir aktif"
  **hanya muncul saat offline** — bagi yang sedang online itu mengulang apa yang sudah dikatakan titik.
- **Kontribusi war** — `Clan::lastWarContributions()`, **satu query agregat** untuk seluruh roster
  (bukan satu query per anggota). Di-scope ke **war terakhir yang selesai**, bukan sepanjang masa:
  angka ini dibaca untuk menilai siapa yang berkontribusi *sekarang*, dan total sepanjang masa akan
  menempatkan veteran yang lama menganggur di atas anggota baru yang aktif.
- **Tanggal gabung** — dari `clan_members.created_at`.

`contribution` bernilai **null** (bukan `0.0`) kalau clan belum pernah menyelesaikan war, jadi view
bisa membedakan "tidak menyumbang" dari "belum sempat ikut" — roster tak boleh menuduh anggota
menganggur di war yang tak pernah berlangsung. Hanya klaim yang **sudah disubmit** yang dihitung,
konsisten dengan cara poin war dihitung di tempat lain: mengunci slot lalu mendiamkannya bernilai 0.

## 3. Emblem & Identitas Clan

Emblem (ikon + warna) dipilih dari katalog terkontrol
[`ClanEmblem`](../../app/Support/ClanEmblem.php) dengan default aman. Ini memberi identitas visual
tanpa mengizinkan upload gambar bebas (sederhana & aman).
