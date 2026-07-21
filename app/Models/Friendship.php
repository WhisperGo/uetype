<?php

namespace App\Models;

use App\Enums\FriendshipStatus;
use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/** A directed friend relationship (requester -> addressee) with its status. */
class Friendship extends Model
{
    // Action monitoring: logs friendship create/update/delete (binafy/laravel-user-monitoring).
    use Actionable;

    protected $fillable = [
        'requester_id',
        'addressee_id',
        'status',
    ];

    protected $casts = [
        'status' => FriendshipStatus::class,
    ];

    /** The user who sent the request. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** The user who received the request. */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    /**
     * Request friendship $requesterId -> $addresseeId, but only if no relation exists
     * in EITHER direction. Returns null if one already exists.
     *
     * Single entry point for the three places that used to copy the "check
     * friendshipWith() then create()" pattern themselves (FriendButton, Friends,
     * leaderboard).
     *
     * CAUTION -- this does NOT close the real concurrency window. The DB unique index
     * is only `(requester_id, addressee_id)`, i.e. one-directional: A->B and B->A can
     * coexist. The transaction here serializes the check against other writers on the
     * same connection, but two truly parallel requests can still both get through.
     * Closing that would need a unique index over the normalized pair (LEAST/GREATEST
     * as a generated column), whose syntax differs between MySQL and sqlite -- not yet
     * worth it for the impact: two redundant pending rows, not data corruption.
     */
    public static function requestBetween(int $requesterId, int $addresseeId): ?self
    {
        if ($requesterId === $addresseeId) {
            return null;
        }

        return DB::transaction(function () use ($requesterId, $addresseeId) {
            $existing = static::query()
                ->whereIn('requester_id', [$requesterId, $addresseeId])
                ->whereIn('addressee_id', [$requesterId, $addresseeId])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return null;
            }

            return static::create([
                'requester_id' => $requesterId,
                'addressee_id' => $addresseeId,
                'status' => FriendshipStatus::Pending,
            ]);
        });
    }

    /**
     * $meId's relation to MANY users at once in ONE query, mapped
     * user_id => ['relation', 'friendship_id'].
     *
     * Replaces calling User::friendshipWith() inside a loop (one query per row = N+1).
     * Used by the friend-search list and leaderboard rows, which both need the relation
     * status to pick the right button.
     *
     * relation: 'friends' | 'sent' | 'incoming'. Users with no friendship row are
     * ABSENT from the map -- callers treat a missing key as 'none'.
     *
     * @param  array<int, int>  $otherIds
     * @return array<int, array{relation: string, friendship_id: int}>
     */
    public static function relationMapFor(int $meId, array $otherIds): array
    {
        $otherIds = array_values(array_unique(array_map('intval', $otherIds)));

        if (empty($otherIds)) {
            return [];
        }

        return static::query()
            ->where(fn ($q) => $q->where('requester_id', $meId)->whereIn('addressee_id', $otherIds))
            ->orWhere(fn ($q) => $q->where('addressee_id', $meId)->whereIn('requester_id', $otherIds))
            ->get()
            ->mapWithKeys(function (self $f) use ($meId) {
                $otherId = $f->requester_id === $meId ? $f->addressee_id : $f->requester_id;

                $relation = match (true) {
                    $f->status === FriendshipStatus::Accepted => 'friends',
                    // Pending: the direction decides which button is shown.
                    $f->requester_id === $meId => 'sent',
                    default => 'incoming',
                };

                return [$otherId => ['relation' => $relation, 'friendship_id' => $f->id]];
            })
            ->all();
    }
}
