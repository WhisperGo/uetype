<?php

namespace App\Http\Controllers;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Profil sendiri (privat): menampilkan semua field termasuk email, koin, total XP.
     */
    public function me(Request $request): View
    {
        return view('profile.show', $this->profilePayload($request->user()));
    }

    /**
     * Profil publik milik user lain: tanpa field privat (email, koin, total XP).
     * Kalau membuka profil sendiri lewat rute ini, arahkan ke profil penuh sendiri.
     */
    public function show(Request $request, User $user): View
    {
        if ($request->user() && $request->user()->id === $user->id) {
            return $this->me($request);
        }

        return view('profile.show', $this->profilePayload($user, public: true));
    }

    /**
     * Rakit data agregat sebuah profil dari hasil ketik tersimpan. Dipakai bersama
     * oleh profil sendiri & publik; $public menentukan field privat disertakan atau tidak.
     *
     * @return array<string, mixed>
     */
    private function profilePayload(User $user, bool $public = false): array
    {
        $recentMatches = TypingResult::where('user_id', $user->id)
            ->latest('created_at')
            ->take(8)
            ->get();

        $base = TypingResult::where('user_id', $user->id);

        $stats = [
            'total_matches' => (clone $base)->count(),
            'avg_wpm' => round((float) (clone $base)->avg('net_wpm'), 1),
            'avg_accuracy' => round((float) (clone $base)->avg('accuracy'), 1),
            'best_wpm' => round((float) (clone $base)->max('net_wpm'), 1),
        ];

        $stats['total_seconds'] = (int) (clone $base)->sum('duration_seconds');

        // Level diturunkan dari total_xp lewat satu sumber kebenaran (User::levelData()).
        $levelData = $user->levelData();
        $stats['level'] = $levelData['level'];
        $stats['level_progress'] = $levelData['progress']; // EXP di dalam level ini
        $stats['level_needed'] = $levelData['needed'];     // EXP rentang menuju level berikutnya

        $bestRecords = (clone $base)
            ->select('mode', 'mode_config', DB::raw('MAX(net_wpm) as high_wpm'))
            ->groupBy('mode', 'mode_config')
            ->get();

        // Data grafik progres WPM: urut kronologis, maks 20 sesi terakhir.
        $progress = (clone $base)->latest('created_at')->take(20)->get()->reverse()->values();
        $wpmProgress = $progress->pluck('net_wpm')->map(fn ($v) => (float) $v)->all();

        return [
            'user' => $user,
            'recentMatches' => $recentMatches,
            'stats' => $stats,
            'bestRecords' => $bestRecords,
            'wpmProgress' => $wpmProgress,
            'isPublic' => $public,
        ];
    }
}
