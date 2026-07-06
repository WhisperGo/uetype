<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Teks tetap (beku) untuk mode Words di Clan War. Satu baris per config
 * (10/25/50/100). Diisi sekali saat migrasi; semua pemain yang mengerjakan
 * mode Words yang sama membaca konten identik dari sini, bukan meng-generate
 * ulang -- itulah yang menutup celah refresh & menyamakan perbandingan clan.
 */
class ClanWarFixedText extends Model
{
    protected $fillable = [
        'mode',
        'mode_config',
        'content',
    ];

    /**
     * Ambil teks tetap untuk mode Words dengan config tertentu (10/25/50/100).
     * Null jika belum ada (mis. wordlist tak tersedia saat migrasi) -- pemanggil
     * memakai fallback generate biasa supaya layar tak pernah kosong.
     */
    public static function forWords(string $config): ?string
    {
        return static::query()
            ->where('mode', 'words')
            ->where('mode_config', $config)
            ->value('content');
    }
}
