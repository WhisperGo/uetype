<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A typing language (e.g. English, Indonesian) that texts belong to.
 */
class Language extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
    ];

    public function texts(): HasMany
    {
        return $this->hasMany(Text::class);
    }
}
