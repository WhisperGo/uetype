<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A player in a race room: live race state, final placement, and XP earned. */
class RoomMember extends Model
{
    /**
     * DNF marker (gave up / ran out of sudden-death time) stored in the
     * finished_time_seconds column. NOT a real duration -- just a large value so
     * DNF players always sort below every legitimate finisher.
     *
     * This sentinel is transient: rooms/room_members are deleted once all players
     * leave. It MUST NOT reach multiplayer_match_history (the permanent record),
     * where that column means "elapsed duration" and 999 would poison any statistic
     * that averages finish time.
     */
    public const DNF_SENTINEL_SECONDS = 999;

    public const ROLE_PLAYER = 'player';

    public const ROLE_SPECTATOR = 'spectator';

    protected $fillable = [
        'room_id', 'user_id', 'role', 'is_ready', 'progress_percent',
        'wpm', 'accuracy', 'finished_time_seconds', 'place', 'xp_earned',
        'result_recorded',
    ];

    protected $casts = [
        'result_recorded' => 'boolean',
    ];

    /** Whether this player did not finish the race (gave up / ran out of time). */
    public function isDnf(): bool
    {
        return (int) $this->finished_time_seconds === self::DNF_SENTINEL_SECONDS;
    }

    /** The REAL elapsed duration, or null if DNF (no meaningful duration). */
    public function realFinishedSeconds(): ?int
    {
        return $this->isDnf() ? null : $this->finished_time_seconds;
    }

    /** Spectator: watches live, excluded from standings/placement/XP. */
    public function isSpectator(): bool
    {
        return $this->role === self::ROLE_SPECTATOR;
    }

    /** Racer: participates in the race, standings, placement, and XP. */
    public function isPlayer(): bool
    {
        return ! $this->isSpectator();
    }

    /** The user this membership belongs to. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** The room this membership belongs to. */
    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
