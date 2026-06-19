<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clan extends Model
{
    protected $fillable = [
        'name',
        'tag',
        'leader_id',
        'clan_rating',
        'member_count',
    ];

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function warParticipants(): HasMany
    {
        return $this->hasMany(ClanWarParticipant::class);
    }

    public function joinRequests(): HasMany
    {
        return $this->hasMany(ClanJoinRequest::class);
    }
}
