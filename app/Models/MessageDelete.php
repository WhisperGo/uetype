<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Penanda "delete for me" per-pesan. Lihat migration create_message_deletes_table:
 * menyembunyikan satu pesan HANYA dari user ini, baris pesan aslinya tetap ada
 * untuk semua orang lain.
 */
class MessageDelete extends Model
{
    protected $fillable = [
        'user_id',
        'message_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Sembunyikan pesan dari user ini saja. firstOrCreate supaya idempoten
     * (klik dua kali tak error / tak menumpuk baris).
     */
    public static function hide(int $userId, int $messageId): void
    {
        static::firstOrCreate([
            'user_id' => $userId,
            'message_id' => $messageId,
        ]);
    }
}
