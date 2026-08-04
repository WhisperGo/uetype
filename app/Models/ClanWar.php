<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanWarStatus;
use App\Services\ClanWarModeCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A war between two clans: pairing, status, and each side's Elo before/after. */
class ClanWar extends Model
{
    protected $fillable = [
        'challenger_clan_id',
        'opponent_clan_id',
        // Identity snapshots so a finished war still reads as a sentence after either clan
        // disbands; written by booted(), see there.
        'challenger_name',
        'opponent_name',
        'status',
        'challenger_power_before',
        'opponent_power_before',
        'challenger_max_claims',
        'opponent_max_claims',
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

    /**
     * Snapshot both clans' names as the war is created.
     *
     * Here rather than at the call site because a snapshot that any future way of starting a
     * war could forget is a snapshot that will eventually be missing exactly when it matters:
     * the row it belongs to outlives the clan it names.
     */
    protected static function booted(): void
    {
        static::creating(function (self $war) {
            $war->challenger_name ??= Clan::find($war->challenger_clan_id)?->name;
            $war->opponent_name ??= Clan::find($war->opponent_clan_id)?->name;
        });
    }

    /** The clan that issued the challenge; null once it has disbanded. */
    public function challenger(): BelongsTo
    {
        return $this->belongsTo(Clan::class, 'challenger_clan_id');
    }

    /** The clan that was challenged; null once it has disbanded. */
    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Clan::class, 'opponent_clan_id');
    }

    /**
     * The other side's name, whether or not that clan still exists.
     *
     * Prefers the live clan so a rename is reflected, and falls back to the snapshot taken at
     * creation. Only ever empty for wars predating the snapshot columns whose clan is also
     * already gone -- callers render a neutral placeholder for that.
     */
    public function opponentNameFor(int $clanId): string
    {
        $isChallenger = $this->challenger_clan_id === $clanId;

        $live = $isChallenger ? $this->opponent : $this->challenger;
        $snapshot = $isChallenger ? $this->opponent_name : $this->challenger_name;

        return $live?->name ?? $snapshot ?? '';
    }

    /**
     * How many of the 9 slots one member of $clanId may claim in this war.
     *
     * Reads the snapshot taken when the challenge was accepted, so the cap cannot move while
     * the war runs -- a live formula would let a clan kick members to raise its own cap and
     * concentrate every slot in one account, which is the finding the cap exists to close.
     *
     * Falls back to the current roster when there is no snapshot: wars predating the column,
     * and every test that inserts an Ongoing war directly. A missing snapshot must not mean
     * "no cap", and it must not mean "cannot claim" either.
     */
    public function maxClaimsFor(int $clanId): int
    {
        $snapshot = $clanId === $this->challenger_clan_id
            ? $this->challenger_max_claims
            : $this->opponent_max_claims;

        if ($snapshot !== null) {
            return (int) $snapshot;
        }

        $activeMembers = ClanMember::query()
            ->where('clan_id', $clanId)
            ->where('status', ClanMemberStatus::Active)
            ->count();

        return ClanWarModeCatalog::claimCapFor($activeMembers);
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
