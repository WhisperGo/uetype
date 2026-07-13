<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Frozen Words-mode text for clan wars, identical for every player per config. */
class ClanWarFixedText extends Model
{
    protected $fillable = [
        'mode',
        'mode_config',
        'content',
    ];

    /** Frozen content for a Words config; null lets the caller fall back to generation. */
    public static function forWords(string $config): ?string
    {
        return static::query()
            ->where('mode', 'words')
            ->where('mode_config', $config)
            ->value('content');
    }
}
