<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's claim on one mode/config slot in a clan war — records who plays
 * which challenge for their clan and their contributed result.
 */
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

    public function war(): BelongsTo
    {
        return $this->belongsTo(ClanWar::class, 'clan_war_id');
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function typingResult(): BelongsTo
    {
        return $this->belongsTo(TypingResult::class);
    }

    /** typing_result_id null = mode terkunci tapi belum dikerjakan (masih bisa dibatalkan). */
    public function isSubmitted(): bool
    {
        return $this->typing_result_id !== null;
    }
}
