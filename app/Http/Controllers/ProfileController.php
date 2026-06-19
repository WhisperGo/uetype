<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use App\Models\MatchParticipant;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        $user = $request->user();

        $recentMatches = MatchParticipant::where('user_id', $user->id)
            ->with('match')
            ->latest()
            ->take(8)
            ->get();

        // Statistik agregat dari seluruh hasil (abaikan yang ditandai mencurigakan utk rata-rata).
        $base = MatchParticipant::where('user_id', $user->id)->where('is_suspicious', false);

        $stats = [
            'total_matches' => (clone $base)->count(),
            'avg_wpm' => round((float) (clone $base)->avg('wpm'), 1),
            'avg_accuracy' => round((float) (clone $base)->avg('accuracy'), 1),
            'best_wpm' => round((float) (clone $base)->max('wpm'), 1),
        ];

        // Total waktu mengetik (detik) dari match terkait.
        $stats['total_seconds'] = (int) MatchParticipant::where('match_participants.user_id', $user->id)
            ->join('matches', 'matches.id', '=', 'match_participants.match_id')
            ->whereNotNull('matches.started_at')
            ->whereNotNull('matches.ended_at')
            ->sum(DB::raw('TIMESTAMPDIFF(SECOND, matches.started_at, matches.ended_at)'));

        // Level sederhana dari XP (tiap level butuh 1000 XP).
        $xp = (int) ($user->xp ?? 0);
        $stats['level'] = intdiv($xp, 1000) + 1;
        $stats['level_progress'] = $xp % 1000; // 0-999 menuju level berikutnya

        // Data grafik progres WPM (urut kronologis, maks 20 match terakhir, hanya yang valid).
        $progress = (clone $base)->latest()->take(20)->get()->reverse()->values();
        $wpmProgress = $progress->pluck('wpm')->map(fn ($v) => (float) $v)->all();

        return view('profile.edit', [
            'user' => $user,
            'recentMatches' => $recentMatches,
            'stats' => $stats,
            'wpmProgress' => $wpmProgress,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
