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
    ];

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
