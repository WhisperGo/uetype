<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanWarParticipant extends Model
{
    protected $fillable = [
        'clan_war_id',
        'clan_id',
        'points',
        'placement',
    ];

    public function clanWar(): BelongsTo
    {
        return $this->belongsTo(ClanWar::class);
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }
}
