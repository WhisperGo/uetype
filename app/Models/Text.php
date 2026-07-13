<?php

namespace App\Models;

use App\Enums\Difficulty;
use App\Enums\TextMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A piece of typing source material: content, language, mode, difficulty. */
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

    /** The language this text is written in. */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    /** Matches raced on this text. */
    public function matches(): HasMany
    {
        return $this->hasMany(Matches::class);
    }

    /** Solo typing results recorded against this text. */
    public function typingResults(): HasMany
    {
        return $this->hasMany(TypingResult::class);
    }
}
