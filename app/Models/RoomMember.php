<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single player's participation in a multiplayer race room: their live race
 * state (progress, wpm, accuracy), final placement, and XP earned.
 */
class RoomMember extends Model
{
    protected $fillable = [
        'room_id', 'user_id', 'is_ready', 'progress_percent',
        'wpm', 'accuracy', 'finished_time_seconds', 'place', 'xp_earned',
    ];

    /** The user this membership belongs to. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
