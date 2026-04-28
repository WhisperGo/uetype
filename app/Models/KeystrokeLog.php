<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KeystrokeLog extends Model
{
    protected $fillable = [
        'match_participant_id',
        'raw_keystroke',
        'heatmap_data',
        'is_bot_flag',
    ];

    public function matchParticipant()
    {
        return $this->belongsTo(MatchParticipant::class);
    }
}
