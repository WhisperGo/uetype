<?php

namespace App\Http\Controllers;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
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

        return view('profile.show', $this->profilePayload($user, public: true));
    }

    /**
     * Build a profile's display payload; $public toggles whether private fields are included.
     *
     * @return array<string, mixed>
     */
    private function profilePayload(User $user, bool $public = false): array
    {
        // Profil fokus identitas: cuma ringkasan. Grafik, rekor per mode, & aktivitas
        // lengkap ada di halaman /stats (App\Livewire\Stats).
        //
        // Empat agregat atas tabel & filter yang sama -> satu query, bukan empat.
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

        // Level diturunkan dari total_xp lewat satu sumber kebenaran (User::levelData()).
        $levelData = $user->levelData();
        $stats['level'] = $levelData['level'];
        $stats['level_progress'] = $levelData['progress']; // EXP di dalam level ini
        $stats['level_needed'] = $levelData['needed'];     // EXP rentang menuju level berikutnya

        return [
            'user' => $user,
            'stats' => $stats,
            'isPublic' => $public,
        ];
    }
}
