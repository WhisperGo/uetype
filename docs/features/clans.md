# Fitur 9 — Clan

**Komponen:** [`App\Livewire\Clans`](../../app/Livewire/Clans.php) — route `/clans`,
[`ClanShow`](../../app/Livewire/ClanShow.php) — `/clans/{clan}`,
[`ClanLeaderboard`](../../app/Livewire/ClanLeaderboard.php) — `/clan-leaderboard`
**Model:** [`Clan`](../../app/Models/Clan.php), [`ClanMember`](../../app/Models/ClanMember.php)
**Enum:** `ClanRole` (Leader/Member), `ClanMemberStatus` (Pending/Active)

---

## 1. Apa Ini

Grup pemain. Fitur: **buat clan** (nama, tag, emblem, warna, deskripsi), **cari & gabung**
(kirim permintaan), **approve/reject** permintaan (leader), **kick** member (leader),
**keluar** clan (member). Leaderboard clan mengurutkan berdasarkan `power` (dari Clan War).

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

### 2.3 Otorisasi berbasis peran, di-*scope* ke leadership

```php
private function pendingForMyLeadership(int $id): ?ClanMember {
    return ClanMember::where('id', $id)->where('status', Pending)
        ->whereHas('clan', fn($q) => $q->where('leader_id', Auth::id()))->first();
}
```

**Justifikasi:** approve/reject/kick hanya boleh oleh leader **clan yang bersangkutan**. Query
di-scope langsung ke `leader_id = Auth::id()`, jadi tak ada jalur bagi non-leader (atau leader clan
lain) memanipulasi member dengan menebak ID. Guard tambahan: leader tak bisa kick/keluar dirinya
sendiri (harus bubarkan/transfer clan dulu).

### 2.4 Re-validasi server-side pada aksi

- `createClan` → blokir kalau sudah punya clan; validasi nama unik, tag ≤ 6, emblem/warna dari
  whitelist (`ClanEmblem::iconKeys()`/`colorKeys()`).
- `sendJoinRequest` → cegah kirim ulang kalau baris ke clan itu sudah ada.
- `approveMember` → cek ulang batas 20 member **saat approve**, bukan hanya saat request.

**Justifikasi:** cek batas dilakukan di titik **approve** (bukan request), karena antara request dan
approve bisa ada perubahan jumlah member. Whitelist emblem/warna mencegah nilai liar merusak
tampilan.

### 2.5 Notifikasi real-time lewat `ClanUpdated` + `SafeBroadcast`

Setiap aksi menyiarkan ke channel `clan.{userId}` pihak terkait (dengan toast opsional). Body
listener `refreshClan()` kosong (pola yang sama dengan Friends/Chat).

**Justifikasi:** leader langsung melihat permintaan masuk, pemohon langsung tahu diterima/ditolak.
`SafeBroadcast` menjaga aksi tetap sukses meski Reverb down.

## 3. Emblem & Identitas Clan

Emblem (ikon + warna) dipilih dari katalog terkontrol
[`ClanEmblem`](../../app/Support/ClanEmblem.php) dengan default aman. Ini memberi identitas visual
tanpa mengizinkan upload gambar bebas (sederhana & aman).
