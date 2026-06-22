<?php

namespace App\Models;

use App\Enums\TypingMode;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TypingResult extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'text_id',
        'mode',
        'mode_config',
        'net_wpm',
        'raw_wpm',
        'accuracy',
        'correct_chars',
        'incorrect_chars',
        'duration_seconds',
        'score',
        'xp_earned',
        'ghost_data',
    ];

    protected $casts = [
        'mode' => TypingMode::class,
        'net_wpm' => 'decimal:2',
        'raw_wpm' => 'decimal:2',
        'accuracy' => 'decimal:2',
        'ghost_data' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function text(): BelongsTo
    {
        return $this->belongsTo(Text::class);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', '>=', Carbon::today());
    }
}
