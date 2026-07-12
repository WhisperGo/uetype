<?php

namespace App\Models;

use App\Enums\ClanWarStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A war challenge between two clans: the pairing, its status, and each side's
 * Elo power before/after so results can be scored and settled.
 */
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

    public function challenger(): BelongsTo
    {
        return $this->belongsTo(Clan::class, 'challenger_clan_id');
    }

    public function opponent(): BelongsTo
    {
        return $this->belongsTo(Clan::class, 'opponent_clan_id');
    }
}
