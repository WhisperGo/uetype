<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\TextMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Text extends Model
{
    protected $fillable = [
        'language_id',
        'content',
        'mode',
        'difficulty',
        'author',
    ];

    protected $casts = [
        'mode' => TextMode::class,
        'difficulty' => Difficulty::class,
    ];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(Matches::class);
    }

    public function typingResults(): HasMany
    {
        return $this->hasMany(TypingResult::class);
    }
}
