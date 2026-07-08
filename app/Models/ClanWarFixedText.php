<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Teks beku mode Words (satu baris per config 10/25/50/100); semua pemain baca konten identik, cegah celah refresh. */
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
