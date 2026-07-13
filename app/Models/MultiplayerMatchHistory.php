<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A permanent record of one player's result in a finished multiplayer race:
 * place, opponent count, wpm, accuracy, finish time and XP earned. Written once
 * by MultiplayerLobby::finalizeRace(), before the room (rooms/room_members) can
 * be deleted. Backs the Multiplayer tab on the Stats page.
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
