<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\FriendshipStatus;
use App\Enums\TypingMode;
use App\Events\PresenceUpdated;
use App\Support\SafeBroadcast;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/** A user account: identity, progression, social graph, and presence. */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'google_id',
        'email',
        'username',
        'avatar',
        'highest_wpm',
        'total_xp',
        'is_admin',
        'preferences',
        'last_seen_at',
    ];

    protected $hidden = [
        'remember_token',
    ];

    /**
     * There is no `password` column here: identity rests entirely on google_id.
     *
     * Returned as '' rather than the null Eloquent would give, because
     * SessionGuard::queueRecallerCookie() feeds this straight into hash_hmac(), and a null
     * $data is deprecated on PHP 8.1+ -- that would be one deprecation on every single
     * sign-in now that remember-me is on. The value is never read back: a recaller is
     * validated on id + remember_token alone (EloquentUserProvider::retrieveByToken), so ''
     * costs nothing.
     */
    public function getAuthPassword(): string
    {
        return '';
    }

    // Property form, matching the other eleven models. Both forms work in Laravel 12 and
    // neither is deprecated; what costs the next reader time is the project answering the
    // question two different ways, and this file was the only one answering it the other way.
    protected $casts = [
        'highest_wpm' => 'decimal:2',
        'is_admin' => 'boolean',
        'preferences' => 'array',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Base of the progressive level curve (Level/EXP requirement part 2, Option B):
     * EXP to go from level L to L+1 = BASE × L.
     * So cumulative EXP to REACH level N = BASE × (1+2+...+(N-1)) = BASE × N(N-1)/2.
     * This number can be tuned; it's the pattern (progressive) that's fixed.
     */
    public const LEVEL_BASE = 100;

    /**
     * Cumulative EXP needed to REACH $level (levels start at 1). Level 1 = 0 EXP.
     * A pure function of total_xp -- no level is stored.
     */
    public static function xpToReachLevel(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }

        return (int) (self::LEVEL_BASE * ($level * ($level - 1)) / 2);
    }

    /**
     * Compute level from total EXP. total_xp is the SINGLE source of truth;
     * level is always derived from it (Level/EXP requirement part 3).
     */
    public static function levelForXp(int $xp): int
    {
        // Closed form inverting N(N-1)/2 × BASE ≤ xp → N = floor((1 + sqrt(1 + 8xp/BASE)) / 2).
        $level = (int) floor((1 + sqrt(1 + (8 * max(0, $xp)) / self::LEVEL_BASE)) / 2);

        return max(1, $level);
    }

    /**
     * Level data for display (one source, used by profile/navigation/leaderboard).
     * Returns the current level, EXP progress within this level, and EXP needed to
     * reach the next level -- all derived from total_xp.
     *
     * @return array{level:int, total_xp:int, progress:int, needed:int, next_level:int}
     */
    public function levelData(): array
    {
        $xp = (int) ($this->total_xp ?? 0);
        $level = self::levelForXp($xp);

        $floor = self::xpToReachLevel($level);       // EXP to reach this level
        $ceil = self::xpToReachLevel($level + 1);     // EXP for the next level

        return [
            'level' => $level,
            'total_xp' => $xp,
            'progress' => $xp - $floor,               // 0 .. needed
            'needed' => $ceil - $floor,               // EXP span of this level (= BASE × level)
            'next_level' => $level + 1,
        ];
    }

    /**
     * Volume-based EXP formula plus a thin accuracy bonus. The SINGLE source of truth
     * used by both solo mode (TypingEngine) AND multiplayer (MultiplayerLobby) so they
     * stay consistent. Accumulates into total_xp, saves, and returns the EXP earned.
     *
     * Volume-based (count of correct chars) -- DELIBERATELY not WPM-based: it rewards
     * effort/practice, not talent, and doesn't penalize slow typists.
     */
    public function addExp(int $correctChars, float $accuracy): int
    {
        $accuracyMultiplier = 0.5 + 0.5 * (max(0, min(100, $accuracy)) / 100);
        $xpEarned = (int) round(max(0, $correctChars) * 0.1 * $accuracyMultiplier);

        // increment(), NOT `$this->total_xp += ...; save()`. The latter reads the old
        // value into PHP memory then writes the whole thing back, so two writers with
        // different instances (a solo result and race finalization both call this
        // method) would overwrite each other and lose EXP silently. increment() defers
        // the addition to the DB: `total_xp = total_xp + ?`.
        //
        // increment() also refreshes the attribute on this instance, so a levelData()
        // read by the result panel right after this call sees the new value, not a
        // stale one.
        $this->increment('total_xp', $xpEarned);

        return $xpEarned;
    }

    /**
     * Raise `highest_wpm` if this result earns it. Returns whether the record moved.
     *
     * THE definition of "does this result count as a personal best", in one place. It used to
     * live in two -- TypingEngine::saveResult() when a run is first saved, and
     * ReviewQueue::approve() when a held run is later cleared -- as two copies of the same
     * three conditions that had to stay identical forever. They are not obviously connected in
     * the code, and the failure mode is silent: change the rule on one side (count survival,
     * move the threshold, add a mode) and the other keeps the old answer. Nobody sees a wrong
     * PB until a player says so.
     *
     * The three conditions, and why each is there:
     *   - survival is excluded: it is achieved under stamina pressure, and its board metric is
     *     duration, not WPM -- the two are not comparable.
     *   - only a cleared result counts: a run held for anti-cheat review must not put a
     *     flagged number on the profile before a human has looked at it. Approving one calls
     *     this method again, which is how it eventually lands.
     *   - and it must actually beat the current record.
     */
    public function recordPersonalBest(TypingResult $result): bool
    {
        $mode = $result->mode instanceof TypingMode ? $result->mode->value : (string) $result->mode;

        $counts = $mode !== 'survival'
            && in_array($result->review_status, [TypingResult::REVIEW_CLEAR, TypingResult::REVIEW_APPROVED], true)
            && (float) $result->net_wpm > (float) $this->highest_wpm;

        if (! $counts) {
            return false;
        }

        $this->highest_wpm = $result->net_wpm;
        $this->save();

        return true;
    }

    /** Set a single key in the JSON preferences column and persist. */
    public function setPreference(string $key, mixed $value): void
    {
        $preferences = $this->preferences ?? [];
        $preferences[$key] = $value;
        $this->preferences = $preferences;
        $this->save();
    }

    /** Solo typing session results recorded by this user. */
    public function typingResults(): HasMany
    {
        return $this->hasMany(TypingResult::class);
    }

    public function typingSpeedCapabilities(): HasMany
    {
        return $this->hasMany(TypingSpeedCapability::class);
    }

    public function typingVerificationAttempts(): HasMany
    {
        return $this->hasMany(TypingVerificationAttempt::class);
    }

    /** Friend requests this user sent (as requester). */
    public function sentFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'requester_id');
    }

    /** Friend requests this user received (as addressee). */
    public function receivedFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'addressee_id');
    }

    /** Achievements this user has unlocked. */
    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }

    /** The room this user is currently in, through their room_members row. */
    public function currentRoom()
    {
        return $this->hasOneThrough(Room::class, RoomMember::class, 'user_id', 'id', 'id', 'room_id');
    }

    /**
     * Find the friendship row between this user and $otherId in either direction
     * (whether this user is the requester or the addressee). Null if no relation yet.
     */
    public function friendshipWith(int $otherId): ?Friendship
    {
        return Friendship::query()
            ->where(function ($q) use ($otherId) {
                $q->where('requester_id', $this->id)->where('addressee_id', $otherId);
            })
            ->orWhere(function ($q) use ($otherId) {
                $q->where('requester_id', $otherId)->where('addressee_id', $this->id);
            })
            ->first();
    }

    /**
     * Threshold (seconds) within which a user is still considered online. The client
     * heartbeat fires every ~30 seconds; a 60-second threshold tolerates one missed
     * heartbeat before treating the user as offline. Easy to tune.
     */
    public const ONLINE_THRESHOLD_SECONDS = 60;

    /**
     * Whether the user is currently online: last_seen_at is set AND still within the
     * threshold. Derived purely from the timestamp -- no stored boolean flag that could
     * get "stuck" at true when the browser closes without an offline event.
     */
    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_THRESHOLD_SECONDS));
    }

    /**
     * Record that this user was just active (called from the heartbeat endpoint).
     * If this is an offline->online transition, broadcast PresenceUpdated to all
     * friends so their friend-list status dot lights up in real time. Subsequent
     * heartbeats (while already online) only update the timestamp without broadcasting,
     * avoiding a flood of WebSocket messages every ~30 seconds.
     */
    public function touchPresence(): void
    {
        $wasOnline = $this->isOnline();

        $this->forceFill(['last_seen_at' => now()])->save();

        if (! $wasOnline) {
            $this->broadcastPresenceToFriends();
        }
    }

    /**
     * Mark the user offline immediately (called on logout) by clearing last_seen_at,
     * then broadcast so friends see the offline status at once without waiting for the
     * threshold to expire.
     */
    public function markOffline(): void
    {
        $wasOnline = $this->isOnline();

        $this->forceFill(['last_seen_at' => null])->save();

        if ($wasOnline) {
            $this->broadcastPresenceToFriends();
        }
    }

    /**
     * Broadcast the status change to the friends.{id} channel of EVERY accepted friend.
     * Rides on the existing toast/refresh infrastructure.
     */
    private function broadcastPresenceToFriends(): void
    {
        Friendship::query()
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $this->id)->orWhere('addressee_id', $this->id))
            ->get()
            ->each(function (Friendship $f) {
                $friendId = $f->requester_id === $this->id ? $f->addressee_id : $f->requester_id;
                SafeBroadcast::run(fn () => broadcast(new PresenceUpdated($friendId)));
            });
    }

    /**
     * This user's ACTIVE clan membership row (not a still-pending one). There is no
     * clan_id column on users -- membership is derived through the clan_members pivot,
     * following the currentRoom() pattern above.
     */
    public function activeClanMembership(): HasOne
    {
        return $this->hasOne(ClanMember::class)
            ->where('status', ClanMemberStatus::Active);
    }

    /**
     * This user's active clan, through the clan_members pivot.
     *
     * A REAL relation, not an accessor. This used to be `getClanAttribute()` calling a
     * plain `clanMembership()` method, which had two bad consequences:
     *
     *  1. NO CACHING. Every read of `$user->clan` (or `$user->clan_role`) re-ran the
     *     query. layouts/app.blade.php reads it twice in the global layout -> 4 extra
     *     queries on EVERY page of the site.
     *  2. NOT EAGER-LOADABLE. `User::with('clan')` would blow up, because Eloquent
     *     requires a Relation instance. So any user list showing the clan was
     *     automatically N+1, with no way to avoid it.
     *
     * As a relation, Eloquent caches the result in $relations after the first access
     * (so repeated reads = one query) AND `with('clan')` works.
     */
    public function clan(): HasOneThrough
    {
        return $this->hasOneThrough(
            Clan::class,
            ClanMember::class,
            'user_id',   // FK on clan_members -> users
            'id',        // PK on clans
            'id',        // local PK on users
            'clan_id',   // FK on clan_members -> clans
        )->where('clan_members.status', ClanMemberStatus::Active);
    }

    /**
     * Accessor so $user->clan_role can be used as-is in the profile view without a
     * stored column -- derived from the role on clan_members. Reads via the
     * activeClanMembership relation, so it's cached too.
     */
    public function getClanRoleAttribute(): ?string
    {
        return $this->activeClanMembership?->role?->value;
    }
}
