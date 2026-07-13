<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-user "clear chat" marker: soft-hides history before a timestamp. */
class MessageClear extends Model
{
    protected $fillable = [
        'user_id',
        'other_user_id',
        'clan_id',
        'cleared_before',
    ];

    protected $casts = [
        'cleared_before' => 'datetime',
    ];

    /** The user who cleared the chat. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The other party of the cleared DM conversation. */
    public function otherUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'other_user_id');
    }

    /** The clan whose channel was cleared. */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /** Clear a DM conversation; repeats just raise cleared_before, never stack rows. */
    public static function clearDm(int $userId, int $otherUserId, ?\DateTimeInterface $before = null): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'other_user_id' => $otherUserId],
            ['cleared_before' => $before ?? now()]
        );
    }

    /** Clear a clan channel; repeats just raise cleared_before, never stack rows. */
    public static function clearClan(int $userId, int $clanId, ?\DateTimeInterface $before = null): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'clan_id' => $clanId],
            ['cleared_before' => $before ?? now()]
        );
    }
}
