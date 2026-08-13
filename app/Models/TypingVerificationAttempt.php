<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TypingVerificationAttempt extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'source_result_id',
        'language',
        'token_hash',
        'challenge_text',
        'text_hash',
        'status',
        'started_at',
        'input_started_at',
        'expires_at',
        'consumed_at',
        'result_meta',
    ];

    protected $hidden = [
        'token_hash',
        'challenge_text',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'input_started_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'result_meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceResult(): BelongsTo
    {
        return $this->belongsTo(TypingResult::class, 'source_result_id');
    }
}
