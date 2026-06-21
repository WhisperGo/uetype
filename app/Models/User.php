<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

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
}
