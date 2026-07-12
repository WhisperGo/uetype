<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-message "delete for me" marker: hides a message from this user only; the
 * original row stays intact for everyone else.
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

    /** firstOrCreate: idempoten, klik dua kali tak error/menumpuk. */
    public static function hide(int $userId, int $messageId): void
    {
        static::firstOrCreate([
            'user_id' => $userId,
            'message_id' => $messageId,
        ]);
    }
}
