<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $fillable = [
        'sender_id',
        'recipient_id',
        'clan_id',
        'body',
        'read_at',
    ];

    protected $casts = [
        'read_at' => 'datetime',
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

    public function isClanMessage(): bool
    {
        return $this->clan_id !== null;
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
