<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\FriendshipStatus;
use App\Events\PresenceUpdated;
use App\Support\SafeBroadcast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * The application's user account. Holds identity (Google/email login),
 * progression (highest_wpm, total_xp, level), social graph (friends, clan),
 * and presence. Also the anchor for typing results and achievements.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'google_id',
        'email',
        'username',
        'avatar',
        'highest_wpm',
        'total_xp',
        'is_admin',
        'preferences',
        'last_seen_at',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'highest_wpm' => 'decimal:2',
            'is_admin' => 'boolean',
            'preferences' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * Base kurva level progresif (requirement Level/EXP bag. 2, Opsi B):
     * EXP untuk naik dari level L ke L+1 = BASE × L.
     * Jadi EXP kumulatif untuk MENCAPAI level N = BASE × (1+2+...+(N-1)) = BASE × N(N-1)/2.
     * Angka ini boleh di-tuning; polanya (progresif) yang dikunci.
     */
    public const LEVEL_BASE = 100;

    /**
     * Total EXP kumulatif yang dibutuhkan untuk MENCAPAI level $level (level mulai dari 1).
     * Level 1 = 0 EXP. Ini fungsi turunan murni dari total_xp — tidak ada level tersimpan.
     */
    public static function xpToReachLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        return (int) (self::LEVEL_BASE * ($level * ($level - 1)) / 2);
    }

    /**
     * Hitung level dari total EXP. total_xp adalah SATU-SATUNYA sumber kebenaran;
     * level selalu diturunkan dari sini (requirement Level/EXP bag. 3).
     */
    public static function levelForXp(int $xp): int
    {
        // Bentuk tertutup membalik N(N-1)/2 × BASE ≤ xp → N = floor((1 + sqrt(1 + 8xp/BASE)) / 2).
        $level = (int) floor((1 + sqrt(1 + (8 * max(0, $xp)) / self::LEVEL_BASE)) / 2);

        return max(1, $level);
    }

    /**
     * Data level untuk presentasi (satu sumber, dipakai profil/navigation/leaderboard).
     * Mengembalikan level sekarang, EXP progres di level ini, dan EXP yang dibutuhkan
     * untuk naik ke level berikutnya — semua diturunkan dari total_xp.
     *
     * @return array{level:int, total_xp:int, progress:int, needed:int, next_level:int}
     */
    public function levelData(): array
    {
        $xp = (int) ($this->total_xp ?? 0);
        $level = self::levelForXp($xp);

        $floor = self::xpToReachLevel($level);       // EXP untuk mencapai level ini
        $ceil = self::xpToReachLevel($level + 1);     // EXP untuk level berikutnya

        return [
            'level' => $level,
            'total_xp' => $xp,
            'progress' => $xp - $floor,               // 0 .. needed
            'needed' => $ceil - $floor,               // EXP rentang level ini (= BASE × level)
            'next_level' => $level + 1,
        ];
    }

    /**
     * Rumus EXP berbasis volume + bonus akurasi tipis. SATU sumber kebenaran yang
     * dipakai mode solo (TypingEngine) DAN multiplayer (MultiplayerLobby) supaya
     * keduanya konsisten. Mengakumulasi ke total_xp, menyimpan, dan mengembalikan
     * jumlah EXP yang diperoleh.
     *
     * Basis volume (jumlah karakter benar) — SENGAJA bukan berbasis WPM: menghargai
     * usaha/latihan, bukan bakat, dan tidak menghukum pengetik lambat.
     */
    public function addExp(int $correctChars, float $accuracy): int
    {
        $accuracyMultiplier = 0.5 + 0.5 * (max(0, min(100, $accuracy)) / 100);
        $xpEarned = (int) round(max(0, $correctChars) * 0.1 * $accuracyMultiplier);

        $this->total_xp += $xpEarned;
        $this->save();

        return $xpEarned;
    }

    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->preferences ?? [];
        $preferences[$key] = $value;
        $this->preferences = $preferences;
        $this->save();
    }

    public function typingResults(): HasMany
    {
        return $this->hasMany(TypingResult::class);
    }

    public function matchParticipants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class);
    }

    public function hostedMatches(): HasMany
    {
        return $this->hasMany(Matches::class, 'host_user_id');
    }

    public function sentFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'requester_id');
    }

    public function receivedFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'addressee_id');
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    public function currentRoom()
    {
        return $this->hasOneThrough(Room::class, RoomMember::class, 'user_id', 'id', 'id', 'room_id');
    }

    /**
     * Cari baris friendship antara user ini dan $otherId, ke arah mana pun
     * (baik user ini pengirim maupun penerima). Null jika belum ada relasi.
     */
    public function friendshipWith(int $otherId): ?Friendship
    {
        return Friendship::query()
            ->where(function ($q) use ($otherId) {
                $q->where('requester_id', $this->id)->where('addressee_id', $otherId);
            })
            ->orWhere(function ($q) use ($otherId) {
                $q->where('requester_id', $otherId)->where('addressee_id', $this->id);
            })
            ->first();
    }

    /**
     * Ambang (detik) di mana user masih dianggap online. Heartbeat klien
     * dikirim tiap ~30 detik; ambang 60 detik memberi toleransi satu heartbeat
     * yang terlewat sebelum dianggap offline. Konstanta yang mudah di-tuning.
     */
    public const ONLINE_THRESHOLD_SECONDS = 60;

    /**
     * Apakah user dianggap online sekarang: last_seen_at ada DAN masih dalam
     * ambang. Diturunkan murni dari timestamp — tak ada flag boolean tersimpan
     * yang bisa "nyangkut" true saat browser tertutup tanpa event offline.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS));
    }

    /**
     * Catat bahwa user ini aktif barusan (dipanggil dari endpoint heartbeat).
     * Jika ini transisi offline->online, siarkan PresenceUpdated ke semua teman
     * agar titik status di daftar teman mereka menyala real-time. Heartbeat
     * lanjutan (saat sudah online) hanya meng-update timestamp tanpa broadcast,
     * mencegah banjir pesan WebSocket tiap ~30 detik.
     */
    public function touchPresence(): void
    {
        $wasOnline = $this->isOnline();

        $this->forceFill(['last_seen_at' => now()])->save();

        if (! $wasOnline) {
            $this->broadcastPresenceToFriends();
        }
    }

    /**
     * Tandai user offline segera (dipanggil saat logout) dengan mengosongkan
     * last_seen_at, lalu siarkan agar teman langsung melihat status offline
     * tanpa menunggu ambang kedaluwarsa.
     */
    public function markOffline(): void
    {
        $wasOnline = $this->isOnline();

        $this->forceFill(['last_seen_at' => null])->save();

        if ($wasOnline) {
            $this->broadcastPresenceToFriends();
        }
    }

    /**
     * Siarkan perubahan status ke channel friends.{id} milik SETIAP teman
     * (status accepted). Menumpang infrastruktur toast/refresh yang sudah ada.
     */
    private function broadcastPresenceToFriends(): void
    {
        Friendship::query()
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $this->id)->orWhere('addressee_id', $this->id))
            ->get()
            ->each(function (Friendship $f) {
                $friendId = $f->requester_id === $this->id ? $f->addressee_id : $f->requester_id;
                SafeBroadcast::run(fn () => broadcast(new PresenceUpdated($friendId)));
            });
    }

    /**
     * Baris keanggotaan clan AKTIF milik user ini (bukan yang masih pending).
     * Tak ada kolom clan_id di tabel users -- keanggotaan diturunkan lewat
     * pivot clan_members, mengikuti pola currentRoom() di atas.
     */
    public function clanMembership(): ?ClanMember
    {
        return ClanMember::where('user_id', $this->id)
            ->where('status', ClanMemberStatus::Active)
            ->first();
    }

    /**
     * Accessor (BUKAN relasi) supaya $user->clan di profile view mengembalikan
     * clan aktif user ini. Harus lewat accessor, bukan method clan(): Eloquent
     * memperlakukan $user->clan sebagai magic-property lookup yang jatuh ke
     * __call('clan', []) kalau ada method bernama sama, lalu memvalidasi hasilnya
     * HARUS instance Relation -- jadi method biasa akan meledak di sini.
     */
    public function getClanAttribute(): ?Clan
    {
        return $this->clanMembership()?->clan;
    }

    /**
     * Accessor supaya $user->clan_role dipakai apa adanya di profile view
     * tanpa kolom tersimpan -- diturunkan dari role pada clan_members.
     */
    public function getClanRoleAttribute(): ?string
    {
        return $this->clanMembership()?->role?->value;
    }
}
