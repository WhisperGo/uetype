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
        $timeLabel = $hours > 0 ? "{$hours}j {$minutes}m" : "{$minutes}m";

        $timeRecords = $bestRecords->where('mode', 'time');
        $wordsRecords = $bestRecords->where('mode', 'words');
        $survivalRecords = $bestRecords->where('mode', 'survival');
    @endphp

    <div class="py-10">
        <div class="max-w-5xl px-4 mx-auto space-y-8 sm:px-6 lg:px-8">

            <!-- ===== IDENTITY HEADER ===== -->
            <div class="relative p-6 overflow-hidden border bg-surface/70 border-white/10 rounded-3xl sm:p-8">
                <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">

                    <!-- Avatar Google / fallback inisial -->
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
                                <span class="px-2 py-0.5 rounded-md bg-brand/15 text-brand-bright text-xs font-mono font-semibold">
                                    [{{ $user->clan->tag }}] {{ ucfirst($user->clan_role ?? __('profile.clan_role_member')) }}
                                </span>
                            @endif
                            <span class="px-2 py-0.5 rounded-md bg-white/5 text-muted text-xs font-mono">{{ __('profile.level', ['level' => $stats['level']]) }}</span>
                        </div>

                        {{-- Email hanya di profil sendiri (privat). --}}
                        @if(! $isPublic)
                            <p class="mt-1 font-mono text-sm text-muted">{{ $user->email }}</p>
                        @endif

                        <p class="mt-1 text-xs text-muted">
                            {{ __('profile.joined', ['date' => \App\Support\AppTime::format($user->joined_at ?? $user->created_at, 'd F Y')]) }}
                        </p>

                        <div class="max-w-xs mt-3">
                            <div class="flex justify-between text-[0.65rem] text-muted font-mono mb-1">
                                <span>{{ $stats['level_progress'] }} / {{ $stats['level_needed'] }} XP</span>
                                <span>{{ __('profile.level', ['level' => $stats['level'] + 1]) }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-gradient-to-r from-brand to-gold" style="width: {{ $stats['level_needed'] > 0 ? ($stats['level_progress'] / $stats['level_needed']) * 100 : 0 }}%"></div>
                            </div>
                        </div>

                        {{-- Aksi pertemanan hanya di profil publik user lain (real-time). --}}
                        @if($isPublic)
                            <div class="mt-4">
                                <livewire:friend-button :target="$user" :key="'friend-btn-'.$user->id" />
                            </div>
                        @endif
                    </div>
                </div>
            </div>

            <!-- ===== STATISTIK ===== -->
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                @php
                    $cards = [
                        ['label' => __('profile.card.highest_wpm'), 'value' => rtrim(rtrim(number_format($user->highest_wpm, 1), '0'), '.'), 'accent' => 'text-brand-bright'],
                        ['label' => __('profile.card.avg_wpm'), 'value' => $stats['avg_wpm'], 'accent' => 'text-gold'],
                        ['label' => __('profile.card.avg_accuracy'), 'value' => $stats['avg_accuracy'].'%', 'accent' => 'text-gold'],
                        ['label' => __('profile.card.total_tests'), 'value' => $stats['total_matches'], 'accent' => 'text-foreground'],
                    ];
                @endphp
                @foreach($cards as $card)
                    <div class="p-5 border bg-surface/60 border-white/5 rounded-2xl">
                        <p class="text-xs uppercase tracking-[0.15em] text-muted font-mono mb-1">{{ $card['label'] }}</p>
                        <p class="text-3xl font-bold font-mono tabular-nums {{ $card['accent'] }}">{{ $card['value'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid grid-cols-2 gap-3 {{ $isPublic ? 'lg:grid-cols-3' : 'lg:grid-cols-4' }}">
                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                    <p class="text-xs uppercase tracking-[0.15em] text-muted font-mono mb-1">{{ __('profile.card.total_time') }}</p>
                    <p class="font-mono text-2xl font-bold text-foreground tabular-nums">{{ $timeLabel }}</p>
                </div>
                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                    <p class="text-xs uppercase tracking-[0.15em] text-muted font-mono mb-1">{{ __('profile.card.best_wpm') }}</p>
                    <p class="font-mono text-2xl font-bold text-brand-bright tabular-nums">{{ $stats['best_wpm'] }}</p>
                </div>
                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                    <p class="text-xs uppercase tracking-[0.15em] text-muted font-mono mb-1">{{ __('profile.card.level') }}</p>
                    <p class="font-mono text-2xl font-bold text-gold tabular-nums">{{ $stats['level'] }}</p>
                </div>

                {{-- Kartu privat: hanya profil sendiri. --}}
                @if(! $isPublic)
                    <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                        <p class="text-xs uppercase tracking-[0.15em] text-muted font-mono mb-1">{{ __('profile.card.xp') }}</p>
                        <p class="font-mono text-2xl font-bold text-gold tabular-nums">{{ $user->total_xp ?? 0 }}</p>
                    </div>
                @endif
            </div>

            <!-- ===== PROGRES WPM ===== -->
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl sm:p-6">
                <h3 class="mb-4 font-mono text-sm font-semibold text-foreground">{{ __('profile.wpm_progress') }}</h3>
                @if(count($wpmProgress) >= 2)
                    <div class="w-full h-48" wire:ignore>
                        <canvas id="profileWpmChart"></canvas>
                    </div>
                @else
                    <p class="font-mono text-sm text-muted">{{ __('profile.wpm_progress_empty') }}</p>
                @endif
            </div>

            <!-- ===== RIWAYAT PERTANDINGAN TERAKHIR ===== -->
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl sm:p-6">
                <h3 class="mb-4 font-mono text-sm font-semibold text-foreground">{{ __('profile.recent_matches') }}</h3>
                @if(isset($recentMatches) && $recentMatches->count() > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="font-mono text-xs tracking-wider uppercase text-muted">
                                    <th class="pb-3 font-semibold">{{ __('profile.th_date') }}</th>
                                    <th class="pb-3 font-semibold">{{ __('profile.th_mode') }}</th>
                                    <th class="pb-3 font-semibold text-right">{{ __('profile.th_wpm') }}</th>
                                    <th class="pb-3 font-semibold text-right">{{ __('profile.th_accuracy') }}</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono text-sm">
                                @foreach($recentMatches as $p)
                                <tr class="border-t border-white/5">
                                    <td class="py-2.5 text-muted">@localtime($p->created_at, 'd M Y H:i')</td>
                                    <td class="py-2.5 text-foreground capitalize">{{ $p->mode?->value ?? 'practice' }}</td>
                                    <td class="py-2.5 text-right text-brand-bright font-bold tabular-nums">{{ rtrim(rtrim(number_format($p->net_wpm, 1), '0'), '.') }}</td>
                                    <td class="py-2.5 text-right text-foreground tabular-nums">{{ rtrim(rtrim(number_format($p->accuracy, 1), '0'), '.') }}%</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    @if($isPublic)
                        <p class="font-mono text-sm text-muted">{{ __('profile.no_history_public', ['name' => $user->username]) }}</p>
                    @else
                        <p class="font-mono text-sm text-muted">{{ __('profile.no_history') }} <a href="{{ url('/typing') }}" class="text-brand-bright hover:underline">{{ __('profile.start_first') }}</a></p>
                    @endif
                @endif
            </div>

            <!-- ===== REKOR TERBAIK ===== -->
            <div class="space-y-4">
                <h3 class="font-mono text-sm font-semibold text-foreground">{{ __('profile.tab.best_records') }}</h3>

                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                    <div class="mb-3">
                        <h4 class="text-sm font-semibold text-foreground">{{ __('profile.records.time_mode') }}</h4>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.records.time_desc') }}</p>
                    </div>
                    @if($timeRecords->count() > 0)
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach($timeRecords as $record)
                                <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                    <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono">{{ $record->mode_config }} {{ __('profile.records.seconds') }}</span>
                                    <p class="mt-1 font-mono text-xl font-bold text-brand-bright tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="py-2 font-mono text-xs text-muted">{{ __('profile.records.time_empty') }}</p>
                    @endif
                </div>

                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                    <div class="mb-3">
                        <h4 class="text-sm font-semibold text-foreground">{{ __('profile.records.words_mode') }}</h4>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.records.words_desc') }}</p>
                    </div>
                    @if($wordsRecords->count() > 0)
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach($wordsRecords as $record)
                                <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                    <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono">{{ $record->mode_config }} {{ __('profile.records.words') }}</span>
                                    <p class="mt-1 font-mono text-xl font-bold text-gold tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="py-2 font-mono text-xs text-muted">{{ __('profile.records.words_empty') }}</p>
                    @endif
                </div>

                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                    <div class="mb-3">
                        <h4 class="text-sm font-semibold text-foreground">{{ __('profile.records.survival_mode') }}</h4>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.records.survival_desc') }}</p>
                    </div>
                    @if($survivalRecords->count() > 0)
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            @foreach($survivalRecords as $record)
                                <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                    <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono">{{ $record->mode_config }} {{ __('profile.records.difficulty') }}</span>
                                    <p class="mt-1 font-mono text-xl font-bold text-gold tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="py-2 font-mono text-xs text-muted">{{ __('profile.records.survival_empty') }}</p>
                    @endif
                </div>
            </div>

        </div>
    </div>

    <!-- Script Chart.js -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const el = document.getElementById('profileWpmChart');
            if (!el) return;
            const data = @json($wpmProgress);
            const render = () => {
                new Chart(el.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: data.map((_, i) => i + 1),
                        datasets: [{
                            label: 'wpm', data: data,
                            borderColor: '#C69F68',
                            backgroundColor: 'rgba(198,159,104,0.12)',
                            fill: true, borderWidth: 3, tension: 0.4,
                            pointRadius: 2, pointBackgroundColor: '#C69F68',
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8' } },
                            y: { grid: { color: 'rgba(255,255,255,0.04)' }, ticks: { color: '#94a3b8' }, beginAtZero: true }
                        }
                    }
                });
            };
            if (typeof Chart === 'undefined') {
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/chart.js';
                s.onload = render;
                document.head.appendChild(s);
            } else { render(); }
        });
    </script>
</x-app-layout>
