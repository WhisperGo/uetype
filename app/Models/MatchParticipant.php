<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
