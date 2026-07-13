<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-message "delete for me" marker: hides a message from one user only. */
class MessageDelete extends Model
{
    protected $fillable = [
        'user_id',
        'message_id',
    ];

    /** The user who hid the message. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The hidden message. */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** Hide a message for a user; idempotent (double-click never errors/stacks). */
    public static function hide(int $userId, int $messageId): void
    {
        static::firstOrCreate([
            'user_id' => $userId,
            'message_id' => $messageId,
        ]);
    }
}
