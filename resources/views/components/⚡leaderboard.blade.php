<?php

use App\Models\TypingResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use function Livewire\Volt\{state, computed};

// 1. Deklarasi State (Sama seperti Properti Public di Livewire Biasa)
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

// 3. Logika Ambil Data Leaderboard (Computed Property agar Otomatis Reactive)
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

<div class="min-h-screen bg-[#323437] text-[#646669] font-mono pt-12 px-4 selection:bg-yellow-500 selection:text-black">
    <div class="max-w-4xl mx-auto">

        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold text-[#d1d0c5] flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-yellow-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                papan peringkat
            </h1>

            <div class="flex gap-2 bg-[#2c2e31] p-1 rounded-lg text-xs">
                <button wire:click="setTimeframe('all_time')" class="px-3 py-1.5 rounded-md transition font-bold {{ $timeframe === 'all_time' ? 'bg-yellow-500 text-black' : 'hover:text-gray-200' }}">All-Time</button>
                <button wire:click="setTimeframe('daily')" class="px-3 py-1.5 rounded-md transition font-bold {{ $timeframe === 'daily' ? 'bg-yellow-500 text-black' : 'hover:text-gray-200' }}">Harian</button>
            </div>
        </div>

        <div class="flex gap-6 pb-3 mb-6 text-sm font-bold border-b border-gray-800">
            <button wire:click="setTab('time')" class="transition-colors {{ $currentTab === 'time' ? 'text-yellow-500 border-b-2 border-yellow-500 pb-3' : 'hover:text-gray-200' }}">time</button>
            <button wire:click="setTab('words')" class="transition-colors {{ $currentTab === 'words' ? 'text-yellow-500 border-b-2 border-yellow-500 pb-3' : 'hover:text-gray-200' }}">words</button>
            <button wire:click="setTab('survival')" class="transition-colors {{ $currentTab === 'survival' ? 'text-yellow-500 border-b-2 border-yellow-500 pb-3' : 'hover:text-gray-200' }}">survival</button>
        </div>

        <div class="flex gap-3 mb-8 text-xs font-bold">
            @if($currentTab === 'time')
                @foreach(['15', '30', '60', '120'] as $t)
                    <button wire:click="setConfig('{{ $t }}')" class="px-3 py-1 rounded transition {{ $currentConfig === $t ? 'text-yellow-500 bg-[#2c2e31]' : 'hover:text-gray-200' }}">{{ $t }}s</button>
                @endforeach
            @elseif($currentTab === 'words')
                @foreach(['10', '25', '50', '100'] as $w)
                    <button wire:click="setConfig('{{ $w }}')" class="px-3 py-1 rounded transition {{ $currentConfig === $w ? 'text-yellow-500 bg-[#2c2e31]' : 'hover:text-gray-200' }}">{{ $w }} words</button>
                @endforeach
            @elseif($currentTab === 'survival')
                <button class="px-3 py-1 rounded text-yellow-500 bg-[#2c2e31]">Medium Mode</button>
            @endif
        </div>

        @auth
            <div class="mb-6 bg-[#2c2e31]/50 border border-gray-800 rounded-xl p-4 flex justify-between items-center text-sm">
                <span class="text-gray-400">Posisi Peringkat Kamu:</span>
                <span class="text-lg font-bold text-yellow-500">#{{ $this->userRank }}</span>
            </div>
        @endauth

        <div class="bg-[#2c2e31] rounded-xl overflow-hidden shadow-2xl border border-gray-800">
            <table class="w-full text-sm text-left border-collapse">
                <thead>
                    <tr class="text-xs font-bold tracking-wider text-gray-500 uppercase border-b border-gray-800">
                        <th class="w-16 px-6 py-4 text-center">#</th>
                        <th class="px-6 py-4">Nama Pemain</th>
                        <th class="px-6 py-4 text-right">{{ $currentTab === 'survival' ? 'Durasi Bertahan' : 'Kecepatan (WPM)' }}</th>
                        <th class="px-6 py-4 text-right">Akurasi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/40 text-[#d1d0c5]">
                    @forelse($this->leaderboard as $index => $row)
                        <tr class="hover:bg-[#323437]/40 transition {{ Auth::id() === $row->user_id ? 'bg-yellow-500/5 text-yellow-500 font-bold' : '' }}">
                            <td class="px-6 py-4 text-xs font-bold text-center text-gray-500">
                                @if($index === 0) <span class="text-base text-yellow-500">👑</span>
                                @elseif($index === 1) <span class="text-base text-gray-400">🥈</span>
                                @elseif($index === 2) <span class="text-base text-amber-600">🥉</span>
                                @else {{ $index + 1 }}
                                @endif
                            </td>
                            <td class="px-6 py-4 font-medium">{{ $row->username }}</td>
                            <td class="px-6 py-4 font-bold tracking-tight text-right">
                                {{ $currentTab === 'survival' ? $row->score . ' detik' : $row->score . ' WPM' }}
                            </td>
                            <td class="px-6 py-4 text-right text-gray-400">{{ $row->accuracy }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-12 text-center text-gray-500">
                                Belum ada rekor data yang dicetak untuk kategori ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
