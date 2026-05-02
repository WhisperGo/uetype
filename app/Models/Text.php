<?php

namespace App\Models;

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
    ];

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(Matches::class);
    }
}
