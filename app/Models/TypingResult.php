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

    /**
     * Mirror the column's own default onto the model.
     *
     * `review_status` defaults to 'clear' in the schema, but a DB-side default is applied by
     * the DATABASE: a row created without mentioning the column comes back from create() with
     * the attribute still unset, so reading it in PHP yields null until the model is refreshed.
     * Any rule that asks "is this result cleared?" then gets null instead of 'clear' and
     * silently answers no -- which is how a legitimate personal best can fail to register.
     *
     * Declaring the default here makes the model agree with the schema from the moment it is
     * instantiated, so callers never have to know which of the two answered.
     */
    protected $attributes = [
        'review_status' => self::REVIEW_CLEAR,
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
            ->trustworthy()
            ->max('net_wpm');

        return $best === null ? null : (float) $best;
    }

    /**
     * The longest this user survived in one survival difficulty, or null if they have no
     * result there. The survival counterpart to bestNetWpmFor(), and it exists for the same
     * reason: the result screen and the survival board have to agree on what the record is.
     *
     * Survival is measured in duration, not WPM -- it is played under stamina pressure, so
     * how long you lasted is the achievement. That is also why it needs the review gate most:
     * stamina is simulated on the CLIENT, so duration_seconds is the one figure the server
     * cannot recompute, and SurvivalPlausibility holds an implausible one for review rather
     * than refusing it.
     */
    public static function bestSurvivalDurationFor(int $userId, string $config): ?float
    {
        $best = static::query()
            ->where('user_id', $userId)
            ->where('mode', 'survival')
            ->where('mode_config', $config)
            ->trustworthy()
            ->max('duration_seconds');

        return $best === null ? null : (float) $best;
    }

    /**
     * Results that may stand as a public number: cleared automatically, or approved by a human.
     *
     * ONE definition of "this result is allowed to represent the player", because it is asked
     * in more places than is obvious -- the leaderboard, both record helpers above, and (in its
     * own words) User::recordPersonalBest(). It used to be asked only in the first and the last,
     * which is how a run held `pending` stayed off the board and off the profile while still
     * becoming the per-mode record the result screen compared against and the pace a ghost ran
     * at. `rejected` mattered just as much: ReviewQueue::reject() moves the column and leaves
     * the row, so without this the number an admin threw out counted forever.
     */
    public function scopeTrustworthy($query)
    {
        return $query->whereIn('review_status', [self::REVIEW_CLEAR, self::REVIEW_APPROVED]);
    }

    /**
     * Restrict to results belonging to players who have earned a place on the public board:
     * accumulated typing time (all modes) at or over LEADERBOARD_MIN_TYPING_SECONDS.
     *
     * ONE definition, because "who is publicly listed" is now asked by more than the board. The
     * ghost opponent list and the `?ghost=<user_id>` deep link ask it too, and used to answer it
     * differently -- they answered "anyone who has ever typed", which turned the deep link into
     * an id-to-username oracle over accounts no board shows. That is the enumeration keying
     * profiles by username exists to prevent, arriving through a different door.
     *
     * Kept as a subquery on user_id rather than a join so callers can attach it to an already
     * grouped/joined query without disturbing their own shape.
     */
    public function scopeLeaderboardEligible($query)
    {
        return $query->whereIn('user_id', static::query()
            ->select('user_id')
            ->groupBy('user_id')
            ->havingRaw('SUM(duration_seconds) >= ?', [self::LEADERBOARD_MIN_TYPING_SECONDS]));
    }
}
