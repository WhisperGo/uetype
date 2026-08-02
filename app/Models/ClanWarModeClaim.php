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
        'attempt_started_at',
        'attempt_text',
        'attempt_carried_ms',
        'attempt_carried_correct_chars',
        'attempt_carried_total_chars',
        'attempt_live_ms',
        'attempt_live_correct_chars',
        'attempt_live_total_chars',
        'attempt_chars',
        'typing_result_id',
        'points',
        'claimed_at',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'claimed_at' => 'datetime',
        'attempt_started_at' => 'datetime',
        'attempt_carried_ms' => 'integer',
        'attempt_carried_correct_chars' => 'integer',
        'attempt_carried_total_chars' => 'integer',
        'attempt_live_ms' => 'integer',
        'attempt_live_correct_chars' => 'integer',
        'attempt_live_total_chars' => 'integer',
        'attempt_chars' => 'integer',
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

    /** Whether the claim is played; null result = locked but not yet done. */
    public function isSubmitted(): bool
    {
        return $this->typing_result_id !== null;
    }

    /**
     * Whether the one attempt this claim is worth has been opened.
     *
     * The dividing line for cancelling: a RESERVED slot (claimed, never opened) may be handed
     * back, but an OPENED one is spent whatever it produced. Without that rule, cancel and
     * re-claim is a third way to restart -- and in Words mode, where the text is frozen and
     * identical for everyone on that config, it is unlimited practice on a memorised paper.
     */
    public function attemptStarted(): bool
    {
        return $this->attempt_started_at !== null;
    }

    /** Opened but not yet submitted: somebody is (or was) mid-attempt on this slot. */
    public function isInFlight(): bool
    {
        return $this->attemptStarted() && ! $this->isSubmitted();
    }
}
