<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penanda "clear chat" per-user. Lihat migration create_message_clears_table
 * untuk penjelasan lengkap kenapa ini soft-hide (bukan hapus pesan asli).
 */
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function otherUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'other_user_id');
    }

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /**
     * Catat/perbarui penanda clear DM antara $userId & $otherUserId sampai
     * $before (default: sekarang -> efektif "clear semua sejauh ini").
     * updateOrCreate supaya clear berulang menaikkan batas, bukan menumpuk.
     */
    public static function clearDm(int $userId, int $otherUserId, ?\DateTimeInterface $before = null): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'other_user_id' => $otherUserId],
            ['cleared_before' => $before ?? now()]
        );
    }

    public static function clearClan(int $userId, int $clanId, ?\DateTimeInterface $before = null): void
    {
        static::updateOrCreate(
            ['user_id' => $userId, 'clan_id' => $clanId],
            ['cleared_before' => $before ?? now()]
        );
    }
}
