<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A player's claim on one mode slot in a clan war and its contributed result. */
class ClanWarModeClaim extends Model
{
    protected $fillable = [
        'clan_war_id',
        'clan_id',
        'user_id',
        'mode',
        'mode_config',
        'typing_result_id',
        'points',
        'claimed_at',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'claimed_at' => 'datetime',
    ];

    /** The war this claim is part of. */
    public function war(): BelongsTo
    {
        return $this->belongsTo(ClanWar::class, 'clan_war_id');
    }

    /** The clan the claimant plays for. */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /** The player who made the claim. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The typing result submitted for this claim (null until played). */
    public function typingResult(): BelongsTo
    {
        return $this->belongsTo(TypingResult::class);
    }

    /** Whether the claim is played; null result = locked but not yet done (cancellable). */
    public function isSubmitted(): bool
    {
        return $this->typing_result_id !== null;
    }
}
