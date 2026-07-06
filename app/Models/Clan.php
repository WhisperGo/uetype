<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanWarStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clan extends Model
{
    protected $fillable = [
        'name',
        'tag',
        'emblem',
        'emblem_color',
        'description',
        'leader_id',
        'power',
    ];

    /**
     * Power (rating Elo) mulai dari 1000. Level clan diturunkan MURNI dari power —
     * tak ada kolom level tersimpan, jadi tak pernah out-of-sync. Tiap POWER_PER_LEVEL
     * poti power menaikkan satu level; BASE_POWER = level 1.
     */
    public const BASE_POWER = 1000;

    public const POWER_PER_LEVEL = 100;

    public static function levelFromPower(int $power): int
    {
        $level = (int) floor(($power - self::BASE_POWER) / self::POWER_PER_LEVEL) + 1;

        return max(1, $level);
    }

    public static function powerToReachLevel(int $level): int
    {
        return self::BASE_POWER + (max(1, $level) - 1) * self::POWER_PER_LEVEL;
    }

    /**
     * Data level untuk presentasi (level, progres di level ini, dan berapa power
     * yang dibutuhkan untuk naik). Meniru pola User::levelData(): satu sumber
     * kebenaran, dipakai header show / kartu my-clan / baris leaderboard.
     *
     * @return array{level:int, power:int, progress:int, needed:int, next_level:int}
     */
    public function levelData(): array
    {
        $power = (int) ($this->power ?? self::BASE_POWER);
        $level = self::levelFromPower($power);

        $floor = self::powerToReachLevel($level);
        $ceil = self::powerToReachLevel($level + 1);

        return [
            'level' => $level,
            'power' => $power,
            'progress' => max(0, $power - $floor),
            'needed' => $ceil - $floor,
            'next_level' => $level + 1,
        ];
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ClanMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', ClanMemberStatus::Active);
    }

    /**
     * War (challenge) yang sedang melibatkan clan ini, baik sebagai
     * penantang maupun tertantang, selama masih Pending atau Ongoing.
     * Null berarti clan ini bebas menantang/ditantang.
     */
    public function activeWar(): ?ClanWar
    {
        return ClanWar::where(function ($q) {
            $q->where('challenger_clan_id', $this->id)
                ->orWhere('opponent_clan_id', $this->id);
        })
            ->whereIn('status', [ClanWarStatus::Pending, ClanWarStatus::Ongoing])
            ->first();
    }

    /**
     * Riwayat war SELESAI yang melibatkan clan ini (dua arah), terbaru dulu.
     * Dipakai oleh halaman detail clan & ringkasan history di halaman war.
     */
    public function finishedWars(int $limit = 20)
    {
        return ClanWar::with(['challenger', 'opponent'])
            ->where(function ($q) {
                $q->where('challenger_clan_id', $this->id)
                    ->orWhere('opponent_clan_id', $this->id);
            })
            ->where('status', ClanWarStatus::Finished)
            ->latest('updated_at')
            ->take($limit)
            ->get();
    }

    /**
     * Ubah sebuah baris ClanWar menjadi ringkasan dari SUDUT PANDANG clan ini:
     * hasil (win/draw/loss), lawan, dan delta power. Menjaga logika
     * "balik hasil kalau kita opponent" di satu tempat.
     *
     * @return array{result: string, opponent: Clan, delta: int}
     */
    public function warSummary(ClanWar $war): array
    {
        $isChallenger = $war->challenger_clan_id === $this->id;
        $opponent = $isChallenger ? $war->opponent : $war->challenger;
        $delta = $isChallenger ? $war->challenger_power_delta : $war->opponent_power_delta;

        if ($war->result === 'draw') {
            $result = 'draw';
        } elseif ($isChallenger) {
            $result = $war->result;
        } else {
            $result = $war->result === 'win' ? 'loss' : 'win';
        }

        return ['result' => $result, 'opponent' => $opponent, 'delta' => (int) $delta];
    }
}
