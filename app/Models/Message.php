<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    // Batas waktu edit pesan setelah dikirim (menit).
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

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * Pesan yang dibalas oleh pesan ini (null kalau bukan reply, atau kalau
     * pesan aslinya sudah dihapus dari DB).
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'reply_to_id');
    }

    public function isClanMessage(): bool
    {
        return $this->clan_id !== null;
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isDeletedForEveryone(): bool
    {
        return $this->deleted_for_everyone_at !== null;
    }

    /**
     * Boleh diedit hanya oleh pengirim, jika belum dihapus-untuk-semua, dan
     * masih dalam jendela EDIT_WINDOW_MINUTES menit sejak dikirim.
     */
    public function canBeEditedBy(int $userId): bool
    {
        return $this->sender_id === $userId
            && ! $this->isDeletedForEveryone()
            && $this->created_at->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
    }

    /**
     * "Delete for everyone" hanya boleh oleh pengirim & belum dihapus.
     */
    public function canBeDeletedForEveryoneBy(int $userId): bool
    {
        return $this->sender_id === $userId && ! $this->isDeletedForEveryone();
    }

    /**
     * Scope: semua pesan DM antara dua user (kedua arah). Dipakai baik
     * untuk daftar percakapan (ambil pesan terakhir) maupun riwayat penuh.
     */
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

    /**
     * Scope: semua pesan chat clan tertentu.
     */
    public function scopeInClan($query, int $clanId)
    {
        return $query->where('clan_id', $clanId);
    }

    /**
     * Scope: sembunyikan pesan yang sudah di-clear oleh $userId (baik lewat
     * clear DM dgn $otherUserId maupun clear chat clan $clanId) -- pesan
     * created_at <= cleared_before tak akan muncul untuk user ini, tapi
     * baris pesannya sendiri TETAP ada di DB utuh untuk partisipan lain.
     */
    public function scopeVisibleTo($query, int $userId, ?int $otherUserId = null, ?int $clanId = null)
    {
        // "Delete for me" per-pesan: sembunyikan pesan yang user ini hapus
        // untuk dirinya sendiri (tetap ada untuk orang lain).
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
