<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanWarStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A war between two clans: pairing, status, and each side's Elo before/after. */
class ClanWar extends Model
{
    protected $fillable = [
        'challenger_clan_id',
        'opponent_clan_id',
        'status',
        'challenger_power_before',
        'opponent_power_before',
        'challenger_power_delta',
        'opponent_power_delta',
        'result',
        'accept_deadline_at',
        'started_at',
        'ends_at',
    ];

    protected $casts = [
        'status' => ClanWarStatus::class,
        'accept_deadline_at' => 'datetime',
        'started_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /** The clan that issued the challenge. */
    public function challenger(): BelongsTo
    {
        return $this->belongsTo(Clan::class, 'challenger_clan_id');
    }

    /** The clan that was challenged. */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Clan::class, 'opponent_clan_id');
    }

    /**
     * Every ACTIVE member of BOTH clans, as user ids.
     *
     * Lives on the war rather than on Clan because "who is taking part" is a property of the
     * PAIRING: every screen that changes when a war changes -- the mode grid, both point
     * totals, the waiting/ongoing branch -- belongs to somebody on one of these two rosters.
     * Reading it from two Clan models would need both loaded first, and one query does here
     * what four would there.
     *
     * Pending memberships are excluded: an unapproved join request is not in the clan, so it
     * is not in the war either.
     *
     * @return array<int, int>
     */
    public function participantUserIds(): array
    {
        return ClanMember::query()
            ->whereIn('clan_id', [$this->challenger_clan_id, $this->opponent_clan_id])
            ->where('status', ClanMemberStatus::Active)
            ->distinct()
            ->pluck('user_id')
            ->all();
    }
}
