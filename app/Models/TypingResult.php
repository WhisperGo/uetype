<?php

namespace App\Models;

use App\Enums\TypingMode;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A saved solo session result (net/raw wpm, accuracy, mode); write-once. */
class TypingResult extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'mode',
        'mode_config',
        'language',
        'net_wpm',
        'raw_wpm',
        'accuracy',
        'correct_chars',
        'incorrect_chars',
        'duration_seconds',
        'score',
        'xp_earned',
        'ghost_data',
        'review_status',
        'review_reason',
    ];

    // Review states for the anti-cheat queue (§7.5/§7.6). Only CLEAR and APPROVED count for
    // the public leaderboard; PENDING is held for a human; REJECTED was declined.
    public const REVIEW_CLEAR = 'clear';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_APPROVED = 'approved';

    public const REVIEW_REJECTED = 'rejected';

    /**
     * Minimum accumulated typing time (seconds, across all modes) before a player's results
     * are eligible for the public leaderboard -- Monkeytype's `minTimeTyping` gate.
     *
     * This closes the "make account -> run a script -> take rank 1" attack at the door,
     * BEFORE any result is scored: a throwaway account can never reach it, while a real
     * player crosses it naturally. Unlike a WPM ceiling it cannot be paced (there is no
     * number to land just under) and needs no client change -- duration_seconds is already
     * stored on every result. A held-back player's records are NOT lost; they still show on
     * the profile/PB and simply join the global board once the threshold is met.
     *
     * 30 minutes is a deliberately gentle interim for a young player base (raise it as the
     * base grows); it still forces a bot to invest real, validated typing time per account.
     */
    public const LEADERBOARD_MIN_TYPING_SECONDS = 1800;

    protected $casts = [
        'mode' => TypingMode::class,
        'net_wpm' => 'decimal:2',
        'raw_wpm' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'ghost_data' => 'array',
    ];

    /** The player who recorded this result. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Scope: results created today. */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', '>=', Carbon::today());
    }

    /**
     * The best Net WPM this user reached in one mode+config bucket, or null if they have
     * no result there at all (which callers read as "no record yet", not as zero).
     *
     * THE definition of "personal record" for the standard modes, and it lives here
     * because three unrelated callers ask the same question: the result screen (is this a
     * PB?), GhostResolver (what pace should the ghost run?), and GhostPicker (what does
     * "My Best" show?). It used to be the identical three-`where`-plus-`max` chain copied
     * into each of them, so changing what counts as a record meant editing three files and
     * hoping none was missed.
     *
     * Scoped by mode+config on purpose: short tests always score higher, so a `time 120`
     * run measured against a `time 15` sprint is not a comparison. See
     * docs/features/typing-engine.md §3.4.a.
     */
    public static function bestNetWpmFor(int $userId, string $mode, string $config): ?float
    {
        $best = static::query()
            ->where('user_id', $userId)
            ->where('mode', $mode)
            ->where('mode_config', $config)
            ->max('net_wpm');

        return $best === null ? null : (float) $best;
    }
}
