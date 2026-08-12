<?php

namespace App\Models;

use App\Enums\RoomStatus;
use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Model;

/** A race lobby: shared text, status, and countdown/start timestamps. */
class Room extends Model
{
    // Action monitoring: logs room create/update/delete (binafy/laravel-user-monitoring).
    use Actionable;

    protected $fillable = ['code', 'host_id', 'status', 'text_to_type', 'language', 'countdown_started_at', 'race_starts_at'];

    // Time columns MUST be cast to datetime so they are always Carbon objects, not
    // strings. Without this, after refresh()/query they come back as strings and
    // ->copy()/->diffInSeconds() fail ("Call to a member function copy() on string").
    protected $casts = [
        // waiting | racing | finished. Cast so a comparison against a value that isn't one of
        // the three is a hard error instead of a branch that silently never runs -- these were
        // the most-repeated literals in the codebase, in its busiest path.
        'status' => RoomStatus::class,
        'countdown_started_at' => 'datetime',
        'race_starts_at' => 'datetime',
    ];

    /** The user who created and owns the room. */
    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** All members currently in the room (players + spectators). */
    public function members()
    {
        return $this->hasMany(RoomMember::class, 'room_id');
    }

    /** Members racing: they count toward the standings, placement, and XP. */
    public function players()
    {
        return $this->members()->where('role', RoomMember::ROLE_PLAYER);
    }

    /** Members only watching: excluded from finish/placement/XP calculations. */
    public function spectators()
    {
        return $this->members()->where('role', RoomMember::ROLE_SPECTATOR);
    }
}
