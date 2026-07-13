<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A permanent record of one player's finished-race result (place, wpm, accuracy).
 * Written by MultiplayerLobby::finalizeRace() before the room is deleted; backs
 * the Stats page Multiplayer tab.
 */
class MultiplayerMatchHistory extends Model
{
    protected $table = 'multiplayer_match_history';

    protected $fillable = [
        'user_id',
        'room_code',
        'place',
        'player_count',
        'wpm',
        'accuracy',
        'finished_time_seconds',
        'xp_earned',
    ];

    protected $casts = [
        'accuracy' => 'decimal:2',
    ];

    /** The player this match result belongs to. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
