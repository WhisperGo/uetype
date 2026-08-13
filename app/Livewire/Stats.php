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
    /** WPM & accuracy chart range: last 7 | 30 days, or all history. */
    #[Url(as: 'range')]
    public string $range = '7';

    /** Range whitelist; anything outside it is treated as '7'. */
    private const RANGES = ['7', '30', 'all'];

    /**
     * Most data points one chart may carry, so the 'all' range can't pull thousands of rows.
     *
     * Public because the test that guards WHICH end of the history survives the cap has to
     * cross it deliberately -- hard-coding the number there would let the two drift apart and
     * quietly stop testing the boundary.
     */
    public const SERIES_LIMIT = 200;

    /** Set the chart range, ignoring values outside the whitelist. */
    public function setRange(string $range): void
    {
        $this->range = in_array($range, self::RANGES, true) ? $range : '7';
    }

    /**
     * Best WPM per mode+config, mapped config => WPM.
     * Example: words => ['15' => 131.0, '25' => 122.0].
     *
     * @return array<string, float>
     */
    private function bestWpmByConfig(int $userId, string $mode): array
    {
        return TypingResult::where('user_id', $userId)
            ->where('mode', $mode)
            ->whereNotNull('mode_config')
            ->trustworthy()
            ->select('mode_config', DB::raw('MAX(net_wpm) as high'))
            ->groupBy('mode_config')
            ->pluck('high', 'mode_config')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Survival is judged by HOW LONG YOU SURVIVE, not WPM -- so the record is
     * MAX(duration_seconds) per difficulty, not MAX(net_wpm).
     *
     * @return array<string, int>
     */
    private function bestSurvivalSeconds(int $userId): array
    {
        return TypingResult::where('user_id', $userId)
            ->where('mode', 'survival')
            ->whereNotNull('mode_config')
            ->trustworthy()
            ->select('mode_config', DB::raw('MAX(duration_seconds) as high'))
            ->groupBy('mode_config')
            ->pluck('high', 'mode_config')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Chart series (WPM & accuracy) for the active range, in chronological order.
     *
     * The cap exists so the 'all' range doesn't pull thousands of rows -- but WHICH end it
     * keeps is the whole point, and it used to keep the wrong one. `orderBy('created_at')`
     * then `take()` sorts ascending and cuts from the bottom, so a player past the cap got
     * their FIRST 200 sessions forever: the chart froze, and it froze hardest for the players
     * who type most. Take the newest, then restore chronological order for the chart.
     *
     * @return array{labels: list<string>, wpm: list<float>, accuracy: list<float>}
     */
    private function series(int $userId): array
    {
        $query = TypingResult::where('user_id', $userId);

        if ($this->range !== 'all') {
            $query->where('created_at', '>=', Carbon::now()->subDays((int) $this->range));
        }

        $rows = $query->latest('created_at')
            ->take(self::SERIES_LIMIT)
            ->get(['net_wpm', 'accuracy', 'created_at'])
            ->reverse()
            ->values();

        return [
            'labels' => $rows->map(fn ($r) => $r->created_at->format('M j'))->all(),
            'wpm' => $rows->map(fn ($r) => (float) $r->net_wpm)->all(),
            'accuracy' => $rows->map(fn ($r) => (float) $r->accuracy)->all(),
        ];
    }

    /**
     * Each mode's share of total tests, rounded to a percent.
     *
     * Note: 'ghost' is NOT included. Ghost sessions are stored as ordinary
     * 'time'/'words' rows (TypingEngine never writes a 'ghost' mode), so a ghost
     * slice would always be 0% and misleading.
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
     * This page shows only achievements ALREADY earned -- all of them, unbounded.
     * Still-locked ones are deliberately hidden: the full list with progress is the
     * job of /achievements.
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
        // Six numbers, one query (was: count/count/avg/max/avg = 5 separate queries over
        // the same table & filter). Wins are counted via SUM(CASE...), so no second query
        // just to filter place = 1.
        $agg = MultiplayerMatchHistory::where('user_id', $userId)
            ->selectRaw('
                COUNT(*) as total_races,
                COALESCE(SUM(CASE WHEN place = 1 THEN 1 ELSE 0 END), 0) as wins,
                AVG(wpm) as avg_wpm,
                MAX(wpm) as best_wpm,
                AVG(accuracy) as avg_accuracy
            ')
            ->first();

        $total = (int) $agg->total_races;

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

        $wins = (int) $agg->wins;

        return [
            'total_races' => $total,
            'wins' => $wins,
            'win_rate' => (int) round(($wins / $total) * 100),
            'avg_wpm' => (int) round((float) $agg->avg_wpm),
            'best_wpm' => (int) $agg->best_wpm,
            'avg_accuracy' => round((float) $agg->avg_accuracy, 1),
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

        // Five aggregates over the EXACT SAME table & filter -> one query, not five.
        $agg = TypingResult::where('user_id', $user->id)
            ->selectRaw('
                COUNT(*) as total_tests,
                COALESCE(SUM(duration_seconds), 0) as total_seconds,
                COALESCE(SUM(correct_chars), 0) as total_chars,
                AVG(accuracy) as avg_accuracy,
                AVG(net_wpm) as avg_wpm
            ')
            ->first();

        $activity = [
            'total_seconds' => (int) $agg->total_seconds,
            'total_tests' => (int) $agg->total_tests,
            'avg_accuracy' => round((float) $agg->avg_accuracy, 1),
            'avg_wpm' => (int) round((float) $agg->avg_wpm),
            'total_chars' => (int) $agg->total_chars,
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
