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

    /**
     * Pesan TERAKHIR dari setiap lawan bicara sekaligus, dipetakan partner_id => Message.
     *
     * Pengganti pemanggilan between()->visibleTo()->latest()->first() di dalam loop
     * daftar teman. Loop itu menembak TIGA query per teman -- pesan terakhir, lookup
     * MessageClear di dalam scopeVisibleTo(), dan hitungan unread -- sehingga inbox
     * dengan 30 teman butuh ~90 query hanya untuk merender satu daftar.
     *
     * Aturan visibilitas dijaga sama persis dengan scopeVisibleTo():
     *   - pesan yang di-"delete for me" oleh user ini disembunyikan (baris tetap ada
     *     untuk lawan bicaranya),
     *   - pesan sebelum "clear chat" per-percakapan disembunyikan.
     * Bedanya cuma: keduanya dikerjakan di dalam SATU query, bukan per baris.
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

        // Lawan bicara = pihak yang bukan saya. Dipakai untuk mengelompokkan DAN
        // untuk mencocokkan baris message_clears milik percakapan itu.
        $partner = 'CASE WHEN messages.sender_id = ? THEN messages.recipient_id ELSE messages.sender_id END';

        // id auto-increment & pesan append-only -> MAX(id) = pesan terbaru. Ini juga
        // urutan yang dipakai kode lama (latest('id')).
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
     * Jumlah pesan belum dibaca dari SETIAP pengirim sekaligus (pengirim => jumlah).
     * Pengirim tanpa pesan belum dibaca tidak muncul di peta.
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
