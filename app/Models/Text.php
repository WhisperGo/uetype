<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Text extends Model
{
    protected $fillable = [
        'language_id',
        'content',
        'mode',
        'difficulty',
        'source_name',
    ];

    public function language()
    {
        return $this->belongsTo(Language::class);
    }

    public function matches()
    {
        return $this->hasMany(Matches::class);
    }
}
