<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Frozen Words-mode text for clan wars (one row per 10/25/50/100 config). Every
 * player sees identical content, closing the refresh-for-easier-text loophole.
 */
class ClanWarFixedText extends Model
{
    protected $fillable = [
        'mode',
        'mode_config',
        'content',
    ];

    /** Null jika belum ada; pemanggil fallback ke generate biasa agar layar tak pernah kosong. */
    public static function forWords(string $config): ?string
    {
        return static::query()
            ->where('mode', 'words')
            ->where('mode_config', $config)
            ->value('content');
    }
}
