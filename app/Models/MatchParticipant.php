<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function match()
    {
        return $this->belongsTo(Matches::class, 'match_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function keystrokeLogs()
    {
        return $this->hasMany(KeystrokeLog::class);
    }
}
