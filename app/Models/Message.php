<?php

namespace App\Models;

use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A chat message (direct or clan), with an edit window and soft delete/clear. */
class Message extends Model
{
    // Action monitoring: log create/update/delete pesan (binafy/laravel-user-monitoring).
    // on_read dimatikan di config supaya baca massal (paginasi chat) tak membanjiri log.
    use Actionable;

    /** How long after sending a message may still be edited (minutes). */
    public const EDIT_WINDOW_MINUTES = 30;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'clan_id',
        'body',
        'reply_to_id',
        'read_at',
        'edited_at',
        'deleted_for_everyone_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
        'edited_at' => 'datetime',
        'deleted_for_everyone_at' => 'datetime',
    ];

    /** The user who sent the message. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** The direct-message recipient (null for clan messages). */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** The clan this message belongs to (null for direct messages). */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /** The message being replied to; null if not a reply or the original is gone. */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    /** Whether this is a clan message rather than a direct message. */
    public function isClanMessage(): bool
    {
        return $this->clan_id !== null;
    }

    /** Whether the message has been edited. */
    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    /** Whether the message was deleted for everyone. */
    public function isDeletedForEveryone(): bool
    {
        return $this->deleted_for_everyone_at !== null;
    }

    /** Editable only by the sender, not deleted-for-all, still within EDIT_WINDOW_MINUTES. */
    public function canBeEditedBy(int $userId): bool
    {
        return $this->sender_id === $userId
            && ! $this->isDeletedForEveryone()
            && $this->created_at->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
    }

    /** "Delete for everyone" is allowed only by the sender and if not already deleted. */
    public function canBeDeletedForEveryoneBy(int $userId): bool
    {
        return $this->sender_id === $userId && ! $this->isDeletedForEveryone();
    }

    /** Scope: all direct messages between two users (both directions). */
    public function scopeBetween($query, int $userA, int $userB)
    {
        return $query->whereNull('clan_id')->where(function ($q) use ($userA, $userB) {
            $q->where(function ($q2) use ($userA, $userB) {
                $q2->where('sender_id', $userA)->where('recipient_id', $userB);
            })->orWhere(function ($q2) use ($userA, $userB) {
                $q2->where('sender_id', $userB)->where('recipient_id', $userA);
            });
        });
    }

    /** Scope: all messages in a given clan channel. */
    public function scopeInClan($query, int $clanId)
    {
        return $query->where('clan_id', $clanId);
    }

    /** Scope: hide messages this user cleared/deleted-for-me (rows stay for others). */
    public function scopeVisibleTo($query, int $userId, ?int $otherUserId = null, ?int $clanId = null)
    {
        // "Delete for me" per-pesan: sembunyikan untuk user ini saja, tetap ada untuk lainnya.
        $query->whereNotExists(function ($sub) use ($userId) {
            $sub->selectRaw('1')
                ->from('message_deletes')
                ->whereColumn('message_deletes.message_id', 'messages.id')
                ->where('message_deletes.user_id', $userId);
        });

        $clearedBefore = MessageClear::query()
            ->where('user_id', $userId)
            ->when($otherUserId, fn ($q) => $q->where('other_user_id', $otherUserId))
            ->when($clanId, fn ($q) => $q->where('clan_id', $clanId))
            ->value('cleared_before');

        if (! $clearedBefore) {
            return $query;
        }

        return $query->where('created_at', '>', $clearedBefore);
    }
}
