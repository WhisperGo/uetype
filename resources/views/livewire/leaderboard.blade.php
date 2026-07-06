<?php

use App\Models\TypingResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use function Livewire\Volt\{state, computed};

// 1. Deklarasi State
state([
    'currentTab' => 'time',
    'currentConfig' => '30',
    'timeframe' => 'all_time'
]);

// 2. Aksi/Fungsi Filter Kategori
$setTab = function ($tab) {
    $this->currentTab = $tab;
    $this->currentConfig = match ($tab) {
        'time' => '30',
        'words' => '25',
        'survival' => 'medium',
    };
};

$setConfig = function ($config) {
    $this->currentConfig = $config;
};

$setTimeframe = function ($timeframe) {
    $this->timeframe = $timeframe;
};

// 3. Logika Ambil Data Leaderboard
$leaderboard = computed(function () {
    $metric = ($this->currentTab === 'survival') ? 'duration_seconds' : 'net_wpm';

    $subQuery = TypingResult::select('user_id', DB::raw("MAX({$metric}) as best_score"))
        ->where('mode', $this->currentTab)
        ->where('mode_config', $this->currentConfig);

    if ($this->timeframe === 'daily') {
        $subQuery->where('created_at', '>=', now()->startOfDay());
    }

    $subQuery->groupBy('user_id');

    return TypingResult::from('typing_results as tr')
        ->joinSub($subQuery, 'pb', function ($join) use ($metric) {
            $join->on('tr.user_id', '=', 'pb.user_id')
                 ->on("tr.{$metric}", '=', 'pb.best_score');
        })
        ->join('users', 'tr.user_id', '=', 'users.id')
        ->select('users.username', 'tr.user_id', DB::raw("pb.best_score as score"), 'tr.accuracy')
        ->orderBy('score', 'desc')
        ->orderBy('tr.accuracy', 'desc')
        ->limit(10)
        ->get();
});

// 4. Logika Hitung Ranking User Sendiri
$userRank = computed(function () {
    if (!Auth::check()) return null;

    $metric = ($this->currentTab === 'survival') ? 'duration_seconds' : 'net_wpm';

    $rankQuery = TypingResult::select('user_id', DB::raw("MAX({$metric}) as best_score"))
        ->where('mode', $this->currentTab)
        ->where('mode_config', $this->currentConfig);

    if ($this->timeframe === 'daily') {
        $rankQuery->where('created_at', '>=', now()->startOfDay());
    }

    $ranks = $rankQuery->groupBy('user_id')
        ->orderBy('best_score', 'desc')
        ->pluck('user_id')
        ->toArray();

    $index = array_search(Auth::id(), $ranks);
    return $index !== false ? $index + 1 : 'Unranked';
});

?>

<div class="text-muted font-mono py-16 px-4">
    <div class="max-w-4xl mx-auto">

        <div class="flex items-center justify-between mb-10">
            <h1 class="text-xl font-bold tracking-widest uppercase text-foreground flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                </svg>
                {{ __('leaderboard.title') }}
            </h1>

            <div class="flex gap-1 bg-surface border border-border p-1 rounded-xl text-xs">
                <button wire:click="setTimeframe('all_time')" class="px-4 py-2 rounded-lg transition font-bold tracking-wider uppercase {{ $timeframe === 'all_time' ? 'bg-brand-bright text-background' : 'hover:text-foreground' }}">{{ __('leaderboard.all_time') }}</button>
                <button wire:click="setTimeframe('daily')" class="px-4 py-2 rounded-lg transition font-bold tracking-wider uppercase {{ $timeframe === 'daily' ? 'bg-brand-bright text-background' : 'hover:text-foreground' }}">{{ __('leaderboard.daily') }}</button>
            </div>
        </div>

        <div class="bg-surface border border-border rounded-2xl p-2.5 flex flex-col md:flex-row justify-between items-center gap-4 mb-8">

            <div class="flex w-full gap-2 md:w-auto">
                @foreach(['time', 'words', 'survival'] as $tab)
                    <button wire:click="setTab('{{ $tab }}')" class="flex-1 md:flex-none px-4 py-2 rounded-xl text-xs font-bold transition {{ $currentTab === $tab ? 'bg-brand-bright text-background' : 'hover:text-foreground' }}">
                        {{ __("leaderboard.tab.{$tab}") }}
                    </button>
                @endforeach
            </div>

            <div class="hidden md:block h-6 w-[1px] bg-border"></div>

            <div class="flex justify-end w-full gap-2 text-xs font-bold md:w-auto">
                @if($currentTab === 'time')
                    @foreach(['15', '30', '60', '120'] as $t)
                        <button wire:click="setConfig('{{ $t }}')" class="px-3 py-2 rounded-xl tabular-nums transition {{ $currentConfig === $t ? 'text-brand-bright bg-background border border-brand-bright/40' : 'hover:text-foreground' }}">{{ $t }}</button>
                    @endforeach
                @elseif($currentTab === 'words')
                    @foreach(['10', '25', '50', '100'] as $w)
                        <button wire:click="setConfig('{{ $w }}')" class="px-3 py-2 rounded-xl tabular-nums transition {{ $currentConfig === $w ? 'text-brand-bright bg-background border border-brand-bright/40' : 'hover:text-foreground' }}">{{ $w }}</button>
                    @endforeach
                @elseif($currentTab === 'survival')
                    <button class="px-4 py-2 rounded-xl text-brand-bright bg-background border border-brand-bright/30 cursor-default">{{ __('leaderboard.survival_mode') }}</button>
                @endif
            </div>
        </div>

        @auth
            <div class="mb-6 bg-surface/40 border border-border rounded-2xl p-4 flex justify-between items-center text-xs tracking-wider uppercase">
                <span class="text-muted">{{ __('leaderboard.your_rank') }}</span>
                <span class="font-bold text-brand-bright text-base tabular-nums">#{{ $this->userRank }}</span>
            </div>
        @endauth

        <div class="bg-surface rounded-2xl overflow-hidden border border-border">
            <table class="w-full text-sm text-left border-collapse">
                <thead>
                    <tr class="text-xs font-bold tracking-widest text-muted uppercase border-b border-border bg-background">
                        <th class="w-16 px-6 py-4 text-center">#</th>
                        <th class="px-6 py-4">{{ __('leaderboard.player') }}</th>
                        <th class="px-6 py-4 text-right">{{ $currentTab === 'survival' ? __('leaderboard.duration') : __('leaderboard.wpm') }}</th>
                        <th class="px-6 py-4 text-right">{{ __('leaderboard.accuracy') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border text-foreground">
                    @forelse($this->leaderboard as $index => $row)
                        <tr class="transition {{ Auth::id() === $row->user_id ? 'bg-brand-bright/10 text-brand-bright font-bold' : ($index === 0 ? 'bg-gold/5 hover:bg-gold/10' : 'hover:bg-background/60') }}">
                            <td class="px-6 py-4 text-xs font-bold text-center {{ $index === 0 ? 'text-gold' : 'text-muted' }}">
                                @if($index === 0) <span class="text-base">👑</span>
                                @elseif($index === 1) <span class="text-base">🥈</span>
                                @elseif($index === 2) <span class="text-base">🥉</span>
                                @else <span class="tabular-nums">{{ $index + 1 }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 font-medium {{ $index === 0 && Auth::id() !== $row->user_id ? 'text-gold' : '' }}">{{ $row->username }}</td>
                            <td class="px-6 py-4 font-bold tracking-tight text-right tabular-nums {{ Auth::id() === $row->user_id ? '' : 'text-foreground' }}">
                                {{ $currentTab === 'survival' ? $row->score . 's' : $row->score . ' wpm' }}
                            </td>
                            <td class="px-6 py-4 text-right font-medium tabular-nums {{ Auth::id() === $row->user_id ? '' : 'text-muted' }}">{{ $row->accuracy }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-16 text-center text-muted tracking-wide text-xs uppercase">
                                {{ __('leaderboard.empty') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
