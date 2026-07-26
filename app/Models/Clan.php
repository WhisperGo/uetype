<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanWarStatus;
use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Only clans that still have at least one active member.
     *
     * A clan cannot normally empty out -- the leader can't kick or leave themselves, so
     * disband and transfer are the only exits -- but a roster CAN reach zero through data
     * repair, a cascade, or a future path we haven't written yet. When it does, the clan
     * is a ghost: it lists in Browse with "0 members", can be sent join requests nobody
     * can approve, and can be challenged to a war it cannot possibly play, handing the
     * challenger free Elo.
     *
     * Hiding rather than deleting: the row is harmless where it sits, and deleting it
     * would cascade into the war records of OPPOSING clans whose power has already moved
     * -- the same reason disband is blocked mid-war.
     */
    public function scopePopulated(Builder $query): Builder
    {
        return $query->whereHas('members', fn (Builder $q) => $q->where('status', ClanMemberStatus::Active));
    }

    /**
     * Active roster in authority order: leader, then co-leaders, then members.
     *
     * Sorted in PHP on the enum's rank(), not `orderBy('role')` in SQL. The role column
     * holds a string, so the database sorts it alphabetically -- which put 'co-leader'
     * ABOVE 'leader'. Every roster reads through here so the order can never drift
     * between the two pages that render it.
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
     * Points each member scored in the last finished war, keyed by user id.
     *
     * Scoped to the last war rather than all-time on purpose: the roster shows this to
     * help a leader judge who is pulling their weight NOW. An all-time total would rank a
     * long-idle veteran above an active newcomer, which is the opposite of what the number
     * is being read for. Members with no claim are simply absent from the map -- the view
     * distinguishes "scored zero" from "wasn't in the clan yet".
     *
     * One aggregate query for the whole roster, not one per member.
     *
     * @return \Illuminate\Support\Collection<int, float>
     */
    public function lastWarContributions(): \Illuminate\Support\Collection
    {
        $war = $this->lastFinishedWar();

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
