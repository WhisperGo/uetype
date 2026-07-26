<?php

namespace App\Http\Controllers;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves user profile pages, both the owner's private profile and other users'
 * public profiles.
 */
class ProfileController extends Controller
{
    /**
     * The owner's private profile: shows all fields including email, coins, total XP.
     */
    public function me(Request $request): View
    {
        return view('profile.show', $this->profilePayload($request->user()));
    }

    /** Another user's public profile (no private fields); redirects to own if it's you. */
    public function show(Request $request, User $user): View
    {
        if ($request->user() && $request->user()->id === $user->id) {
            return $this->me($request);
        }

        // array_merge, NOT the `+` operator: `+` keeps the LEFT side's value for a key
        // that already exists, so the payload's default backUrl would silently win and
        // every profile would still point at Friends.
        return view('profile.show', array_merge(
            $this->profilePayload($user, public: true),
            ['backUrl' => $this->backUrl($request)],
        ));
    }

    /**
     * Where the profile's back arrow should point: the page the visitor came from.
     *
     * Public profiles are reached from at least five places (clan roster, clan detail,
     * friends, chat, leaderboard), but the arrow used to be hardcoded to Friends -- so
     * opening a clan member's profile and going back dumped you on a page you were never
     * on, losing your place in the roster.
     *
     * Derived from the Referer rather than a `?from=` parameter so no caller has to
     * remember to pass anything, and so a link shared into chat still behaves.
     *
     * Two guards on that header, which is client-controlled:
     *  - it must be a URL on THIS host, or it becomes an open redirect to anywhere;
     *  - it must not be a profile page, or bouncing between two profiles would make the
     *    arrow point at the one you just left instead of the list you started from.
     *
     * Falls back to Friends when there is no usable referer (a direct visit, a bookmark,
     * a privacy-stripped header) so the arrow always leads somewhere sensible.
     */
    private function backUrl(Request $request): string
    {
        $referer = $request->headers->get('referer');
        $fallback = route('friends.index');

        if (! $referer) {
            return $fallback;
        }

        $parts = parse_url($referer);

        if (! isset($parts['host']) || $parts['host'] !== $request->getHost()) {
            return $fallback;
        }

        $path = $parts['path'] ?? '/';

        if (str_starts_with($path, '/users/')) {
            return $fallback;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Build a profile's display payload; $public toggles whether private fields are included.
     *
     * @return array<string, mixed>
     */
    private function profilePayload(User $user, bool $public = false): array
    {
        // Profile is identity-focused: just a summary. Charts, per-mode records, and
        // full activity live on the /stats page (App\Livewire\Stats).
        //
        // Four aggregates over the same table & filter -> one query, not four.
        $agg = TypingResult::where('user_id', $user->id)
            ->selectRaw('
                COUNT(*) as total_matches,
                AVG(net_wpm) as avg_wpm,
                AVG(accuracy) as avg_accuracy,
                COALESCE(SUM(duration_seconds), 0) as total_seconds
            ')
            ->first();

        $stats = [
            'total_matches' => (int) $agg->total_matches,
            'avg_wpm' => round((float) $agg->avg_wpm, 1),
            'avg_accuracy' => round((float) $agg->avg_accuracy, 1),
            'total_seconds' => (int) $agg->total_seconds,
        ];

        // Level is derived from total_xp via a single source of truth (User::levelData()).
        $levelData = $user->levelData();
        $stats['level'] = $levelData['level'];
        $stats['level_progress'] = $levelData['progress']; // EXP within the current level
        $stats['level_needed'] = $levelData['needed'];     // EXP span to the next level

        return [
            'user' => $user,
            'stats' => $stats,
            'isPublic' => $public,
            // Always defined so the view never has to guard it; show() overrides it with
            // the real origin. The private profile has no back arrow, so it goes unused.
            'backUrl' => route('friends.index'),
        ];
    }
}
