<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TypingSpeedCapability extends Model
{
    protected $fillable = [
        'user_id',
        'language',
        'verified_wpm',
        'verified_accuracy',
        'verified_at',
        'rule_version',
        'evidence_meta',
    ];

    protected $casts = [
        'verified_wpm' => 'decimal:2',
        'verified_accuracy' => 'decimal:2',
        'verified_at' => 'datetime',
        'evidence_meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
