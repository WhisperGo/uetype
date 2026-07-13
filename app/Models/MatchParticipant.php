<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One user's result in a match (wpm, accuracy, placement); write-once. */
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

    /** The match this result belongs to. */
    public function match(): BelongsTo
    {
        return $this->belongsTo(Matches::class, 'match_id');
    }

    /** The player this result belongs to. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
