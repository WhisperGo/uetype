<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchParticipant extends Model
{
    protected $fillable = [
        'match_id',
        'user_id',
        'wpm',
        'accuracy',
        'placement',
        'elo_change',
        'connection_status',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matches::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function keystrokeLogs(): HasMany
    {
        return $this->hasMany(KeystrokeLog::class);
    }
}
