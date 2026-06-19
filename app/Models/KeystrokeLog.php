<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeystrokeLog extends Model
{
    protected $fillable = [
        'match_participant_id',
        'raw_keystroke',
    ];

    protected $casts = [
        'raw_keystroke' => 'array',
    ];

    public function matchParticipant(): BelongsTo
    {
        return $this->belongsTo(MatchParticipant::class);
    }
}
