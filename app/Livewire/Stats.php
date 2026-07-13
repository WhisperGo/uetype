<?php

namespace App\Livewire;

use App\Models\MultiplayerMatchHistory;
use App\Models\TypingResult;
use App\Services\AchievementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Stats page: solo and multiplayer performance aggregates, all derived at
 * render time (no stored aggregate columns). Chart range is kept in the URL.
 */
#[Layout('layouts.app')]
class Stats extends Component
{
    /** Rentang grafik WPM & akurasi: 7 | 30 hari terakhir, atau seluruh riwayat. */
    #[Url(as: 'range')]
    public string $range = '7';

    /** Whitelist rentang; nilai di luar ini dianggap '7'. */
    private const RANGES = ['7', '30', 'all'];

    public function setRange(string $range): void
    {
        $this->range = in_array($range, self::RANGES, true) ? $range : '7';
    }

    /**
     * Rekor WPM tertinggi per mode+config, dipetakan config => WPM.
     * Contoh: words => ['15' => 131.0, '25' => 122.0].
     *
     * @return array<string, float>
     */
    private function bestWpmByConfig(int $userId, string $mode): array
    {
        return TypingResult::where('user_id', $userId)
            ->where('mode', $mode)
            ->whereNotNull('mode_config')
            ->select('mode_config', DB::raw('MAX(net_wpm) as high'))
            ->groupBy('mode_config')
            ->pluck('high', 'mode_config')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Survival dinilai dari LAMA BERTAHAN, bukan WPM — jadi rekornya
     * MAX(duration_seconds) per tingkat kesulitan, bukan MAX(net_wpm).
     *
     * @return array<string, int>
     */
    private function bestSurvivalSeconds(int $userId): array
    {
        return TypingResult::where('user_id', $userId)
            ->where('mode', 'survival')
            ->whereNotNull('mode_config')
            ->select('mode_config', DB::raw('MAX(duration_seconds) as high'))
            ->groupBy('mode_config')
            ->pluck('high', 'mode_config')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Deret grafik (WPM & akurasi) untuk rentang aktif, urut kronologis.
     * Diambil menaik lalu dibatasi agar rentang 'all' tak menarik ribuan baris.
     *
     * @return array{labels: list<string>, wpm: list<float>, accuracy: list<float>}
     */
    private function series(int $userId): array
    {
        $query = TypingResult::where('user_id', $userId);

        if ($this->range !== 'all') {
            $query->where('created_at', '>=', Carbon::now()->subDays((int) $this->range));
        }

        $rows = $query->orderBy('created_at')
            ->take(200)
            ->get(['net_wpm', 'accuracy', 'created_at']);

        return [
            'labels' => $rows->map(fn ($r) => $r->created_at->format('M j'))->all(),
            'wpm' => $rows->map(fn ($r) => (float) $r->net_wpm)->all(),
            'accuracy' => $rows->map(fn ($r) => (float) $r->accuracy)->all(),
        ];
    }

    /**
     * Porsi tiap mode dari total tes, dibulatkan ke persen.
     *
     * Catatan: 'ghost' TIDAK disertakan. Sesi ghost tersimpan sebagai baris
     * 'time'/'words' biasa (TypingEngine tak pernah menulis mode 'ghost'),
     * jadi slice ghost akan selalu 0% dan menyesatkan.
     *
     * @return list<array{mode: string, count: int, percent: int}>
     */
    private function modeDistribution(int $userId): array
    {
        $counts = TypingResult::where('user_id', $userId)
            ->select('mode', DB::raw('COUNT(*) as total'))
            ->groupBy('mode')
            ->pluck('total', 'mode');

        $total = $counts->sum();

        if ($total === 0) {
            return [];
        }

        return $counts
            ->map(fn ($count, $mode) => [
                'mode' => (string) $mode,
                'count' => (int) $count,
                'percent' => (int) round(($count / $total) * 100),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();
    }

    /**
     * Halaman ini hanya memajang achievement yang SUDAH diraih — semuanya, tanpa
     * batas. Yang masih terkunci sengaja tak ditampilkan: daftar lengkap beserta
     * progresnya sudah jadi tugas /achievements.
     *
     * @param  array<int, array{earned: bool}>  $items
     * @return array{earned: array<int, array<string, mixed>>, total: int}
     */
    private function earnedAchievements(array $items): array
    {
        return [
            'earned' => array_values(array_filter($items, fn ($a) => $a['earned'])),
            'total' => count($items),
        ];
    }

    /**
     * Aggregate multiplayer race stats from multiplayer_match_history, the
     * permanent log written by MultiplayerLobby::finalizeRace() (rooms/room_members
     * themselves are deleted once everyone leaves, so this table is the only
     * durable source). Mirrors the solo $activity block: derived at render time,
     * no stored aggregate columns.
     *
     * @return array{total_races: int, wins: int, win_rate: int, avg_wpm: int, best_wpm: int, avg_accuracy: float}
     */
    private function multiplayerStats(int $userId): array
    {
        $base = MultiplayerMatchHistory::where('user_id', $userId);
        $total = (clone $base)->count();

        if ($total === 0) {
            return [
                'total_races' => 0,
                'wins' => 0,
                'win_rate' => 0,
                'avg_wpm' => 0,
                'best_wpm' => 0,
                'avg_accuracy' => 0.0,
            ];
        }

        $wins = (clone $base)->where('place', 1)->count();

        return [
            'total_races' => $total,
            'wins' => $wins,
            'win_rate' => (int) round(($wins / $total) * 100),
            'avg_wpm' => (int) round((float) (clone $base)->avg('wpm')),
            'best_wpm' => (int) (clone $base)->max('wpm'),
            'avg_accuracy' => round((float) (clone $base)->avg('accuracy'), 1),
        ];
    }

    /**
     * Placement breakdown (1st / 2nd / 3rd+) across every recorded race, for the
     * distribution bar on the Multiplayer tab -- same shape as modeDistribution().
     *
     * @return list<array{place: int, count: int, percent: int}>
     */
    private function placementDistribution(int $userId): array
    {
        $counts = MultiplayerMatchHistory::where('user_id', $userId)
            ->select('place', DB::raw('COUNT(*) as total'))
            ->groupBy('place')
            ->pluck('total', 'place');

        $total = $counts->sum();

        if ($total === 0) {
            return [];
        }

        return $counts
            ->map(fn ($count, $place) => [
                'place' => (int) $place,
                'count' => (int) $count,
                'percent' => (int) round(($count / $total) * 100),
            ])
            ->sortBy('place')
            ->values()
            ->all();
    }

    /**
     * Most recent finished races, newest first, for the recent-matches table.
     *
     * @return Collection<int, MultiplayerMatchHistory>
     */
    private function recentMultiplayerMatches(int $userId, int $limit = 10)
    {
        return MultiplayerMatchHistory::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->take($limit)
            ->get();
    }

    public function render(AchievementService $achievements)
    {
        $user = Auth::user();
        $base = TypingResult::where('user_id', $user->id);

        $totalSeconds = (int) (clone $base)->sum('duration_seconds');

        $activity = [
            'total_seconds' => $totalSeconds,
            'total_tests' => (clone $base)->count(),
            'avg_accuracy' => round((float) (clone $base)->avg('accuracy'), 1),
            'avg_wpm' => (int) round((float) (clone $base)->avg('net_wpm')),
            'total_chars' => (int) (clone $base)->sum('correct_chars'),
        ];

        return view('livewire.stats', [
            'user' => $user,
            'levelData' => $user->levelData(),
            'activity' => $activity,
            'bestWords' => $this->bestWpmByConfig($user->id, 'words'),
            'bestTime' => $this->bestWpmByConfig($user->id, 'time'),
            'bestSurvival' => $this->bestSurvivalSeconds($user->id),
            'achievements' => $this->earnedAchievements($achievements->evaluate($user)['items']),
            'series' => $this->series($user->id),
            'distribution' => $this->modeDistribution($user->id),
            'multiplayerStats' => $this->multiplayerStats($user->id),
            'placementDistribution' => $this->placementDistribution($user->id),
            'recentMatches' => $this->recentMultiplayerMatches($user->id),
        ]);
    }
}
