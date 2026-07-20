<?php

namespace App\Support;

/**
 * Satu-satunya daftar tujuan navigasi.
 *
 * Sebelumnya tiap menu ditulis dua kali di navigation.blade.php -- sekali untuk
 * desktop, sekali untuk mobile -- lengkap dengan ekspresi `:active` yang harus
 * disinkronkan manual. Duplikasinya sudah menyimpang: leaderboard memakai
 * `route('leaderboard')` di desktop tapi `url('/leaderboard')` di mobile, dan
 * menu akun di dropdown desktop kehilangan state aktifnya sama sekali.
 *
 * Label dibiarkan sebagai KUNCI terjemahan, bukan string jadi: kelas ini bisa
 * dipanggil sebelum locale ter-resolve, dan view yang memutuskan kapan __()
 * dijalankan.
 */
class NavItems
{
    /**
     * Menu utama (bilah kiri desktop / blok atas mobile).
     *
     * @return list<array{key:string, label:string, href:string, active:bool, auth:bool}>
     */
    public static function main(): array
    {
        return [
            [
                'key' => 'solo',
                'label' => 'nav.solo',
                'href' => route('typing'),
                'active' => request()->routeIs('typing'),
                'auth' => false,
            ],
            [
                'key' => 'multiplayer',
                'label' => 'nav.multiplayer',
                'href' => route('multiplayer.lobby'),
                'active' => request()->routeIs('multiplayer.lobby'),
                'auth' => true,
            ],
            [
                'key' => 'klan',
                'label' => 'nav.klan',
                'href' => route('clans.index'),
                // Clan war dianggap bagian dari Klan, jadi menunya tetap menyala.
                'active' => request()->routeIs('clans.index', 'clan-war.index'),
                'auth' => true,
            ],
            [
                'key' => 'leaderboard',
                'label' => 'nav.leaderboard',
                'href' => route('leaderboard'),
                'active' => request()->routeIs('leaderboard'),
                'auth' => true,
            ],
        ];
    }

    /**
     * Menu akun (dropdown desktop / blok bawah mobile).
     *
     * @return list<array{key:string, label:string, href:string, active:bool}>
     */
    public static function account(): array
    {
        $routes = [
            'profile' => ['nav.profile', 'profile.me'],
            'achievements' => ['nav.achievements', 'achievements.index'],
            'stats' => ['nav.user_stats', 'stats'],
            'friends' => ['nav.friends', 'friends.index'],
            'chat' => ['nav.chat', 'chat.index'],
            'settings' => ['nav.settings', 'settings'],
        ];

        return collect($routes)
            ->map(fn (array $item, string $key) => [
                'key' => $key,
                'label' => $item[0],
                'href' => route($item[1]),
                'active' => request()->routeIs($item[1]),
            ])
            ->values()
            ->all();
    }
}
