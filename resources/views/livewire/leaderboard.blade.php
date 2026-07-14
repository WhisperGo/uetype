<?php

use App\Enums\FriendshipStatus;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\TypingResult;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use function Livewire\Volt\{state, computed};

state([
    'currentTab' => 'time',
    'currentConfig' => '30',
    'timeframe' => 'all_time'
]);

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

$sendRequest = function (int $userId) {
    $me = Auth::id();

    if ($userId === $me) {
        return;
    }

    if (Auth::user()->friendshipWith($userId)) {
        return;
    }

    Friendship::create([
        'requester_id' => $me,
        'addressee_id' => $userId,
        'status' => FriendshipStatus::Pending,
    ]);

    $payload = ['type' => 'request', 'message' => Auth::user()->username.' sent you a friend request'];

    FriendshipUpdated::dispatch($userId, $payload);
};

// Metrik peringkat per tab: survival dinilai dari LAMA BERTAHAN, mode lain dari WPM.
$metricFor = fn (string $tab) => $tab === 'survival' ? 'duration_seconds' : 'net_wpm';

// Filter dasar (mode + config + timeframe aktif). Satu sumber kebenaran yang
// dipakai papan DAN perhitungan rank, supaya keduanya tak mungkin memfilter
// dengan aturan yang berbeda.
//
// State dioper eksplisit sebagai argumen, bukan lewat $this: closure biasa di
// Volt TIDAK di-bind ke komponen (hanya action & computed yang di-bind), jadi
// $this di sini akan fatal.
$scoped = function (string $tab, string $config, string $timeframe) {
    $q = TypingResult::where('mode', $tab)->where('mode_config', $config);

    if ($timeframe === 'daily') {
        $q->where('created_at', '>=', now()->startOfDay());
    }

    return $q;
};

// Rekor terbaik per user di scope aktif.
$bestPerUser = fn (string $metric, string $tab, string $config, string $timeframe) => $scoped($tab, $config, $timeframe)
    ->select('user_id', DB::raw("MAX({$metric}) as best_score"))
    ->groupBy('user_id');

$leaderboard = computed(function () use ($metricFor, $bestPerUser) {
    $metric = $metricFor($this->currentTab);

    // GROUP BY di query LUAR itu wajib, bukan hiasan: join mencocokkan
    // `tr.{metric} = pb.best_score`, jadi user yang punya DUA hasil dengan skor
    // identik (mudah terjadi -- net_wpm cuma 2 desimal) akan menghasilkan dua
    // baris, menggandakan dirinya di papan DAN menggeser pemain lain keluar dari
    // top 10. Grouping menjamin satu baris per user secara struktural.
    $rows = TypingResult::from('typing_results as tr')
        ->joinSub($bestPerUser($metric, $this->currentTab, $this->currentConfig, $this->timeframe), 'pb', function ($join) use ($metric) {
            $join->on('tr.user_id', '=', 'pb.user_id')
                 ->on("tr.{$metric}", '=', 'pb.best_score');
        })
        ->join('users', 'tr.user_id', '=', 'users.id')
        ->groupBy('tr.user_id', 'users.username', 'users.avatar', 'pb.best_score')
        ->select(
            'tr.user_id',
            'users.username',
            'users.avatar',
            DB::raw('pb.best_score as score'),
            // Akurasi dari sesi rekornya; kalau beberapa sesi seri di skor yang
            // sama, ambil yang paling akurat sebagai pemecah seri.
            DB::raw('MAX(tr.accuracy) as accuracy'),
        )
        ->orderBy('score', 'desc')
        ->orderBy('accuracy', 'desc')
        ->limit(10)
        ->get();

    $me = Auth::user();

    if (! $me) {
        return $rows->each(fn ($row) => $row->relation = 'none');
    }

    // Status relasi untuk SEMUA baris dalam satu query (dulu: satu query per
    // baris lewat friendshipWith() -> 10 query tiap ganti tab/config).
    $relations = Friendship::relationMapFor($me->id, $rows->pluck('user_id')->all());

    return $rows->each(function ($row) use ($relations) {
        $row->relation = $relations[(int) $row->user_id]['relation'] ?? 'none';
    });
});

$userRank = computed(function () use ($metricFor, $bestPerUser, $scoped) {
    if (!Auth::check()) return null;

    $metric = $metricFor($this->currentTab);

    // Rekor SAYA di mode/config ini. Belum pernah main -> tak punya peringkat.
    $myBest = $scoped($this->currentTab, $this->currentConfig, $this->timeframe)
        ->where('user_id', Auth::id())
        ->max($metric);

    if ($myBest === null) {
        return 'Unranked';
    }

    // Peringkat = jumlah user yang rekornya LEBIH TINGGI dari rekor saya, + 1.
    // Dihitung DI DATABASE lewat COUNT: yang kembali ke PHP cuma satu angka.
    // (Dulu: pluck() menarik SATU BARIS PER USER ke memori PHP lalu array_search
    //  -- 10.000 user = 10.000 baris ditarik, setiap kali user ganti tab.)
    $better = DB::query()
        ->fromSub($bestPerUser($metric, $this->currentTab, $this->currentConfig, $this->timeframe), 'pb')
        ->where('pb.best_score', '>', $myBest)
        ->count();

    return $better + 1;
});

?>

<div class="text-muted font-mono py-16">
    <x-page-container width="max-w-4xl">

        <div class="flex flex-wrap items-center justify-between gap-4 mb-10">
            <h1 class="text-fluid-title font-bold tracking-widest uppercase text-foreground flex items-center gap-3">
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

        <div class="bg-surface border border-border rounded-2xl p-2.5 flex flex-col md:flex-row justify-between items-center gap-3 mb-8">

            <div class="flex w-full gap-2 md:w-auto">
                @foreach(['time', 'words', 'survival'] as $tab)
                    <button wire:click="setTab('{{ $tab }}')" class="flex-1 md:flex-none px-4 py-2 rounded-xl text-xs font-bold transition {{ $currentTab === $tab ? 'bg-brand-bright text-background' : 'hover:text-foreground' }}">
                        {{ __("leaderboard.tab.{$tab}") }}
                    </button>
                @endforeach
            </div>

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
                    @foreach(['easy', 'medium', 'hard'] as $d)
                        <button wire:click="setConfig('{{ $d }}')" class="px-3 py-2 rounded-xl transition capitalize {{ $currentConfig === $d ? 'text-brand-bright bg-background border border-brand-bright/40' : 'hover:text-foreground' }}">{{ __("leaderboard.difficulty.{$d}") }}</button>
                    @endforeach
                @endif
            </div>
        </div>

        @auth
            <div class="mb-6 bg-surface/40 border border-border rounded-2xl p-4 flex justify-between items-center text-xs tracking-wider uppercase">
                <span class="text-muted">{{ __('leaderboard.your_rank') }}</span>
                <span class="font-bold text-brand-bright text-base tabular-nums">#{{ $this->userRank }}</span>
            </div>
        @endauth

        <div class="bg-surface rounded-2xl border border-border">
            <div class="hidden sm:flex items-center gap-4 px-6 py-4 text-xs font-bold tracking-widest text-muted uppercase border-b border-border bg-background rounded-t-2xl">
                <span class="w-8 text-center">#</span>
                <span class="flex-1">{{ __('leaderboard.player') }}</span>
                <span class="w-28 text-right">{{ $currentTab === 'survival' ? __('leaderboard.duration') : __('leaderboard.wpm') }}</span>
                <span class="w-20 text-right">{{ __('leaderboard.accuracy') }}</span>
                <span class="w-8"></span>
            </div>

            <div class="divide-y divide-border text-foreground">
                @forelse($this->leaderboard as $index => $row)
                    @php $isMe = Auth::id() === (int) $row->user_id; @endphp
                    <div wire:key="lb-{{ $row->user_id }}"
                        class="relative flex items-center gap-3 sm:gap-4 px-4 sm:px-6 py-3 transition last:rounded-b-2xl {{ $isMe ? 'bg-brand-bright/10' : ($index === 0 ? 'bg-gold/5 hover:bg-gold/10' : 'hover:bg-background/60') }}">

                        <span class="w-8 shrink-0 text-center text-xs font-bold tabular-nums {{ $index === 0 ? 'text-gold' : 'text-muted' }}">
                            @if($index === 0) <span class="text-base">👑</span>
                            @elseif($index === 1) <span class="text-base">🥈</span>
                            @elseif($index === 2) <span class="text-base">🥉</span>
                            @else {{ $index + 1 }}
                            @endif
                        </span>

                        <x-friend-avatar :user="$row" />

                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold truncate {{ $isMe ? 'text-brand-bright' : ($index === 0 ? 'text-gold' : 'text-foreground') }}">
                                {{ $row->username }}
                                @if($isMe)
                                    <span class="ml-1.5 align-middle text-[10px] font-bold uppercase tracking-wider text-brand-bright/70">{{ __('leaderboard.you') }}</span>
                                @endif
                            </p>
                            <p class="sm:hidden text-xs text-muted tabular-nums mt-0.5">
                                {{ $currentTab === 'survival' ? $row->score . 's' : $row->score . ' wpm' }} · {{ $row->accuracy }}%
                            </p>
                        </div>

                        <span class="hidden sm:block w-28 text-right text-sm font-bold tracking-tight tabular-nums {{ $isMe ? 'text-brand-bright' : 'text-foreground' }}">
                            {{ $currentTab === 'survival' ? $row->score . 's' : $row->score . ' wpm' }}
                        </span>
                        <span class="hidden sm:block w-20 text-right text-sm font-medium tabular-nums {{ $isMe ? 'text-brand-bright' : 'text-muted' }}">{{ $row->accuracy }}%</span>

                        <div class="w-8 shrink-0 flex justify-end" x-data="{ open: false }" @keydown.escape="open = false">
                            <button type="button" @click="open = !open" @click.outside="open = false"
                                aria-label="{{ __('leaderboard.actions') }}"
                                class="p-1.5 rounded-lg text-muted hover:text-foreground hover:bg-white/5 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01" />
                                </svg>
                            </button>

                            <div x-show="open" x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                @click="open = false"
                                class="absolute right-4 top-full z-50 mt-1 w-48 origin-top-right rounded-xl border border-white/10 bg-surface shadow-lg ring-1 ring-black/20 overflow-hidden py-1">

                                <a href="{{ route('profile.show', $row->username) }}" wire:navigate
                                    class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-bold text-foreground hover:bg-white/5 transition">
                                    <svg class="w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" /></svg>
                                    {{ __('leaderboard.view_profile') }}
                                </a>

                                @unless($isMe)
                                    @if(in_array($currentTab, ['time', 'words'], true))
                                        <a href="{{ route('typing') }}?ghost={{ $row->user_id }}&mode={{ $currentTab }}&config={{ $currentConfig }}" wire:navigate
                                            class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-bold text-foreground hover:bg-white/5 transition">
                                            <svg class="w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 7h10v10H7V7z" /></svg>
                                            {{ __('leaderboard.challenge_ghost') }}
                                        </a>
                                    @endif

                                    @switch($row->relation)
                                        @case('friends')
                                            <span class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-bold text-gold">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                                {{ __('leaderboard.friends_label') }}
                                            </span>
                                            @break
                                        @case('sent')
                                            <span class="flex items-center gap-2.5 px-4 py-2.5 text-xs text-muted">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                                {{ __('leaderboard.request_sent') }}
                                            </span>
                                            @break
                                        @case('incoming')
                                            <a href="{{ route('friends.index') }}" wire:navigate
                                                class="flex items-center gap-2.5 px-4 py-2.5 text-xs font-bold text-foreground hover:bg-white/5 transition">
                                                <svg class="w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5m6 0v1a3 3 0 11-6 0v-1" /></svg>
                                                {{ __('leaderboard.respond') }}
                                            </a>
                                            @break
                                        @default
                                            <button type="button" wire:click="sendRequest({{ $row->user_id }})"
                                                class="w-full flex items-center gap-2.5 px-4 py-2.5 text-xs font-bold text-foreground hover:bg-white/5 transition text-left">
                                                <svg class="w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
                                                {{ __('leaderboard.add_friend') }}
                                            </button>
                                    @endswitch
                                @endunless
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="py-16 text-center text-muted tracking-wide text-xs uppercase">
                        {{ __('leaderboard.empty') }}
                    </div>
                @endforelse
            </div>
        </div>
    </x-page-container>
</div>
