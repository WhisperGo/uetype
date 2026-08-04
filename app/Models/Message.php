<?php

namespace App\Models;

use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** A chat message (direct or clan), with an edit window and soft delete/clear. */
class Message extends Model
{
    // Action monitoring: logs message create/update/delete (binafy/laravel-user-monitoring).
    // on_read is disabled in config so bulk reads (chat pagination) don't flood the log.
    use Actionable;

    /** How long after sending a message may still be edited (minutes). */
    public const EDIT_WINDOW_MINUTES = 30;

    /**
     * Longest body a message may carry, for sends AND edits, through every door.
     *
     * One definition because there are three call sites (both send paths in
     * ManagesChatConversation and the validation rule in ChatController), and a limit written
     * three times is a limit that can move in two of them unnoticed.
     */
    public const MAX_BODY_LENGTH = 2000;

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
        // Chat body is encrypted at rest (Laravel's 'encrypted' cast, keyed by APP_KEY):
        // stored ciphertext in the DB, transparently decrypted on read. Safe here because
        // nothing queries the body in SQL (no LIKE/where/search) -- encryption would break
        // that, but there is none to break. Room chat isn't affected: it's broadcast-only and
        // never persisted (see RoomMessageSent).
        'body' => 'encrypted',
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

    /**
     * The LAST message from each conversation partner at once, mapped partner_id => Message.
     *
     * Replaces calling between()->visibleTo()->latest()->first() inside a friend-list
     * loop. That loop fired THREE queries per friend -- last message, the MessageClear
     * lookup inside scopeVisibleTo(), and the unread count -- so a 30-friend inbox needed
     * ~90 queries just to render one list.
     *
     * Visibility rules are kept identical to scopeVisibleTo():
     *   - messages this user "deleted for me" are hidden (the row stays for the partner),
     *   - messages before a per-conversation "clear chat" are hidden.
     * The only difference: both are done in ONE query instead of per row.
     *
     * @param  array<int, int>  $partnerIds
     * @return Collection<int, self>
     */
    public static function lastPerConversation(int $meId, array $partnerIds): Collection
    {
        $partnerIds = array_values(array_unique(array_map('intval', $partnerIds)));

        if (empty($partnerIds)) {
            return collect();
        }

        // The partner is whoever isn't me. Used both to group and to match the
        // message_clears row for that conversation.
        $partner = 'CASE WHEN messages.sender_id = ? THEN messages.recipient_id ELSE messages.sender_id END';

        // IDs are auto-increment and messages are append-only, so MAX(id) = newest. This
        // matches the ordering the old code used (latest('id')).
        $rows = DB::select(
            "SELECT {$partner} AS partner_id, MAX(messages.id) AS last_id
               FROM messages
               LEFT JOIN message_clears mc
                      ON mc.user_id = ?
                     AND mc.other_user_id = {$partner}
              WHERE messages.clan_id IS NULL
                AND (messages.sender_id = ? OR messages.recipient_id = ?)
                AND NOT EXISTS (
                      SELECT 1 FROM message_deletes md
                       WHERE md.message_id = messages.id
                         AND md.user_id = ?
                    )
                AND (mc.cleared_before IS NULL OR messages.created_at > mc.cleared_before)
              GROUP BY partner_id",
            [$meId, $meId, $meId, $meId, $meId, $meId],
        );

        $lastIds = array_column($rows, 'last_id');

        if (empty($lastIds)) {
            return collect();
        }

        return static::with('sender')
            ->whereIn('id', $lastIds)
            ->get()
            ->keyBy(fn (self $m) => $m->sender_id === $meId ? $m->recipient_id : $m->sender_id);
    }

    /**
     * Unread message count from EACH sender at once (sender_id => count).
     * Senders with no unread messages are absent from the map.
     *
     * @param  array<int, int>  $senderIds
     * @return Collection<int, int>
     */
    public static function unreadCountsFrom(int $meId, array $senderIds): Collection
    {
        $senderIds = array_values(array_unique(array_map('intval', $senderIds)));

        if (empty($senderIds)) {
            return collect();
        }

        return static::query()
            ->where('recipient_id', $meId)
            ->whereIn('sender_id', $senderIds)
            ->whereNull('read_at')
            ->groupBy('sender_id')
            ->selectRaw('sender_id, COUNT(*) as total')
            ->pluck('total', 'sender_id');
    }

    /** Scope: hide messages this user cleared/deleted-for-me (rows stay for others). */
    public function scopeVisibleTo($query, int $userId, ?int $otherUserId = null, ?int $clanId = null)
    {
        // Per-message "delete for me": hide from this user only, keep it for everyone else.
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
