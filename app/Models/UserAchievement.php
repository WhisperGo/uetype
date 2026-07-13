<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Records that a user unlocked a specific achievement; write-once. */
class UserAchievement extends Model
{
    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    protected $fillable = [
        'user_id',
        'achievement_key',
        'unlocked_at',
    ];

    protected $casts = [
        'unlocked_at' => 'datetime',
    ];

    /** The user who unlocked the achievement. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
