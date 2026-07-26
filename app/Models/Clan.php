<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanWarStatus;
use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** A player clan (guild): identity, Elo power, members, and war history. */
class Clan extends Model
{
    // Action monitoring: logs clan create/update/delete (binafy/laravel-user-monitoring).
    use Actionable;

    protected $fillable = [
        'name',
        'tag',
        'emblem',
        'emblem_color',
        'description',
        'leader_id',
        'power',
    ];

    /** Clan level is derived purely from power (no stored level column, so it can never drift out of sync). */
    public const BASE_POWER = 1000;

    public const POWER_PER_LEVEL = 100;

    /** Clan level derived from a power value (level 1 at BASE_POWER). */
    public static function levelFromPower(int $power): int
    {
        $level = (int) floor(($power - self::BASE_POWER) / self::POWER_PER_LEVEL) + 1;

        return max(1, $level);
    }

    /** Power needed to reach a given level. */
    public static function powerToReachLevel(int $level): int
    {
        return self::BASE_POWER + (max(1, $level) - 1) * self::POWER_PER_LEVEL;
    }

    /**
     * Level breakdown for display; same shape as User::levelData().
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

    /** The user who leads the clan. */
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    /** All members (any status). */
    public function members(): HasMany
    {
        return $this->hasMany(ClanMember::class);
    }

    /** Only approved (active) members. */
    public function activeMembers(): HasMany
    {
        return $this->members()->where('status', ClanMemberStatus::Active);
    }

    /**
     * Only clans with at least one active member.
     *
     * An empty clan is a ghost: nobody can approve its join requests, and challenging it
     * to a war it cannot play is free Elo. Hidden rather than deleted -- deleting cascades
     * into the war records of opposing clans whose power has already moved.
     */
    public function scopePopulated(Builder $query): Builder
    {
        return $query->whereHas('members', fn (Builder $q) => $q->where('status', ClanMemberStatus::Active));
    }

    /**
     * Active roster in authority order: leader, co-leaders, members.
     *
     * Sorted in PHP on rank(), not `orderBy('role')`: role is a string column, so SQL
     * sorts it alphabetically and puts 'co-leader' above 'leader'.
     */
    public function orderedActiveMembers()
    {
        return $this->activeMembers()->with('user')->get()
            ->sortBy(fn (ClanMember $m) => [$m->role->rank(), mb_strtolower($m->user->username ?? '')])
            ->values();
    }

    /** The clan's current pending/ongoing war (either side), or null if free. */
    public function activeWar(): ?ClanWar
    {
        return ClanWar::where(function ($q) {
            $q->where('challenger_clan_id', $this->id)
                ->orWhere('opponent_clan_id', $this->id);
        })
            ->whereIn('status', [ClanWarStatus::Pending, ClanWarStatus::Ongoing])
            ->first();
    }

    /** Finished wars involving this clan (either side), newest first. */
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

    /** The most recently finished war involving this clan, or null. */
    public function lastFinishedWar(): ?ClanWar
    {
        return ClanWar::where(function ($q) {
            $q->where('challenger_clan_id', $this->id)
                ->orWhere('opponent_clan_id', $this->id);
        })
            ->where('status', ClanWarStatus::Finished)
            ->latest('updated_at')
            ->first();
    }

    /**
     * Points each member scored in a war, keyed by user id. One aggregate for the whole
     * roster, not one query per member.
     *
     * The roster passes the LAST FINISHED war, not an all-time total: the number is read
     * to judge who is pulling their weight now, and all-time would rank an idle veteran
     * above an active newcomer. Members with no claim are absent from the map, so callers
     * can tell "scored zero" from "wasn't here yet".
     *
     * @return Collection<int, float>
     */
    public function warContributions(?ClanWar $war): Collection
    {
        if (! $war) {
            return collect();
        }

        return ClanWarModeClaim::where('clan_war_id', $war->id)
            ->where('clan_id', $this->id)
            ->whereNotNull('typing_result_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, SUM(points) as total')
            ->pluck('total', 'user_id')
            ->map(fn ($v) => (float) $v);
    }

    /**
     * War summary from this clan's perspective (result flipped if we're the opponent).
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
