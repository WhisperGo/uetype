<?php

namespace App\Support;

use Illuminate\Support\Facades\Auth;

/**
 * The single source of truth for navigation destinations.
 *
 * Previously each menu item was written twice in navigation.blade.php -- once for
 * desktop, once for mobile -- each with its own `:active` expression that had to be
 * kept in sync by hand. The duplicates had already drifted: leaderboard used
 * `route('leaderboard')` on desktop but `url('/leaderboard')` on mobile, and the
 * account menu in the desktop dropdown lost its active state entirely.
 *
 * Labels are kept as translation KEYS, not resolved strings: this class can be
 * called before the locale is resolved, and the view decides when __() runs.
 */
class NavItems
{
    /**
     * Main menu (desktop left rail / mobile top block).
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
                // Clan war counts as part of Clan, so the menu item stays highlighted.
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
     * Account menu (desktop dropdown / mobile bottom block).
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

        $items = collect($routes)
            ->map(fn (array $item, string $key) => [
                'key' => $key,
                'label' => $item[0],
                'href' => route($item[1]),
                'active' => request()->routeIs($item[1]),
            ])
            ->values()
            ->all();

        // Admin-only entry point to the monitoring dashboard. Non-admins never see it,
        // and the routes themselves are guarded by EnsureUserIsAdmin -- hiding the link
        // is convenience for admins, not the access control.
        if (Auth::user()?->is_admin) {
            $items[] = [
                'key' => 'monitoring',
                'label' => 'nav.monitoring',
                'href' => route('user-monitoring.visits-monitoring'),
                // Any of the three dashboard tabs lights up this one item.
                'active' => request()->routeIs('user-monitoring.*'),
            ];
        }

        return $items;
    }
}
