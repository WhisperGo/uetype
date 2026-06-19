<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClanWar extends Model
{
    protected $fillable = [
        'name',
        'starts_at',
        'ends_at',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(ClanWarParticipant::class);
    }
}
