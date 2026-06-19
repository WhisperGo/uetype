<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MatchParticipant extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'match_id',
        'user_id',
        'wpm',
        'accuracy',
        'placement',
        'elo_change',
        'connection_status',
        'wpm_samples',
        'heatmap_data',
        'is_suspicious',
        'cheat_summary',
    ];

    protected $casts = [
        'wpm_samples' => 'array',
        'heatmap_data' => 'array',
        'cheat_summary' => 'array',
        'is_suspicious' => 'boolean',
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
