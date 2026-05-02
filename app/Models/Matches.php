<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matches extends Model
{
    protected $fillable = [
        'text_id',
        'match_type',
        'status',
        'started_at',
        'ended_at',
    ];

    public function text(): BelongsTo
    {
        return $this->belongsTo(Text::class);
    }

    public function matchParticipants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class, 'match_id');
    }
}
