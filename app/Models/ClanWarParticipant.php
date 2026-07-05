<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanWarParticipant extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'clan_war_id',
        'clan_id',
        'total_contribution',
        'placement',
    ];

    public function war(): BelongsTo
    {
        return $this->belongsTo(ClanWar::class, 'clan_war_id');
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }
}
