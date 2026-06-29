<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoomMember extends Model
{
    protected $fillable = [
        'room_id', 'user_id', 'is_ready', 'progress_percent', 
        'wpm', 'accuracy', 'finished_time_seconds', 'place'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}