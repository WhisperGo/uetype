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
        'leader_id',
        'power',
    ];

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
}
