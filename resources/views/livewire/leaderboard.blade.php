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

<div class="min-h-screen bg-[#0f172a] text-[#334155] font-mono pt-16 px-4 selection:bg-[#22d3ee] selection:text-[#121824]">
    <div class="max-w-4xl mx-auto">

        <div class="flex items-center justify-between mb-10">
            <h1 class="text-xl font-bold tracking-widest uppercase text-[#93b4db] flex items-center gap-3">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-[#24e5ff] drop-shadow-[0_0_5px_rgba(36,229,255,0.5)]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                papan peringkat
            </h1>

            <div class="flex gap-1 bg-[#1a2333] border border-[#233044] p-1 rounded-lg text-xs">
                <button wire:click="setTimeframe('all_time')" class="px-4 py-2 rounded-md transition font-bold tracking-wider uppercase {{ $timeframe === 'all_time' ? 'bg-[#24e5ff] text-[#121824] shadow-[0_0_10px_rgba(36,229,255,0.3)]' : 'hover:text-[#93b4db]' }}">All-Time</button>
                <button wire:click="setTimeframe('daily')" class="px-4 py-2 rounded-md transition font-bold tracking-wider uppercase {{ $timeframe === 'daily' ? 'bg-[#24e5ff] text-[#121824] shadow-[0_0_10px_rgba(36,229,255,0.3)]' : 'hover:text-[#93b4db]' }}">Harian</button>
            </div>
        </div>

        <div class="bg-[#1a2333] border border-[#24344d]/60 rounded-xl p-2.5 flex flex-col md:flex-row justify-between items-center gap-4 mb-8 shadow-xl">

            <div class="flex w-full gap-2 md:w-auto">
                @foreach(['time', 'words', 'quote', 'survival'] as $tab)
                    <button wire:click="setTab('{{ $tab }}')" class="flex-1 md:flex-none px-4 py-2 rounded-lg text-xs font-bold transition {{ $currentTab === $tab ? 'bg-[#24e5ff] text-[#121824] font-extrabold shadow-[0_0_15px_rgba(36,229,255,0.4)]' : 'hover:text-[#93b4db]' }}">
                        {{ $tab }}
                    </button>
                @endforeach
            </div>

            <div class="hidden md:block h-6 w-[1px] bg-[#24344d]"></div>

            <div class="flex justify-end w-full gap-2 text-xs font-bold md:w-auto">
                @if($currentTab === 'time')
                    @foreach(['15', '30', '60', '120'] as $t)
                        <button wire:click="setConfig('{{ $t }}')" class="px-3 py-2 rounded-lg transition {{ $currentConfig === $t ? 'text-[#24e5ff] bg-[#121824] border border-[#24e5ff]/30' : 'hover:text-[#93b4db]' }}">{{ $t }}</button>
                    @endforeach
                @elseif($currentTab === 'words')
                    @foreach(['10', '25', '50', '100'] as $w)
                        <button wire:click="setConfig('{{ $w }}')" class="px-3 py-2 rounded-lg transition {{ $currentConfig === $w ? 'text-[#24e5ff] bg-[#121824] border border-[#24e5ff]/30' : 'hover:text-[#93b4db]' }}">{{ $w }}</button>
                    @endforeach
                @elseif($currentTab === 'quote')
                    @foreach(['easy', 'medium', 'hard'] as $q)
                        <button wire:click="setConfig('{{ $q }}')" class="px-3 py-2 rounded-lg capitalize transition {{ $currentConfig === $q ? 'text-[#24e5ff] bg-[#121824] border border-[#24e5ff]/30' : 'hover:text-[#93b4db]' }}">{{ $q }}</button>
                    @endforeach
                @elseif($currentTab === 'survival')
                    <button class="px-4 py-2 rounded-lg text-[#24e5ff] bg-[#121824] border border-[#24e5ff]/20 cursor-default">Medium Mode</button>
                @endif
            </div>
        </div>

        @auth
            <div class="mb-6 bg-[#1a2333]/40 border border-[#24344d]/60 rounded-xl p-4 flex justify-between items-center text-xs tracking-wider uppercase">
                <span class="text-[#4f6585]">Posisi Peringkat Kamu:</span>
                <span class="font-bold text-[#24e5ff] text-base drop-shadow-[0_0_5px_rgba(36,229,255,0.3)]">#{{ $this->userRank }}</span>
            </div>
        @endauth

        <div class="bg-[#1a2333] rounded-xl overflow-hidden shadow-2xl border border-[#24344d]/60">
            <table class="w-full text-sm text-left border-collapse">
                <thead>
                    <tr class="text-xs font-bold tracking-widest text-[#4f6585] uppercase border-b border-[#24344d]/60 bg-[#141b28]">
                        <th class="w-16 px-6 py-4 text-center">#</th>
                        <th class="px-6 py-4">Nama Pemain</th>
                        <th class="px-6 py-4 text-right">{{ $currentTab === 'survival' ? 'Durasi Bertahan' : 'Kecepatan (WPM)' }}</th>
                        <th class="px-6 py-4 text-right">Akurasi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#24344d]/40 text-[#93b4db]">
                    @forelse($this->leaderboard as $index => $row)
                        <tr class="hover:bg-[#121824]/60 transition {{ Auth::id() === $row->user_id ? 'bg-[#24e5ff]/5 text-[#24e5ff] font-bold' : '' }}">
                            <td class="px-6 py-4 text-xs font-bold text-center text-[#4f6585]">
                                @if($index === 0) <span class="text-sm drop-shadow-[0_0_4px_rgba(255,215,0,0.4)]">👑</span>
                                @elseif($index === 1) <span class="text-sm">🥈</span>
                                @elseif($index === 2) <span class="text-sm">🥉</span>
                                @else {{ $index + 1 }}
                                @endif
                            </td>
                            <td class="px-6 py-4 font-medium">{{ $row->username }}</td>
                            <td class="px-6 py-4 font-bold tracking-tight text-right text-white">
                                {{ $currentTab === 'survival' ? $row->score . 's' : $row->score . ' wpm' }}
                            </td>
                            <td class="px-6 py-4 text-right text-[#4f6585] font-medium">{{ $row->accuracy }}%</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-16 text-center text-[#4f6585] tracking-wide text-xs uppercase">
                                Belum ada rekor data yang dicetak untuk kategori ini.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
