<?php

namespace App\Http\Controllers;

use App\Models\TypingResult;
use App\Models\User;
use App\Support\BackLink;
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

        // array_merge, NOT `+`: `+` keeps the left side's value for an existing key, so the
        // payload's default backUrl would silently win.
        //
        // Profiles are reached from five places (clan roster, clan detail, friends, chat,
        // leaderboard), so the arrow follows the Referer -- see App\Support\BackLink for why
        // that beats a `?from=` param and which guards it applies.
        //
        // /users/ is excluded because hopping profile to profile would point the arrow at the
        // profile just left instead of the list the visitor started from.
        return view('profile.show', array_merge(
            $this->profilePayload($user, public: true),
            ['backUrl' => BackLink::from($request, route('friends.index'), ['/users/'])],
        ));
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
        $results = TypingResult::where('user_id', $user->id);

        if ($public) {
            $results->trustworthy();
        }

        $agg = $results
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
            // Private profile only uses this to explain withheld results. Public viewers must
            // not learn that another player's session is under integrity probation.
            'pending_results' => $public
                ? 0
                : TypingResult::where('user_id', $user->id)->pendingVerification()->count(),
            'has_speed_verification' => ! $public && TypingResult::query()
                ->where('user_id', $user->id)
                ->whereIn('mode', ['time', 'words'])
                ->pendingVerification()
                ->whereIn('review_reason', ['no_history_high', 'longitudinal_spike'])
                ->exists(),
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
            // Resolved HERE rather than read off $user in the view. The view is shared between
            // the private and public profile, so `@if(! $isPublic) {{ $user->email }}` put the
            // only boundary protecting a private field inside presentation -- correct today, and
            // one careless edit outside that guard away from not being. Null on the public
            // branch means the address never enters that render at all.
            'email' => $public ? null : $user->email,
            // Always defined so the view never has to guard it; show() overrides it with
            // the real origin. The private profile has no back arrow, so it goes unused.
            'backUrl' => route('friends.index'),
        ];
    }
}
