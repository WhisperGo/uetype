<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Matches extends Model
{
    protected $fillable = [
        'text_id',
        'match_type',
        'status',
        'started_at',
        'ended_at',
    ];

    public function text()
    {
        return $this->belongsTo(Text::class);
    }

    public function matchParticipants()
    {
        return $this->hasMany(MatchParticipant::class, 'match_id');
    }
}
