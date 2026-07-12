<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One user's result within a completed match: their wpm, accuracy, placement,
 * and finish time. Immutable once written (no updated_at).
 */
class MatchParticipant extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'user_id',
        'wpm',
        'accuracy',
        'placement',
        'finished_at',
    ];

    protected $casts = [
        'wpm' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'finished_at' => 'datetime',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(Matches::class, 'match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
