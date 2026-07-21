{{-- User profile page: identity header, level progress, stat cards, and (own profile) a link to full stats. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            @if($isPublic)
                <a href="{{ route('friends.index') }}" wire:navigate class="text-muted hover:text-foreground transition" aria-label="{{ __('profile.back') }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
            @endif
            <h2 class="font-mono text-xl font-semibold tracking-tight text-foreground">
                {{ $isPublic ? __('profile.header_public') : __('profile.header') }}
            </h2>
        </div>
    </x-slot>

    @php
        $totalSeconds = $stats['total_seconds'] ?? 0;
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $timeLabel = $hours > 0 ? "{$hours}h {$minutes}m" : "{$minutes}m";
    @endphp

    <div class="py-10">
        <div class="max-w-5xl px-4 mx-auto space-y-8 sm:px-6 lg:px-8">

            <!-- ===== IDENTITY HEADER ===== -->
            <div class="relative p-6 overflow-hidden border bg-surface/70 border-white/10 rounded-3xl sm:p-8">
                <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">

                    <!-- Google avatar / initial fallback -->
                    @if($user->avatar)
                        <img src="{{ $user->avatar }}"
                             alt="{{ $user->username }}"
                             class="w-20 h-20 object-cover rounded-2xl border border-white/10 shadow-md shrink-0"
                             referrerpolicy="no-referrer">
                    @else
                        <div class="flex items-center justify-center w-20 h-20 text-3xl font-bold uppercase rounded-2xl bg-gradient-to-br from-brand to-gold text-background shrink-0">
                            {{ \Illuminate\Support\Str::substr($user->username, 0, 1) }}
                        </div>
                    @endif

                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="font-mono text-2xl font-bold text-foreground">{{ $user->username }}</h1>
                            @if($user->clan)
                                <span class="px-2 py-0.5 rounded-md bg-white/5 text-muted text-xs font-mono font-semibold">
                                    [{{ $user->clan->tag }}] {{ ucfirst($user->clan_role ?? __('profile.clan_role_member')) }}
                                </span>
                            @endif
                            <span class="px-2 py-0.5 rounded-md bg-brand/25 text-muted text-xs font-mono">{{ __('profile.level', ['level' => $stats['level']]) }}</span>
                        </div>

                        {{-- Email shown only on your own profile (private). --}}
                        @if(! $isPublic)
                            <p class="mt-1 font-mono text-sm text-muted">{{ $user->email }}</p>
                        @endif

                        <p class="mt-1 text-xs text-muted">
                            {{ __('profile.joined', ['date' => \App\Support\AppTime::format($user->joined_at ?? $user->created_at, 'd F Y')]) }}
                        </p>

                        <div class="max-w-xs mt-3">
                            <div class="flex justify-end text-[0.65rem] text-muted font-mono mb-1">
                                <span>{{ $stats['level_progress'] }} / {{ $stats['level_needed'] }} XP</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-gold" style="width: {{ $stats['level_needed'] > 0 ? ($stats['level_progress'] / $stats['level_needed']) * 100 : 0 }}%"></div>
                            </div>
                        </div>

                        {{-- Friend actions only on another user's public profile (real-time). --}}
                        @if($isPublic)
                            <div class="mt-4">
                                <livewire:friend-button :target="$user" :key="'friend-btn-'.$user->id" />
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- ===== STATISTICS ===== -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @php
                    $cards = [
                        ['label' => __('profile.card.highest_wpm'), 'value' => rtrim(rtrim(number_format($user->highest_wpm, 1), '0'), '.'), 'accent' => 'text-brand-bright'],
                        ['label' => __('profile.card.avg_wpm'), 'value' => $stats['avg_wpm'], 'accent' => 'text-gold'],
                        ['label' => __('profile.card.avg_accuracy'), 'value' => $stats['avg_accuracy'].'%', 'accent' => 'text-gold'],
                        ['label' => __('profile.card.total_tests'), 'value' => $stats['total_matches'], 'accent' => 'text-foreground'],
                        ['label' => __('profile.card.total_time'), 'value' => $timeLabel, 'accent' => 'text-foreground'],
                    ];
                @endphp
                @foreach($cards as $card)
                    <div class="p-5 border bg-surface/60 border-white/5 rounded-2xl">
                        <p class="text-xs uppercase tracking-[0.15em] text-muted font-mono mb-1">{{ $card['label'] }}</p>
                        <p class="text-3xl font-bold font-mono tabular-nums {{ $card['accent'] }}">{{ $card['value'] }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Full statistics (charts, per-mode records, activity) live at /stats;
                 the profile focuses on identity. Link shown only on your own profile. --}}
            @if(! $isPublic)
                <a href="{{ route('stats') }}" wire:navigate
                    class="flex items-center justify-between gap-4 p-5 border bg-surface/40 border-white/5 rounded-2xl transition-colors hover:border-brand/40 hover:bg-surface/60">
                    <div>
                        <h3 class="font-mono text-sm font-semibold text-foreground">{{ __('profile.view_stats') }}</h3>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.view_stats_hint') }}</p>
                    </div>
                    <svg class="w-5 h-5 text-muted shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            @endif

        </div>
    </div>
</x-app-layout>
