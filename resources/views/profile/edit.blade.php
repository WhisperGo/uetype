<x-app-layout>
    <x-slot name="header">
        <h2 class="font-sans text-xl font-semibold tracking-tight text-foreground">
            {{ __('Profil') }}
        </h2>
    </x-slot>

    @php
        $totalSeconds = $stats['total_seconds'] ?? 0;
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $timeLabel = $hours > 0 ? "{$hours}j {$minutes}m" : "{$minutes}m";
    @endphp

    <div class="py-10" x-data="{ activeTab: 'stats' }">
        <div class="max-w-5xl px-4 mx-auto space-y-8 sm:px-6 lg:px-8">

            <!-- ===== IDENTITY HEADER ===== -->
            <div class="relative p-6 overflow-hidden border bg-surface/70 border-white/10 rounded-3xl sm:p-8 shadow-glow">
                <div class="absolute w-48 h-48 rounded-full -top-16 -right-16 bg-brand/10 blur-3xl"></div>
                <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">
                    
                    <!-- MODIFIKASI DISINI: RENDER AVATAR GOOGLE / INITIALS FALLBACK -->
                    @if($user->avatar)
                        <!-- Tampilkan Foto Profil Asli Google -->
                        <img src="{{ $user->avatar }}" 
                             alt="{{ $user->username }}" 
                             class="w-20 h-20 object-cover rounded-2xl border border-white/10 shadow-md shrink-0"
                             referrerpolicy="no-referrer">
                    @else
                        <!-- Cadangan Inisial Huruf (Jika daftar manual) -->
                        <div class="flex items-center justify-center w-20 h-20 text-3xl font-bold uppercase rounded-2xl bg-gradient-to-br from-brand to-gold text-background shrink-0">
                            {{ \Illuminate\Support\Str::substr($user->username, 0, 1) }}
                        </div>
                    @endif

                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="font-sans text-2xl font-bold text-foreground">{{ $user->username }}</h1>
                            @if($user->clan)
                                <span class="px-2 py-0.5 rounded-md bg-brand/15 text-brand-bright text-xs font-mono font-semibold">
                                    [{{ $user->clan->tag }}] {{ ucfirst($user->clan_role ?? 'member') }}
                                </span>
                            @endif
                            <span class="px-2 py-0.5 rounded-md bg-white/5 text-muted text-xs font-mono">Level {{ $stats['level'] }}</span>
                        </div>
                        <p class="mt-1 font-mono text-sm text-muted">{{ $user->email }}</p>
                        <p class="mt-1 text-xs text-muted">
                            Bergabung {{ optional($user->joined_at ?? $user->created_at)->translatedFormat('d F Y') }}
                        </p>
                        <div class="max-w-xs mt-3">
                            <div class="flex justify-between text-[0.65rem] text-muted font-mono mb-1">
                                <span>{{ $stats['level_progress'] }} / {{ $stats['level_needed'] }} XP</span>
                                <span>Level {{ $stats['level'] + 1 }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-gradient-to-r from-brand to-gold" style="width: {{ $stats['level_needed'] > 0 ? ($stats['level_progress'] / $stats['level_needed']) * 100 : 0 }}%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="pt-4 text-center border-t sm:text-right shrink-0 sm:border-t-0 sm:border-l border-white/10 sm:pt-0 sm:pl-6">
                        <p class="text-xs uppercase tracking-[0.2em] text-muted font-sans">ELO</p>
                        <p class="mt-1 font-mono text-4xl font-bold leading-none text-gold">{{ $user->elo_rating ?? 0 }}</p>
                        <p class="mt-1 text-xs text-muted">Ranking</p>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-white/10">
                <nav class="flex gap-6 -mb-px" aria-label="Tabs">
                    <button @click="activeTab = 'stats'"
                            :class="activeTab === 'stats' ? 'border-brand-bright text-brand-bright' : 'border-transparent text-muted hover:text-foreground'"
                            class="px-1 py-3 font-sans text-sm font-semibold transition-colors border-b-2 whitespace-nowrap">
                        Statistik
                    </button>
                    <button @click="activeTab = 'settings'"
                            :class="activeTab === 'settings' ? 'border-brand-bright text-brand-bright' : 'border-transparent text-muted hover:text-foreground'"
                            class="px-1 py-3 font-sans text-sm font-semibold transition-colors border-b-2 whitespace-nowrap">
                        Pengaturan Akun
                    </button>
                    <button @click="activeTab = 'BestRecords'"
                            :class="activeTab === 'BestRecords' ? 'border-brand-bright text-brand-bright' : 'border-transparent text-muted hover:text-foreground'"
                            class="px-1 py-3 font-sans text-sm font-semibold transition-colors border-b-2 whitespace-nowrap">
                        Rekor Terbaik
                    </button>
                </nav>
            </div>

            <!-- ===== STATS TAB ===== -->
            <div x-show="activeTab === 'stats'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-6">

                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    @php
                        $cards = [
                            ['label' => 'WPM Tertinggi', 'value' => rtrim(rtrim(number_format($user->highest_wpm, 1), '0'), '.'), 'accent' => 'text-brand-bright'],
                            ['label' => 'Rata-rata WPM', 'value' => $stats['avg_wpm'], 'accent' => 'text-gold'],
                            ['label' => 'Rata-rata Akurasi', 'value' => $stats['avg_accuracy'].'%', 'accent' => 'text-gold'],
                            ['label' => 'Total Tes', 'value' => $stats['total_matches'], 'accent' => 'text-foreground'],
                        ];
                    @endphp
                    @foreach($cards as $card)
                        <div class="p-5 border bg-surface/60 border-white/5 rounded-2xl">
                            <p class="text-xs uppercase tracking-[0.15em] text-muted font-sans mb-1">{{ $card['label'] }}</p>
                            <p class="text-3xl font-bold font-mono tabular-nums {{ $card['accent'] }}">{{ $card['value'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                        <p class="text-xs uppercase tracking-[0.15em] text-muted font-sans mb-1">Total Waktu</p>
                        <p class="font-mono text-2xl font-bold text-foreground tabular-nums">{{ $timeLabel }}</p>
                    </div>
                    <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                        <p class="text-xs uppercase tracking-[0.15em] text-muted font-sans mb-1">XP</p>
                        <p class="font-mono text-2xl font-bold text-gold tabular-nums">{{ $user->total_xp ?? 0 }}</p>
                    </div>
                    <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                        <p class="text-xs uppercase tracking-[0.15em] text-muted font-sans mb-1">Koin</p>
                        <p class="font-mono text-2xl font-bold text-gold tabular-nums">{{ $user->coins ?? 0 }}</p>
                    </div>
                    <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl">
                        <p class="text-xs uppercase tracking-[0.15em] text-muted font-sans mb-1">Best WPM</p>
                        <p class="font-mono text-2xl font-bold text-brand-bright tabular-nums">{{ $stats['best_wpm'] }}</p>
                    </div>
                </div>

                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl sm:p-6">
                    <h3 class="mb-4 font-sans text-sm font-semibold text-foreground">Progres WPM (tes terakhir)</h3>
                    @if(count($wpmProgress) >= 2)
                        <div class="w-full h-48" wire:ignore>
                            <canvas id="profileWpmChart"></canvas>
                        </div>
                    @else
                        <p class="font-mono text-sm text-muted">Belum cukup data. Selesaikan beberapa tes untuk melihat progresmu.</p>
                    @endif
                </div>

                <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl sm:p-6">
                    <h3 class="mb-4 font-sans text-sm font-semibold text-foreground">Riwayat Pertandingan Terakhir</h3>
                    @if(isset($recentMatches) && $recentMatches->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="font-sans text-xs tracking-wider uppercase text-muted">
                                        <th class="pb-3 font-semibold">Tanggal</th>
                                        <th class="pb-3 font-semibold">Mode</th>
                                        <th class="pb-3 font-semibold text-right">WPM</th>
                                        <th class="pb-3 font-semibold text-right">Akurasi</th>
                                    </tr>
                                </thead>
                                <tbody class="font-mono text-sm">
                                    @foreach($recentMatches as $p)
                                    <tr class="border-t border-white/5">
                                        <td class="py-2.5 text-muted">{{ $p->created_at ? $p->created_at->format('d M Y H:i') : '-' }}</td>
                                        <td class="py-2.5 text-foreground capitalize">
                                            {{ $p->mode?->value ?? 'practice' }}
                                        </td>
                                        <td class="py-2.5 text-right text-brand-bright font-bold tabular-nums">{{ rtrim(rtrim(number_format($p->net_wpm, 1), '0'), '.') }}</td>
                                        <td class="py-2.5 text-right text-foreground tabular-nums">{{ rtrim(rtrim(number_format($p->accuracy, 1), '0'), '.') }}%</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="font-mono text-sm text-muted">Belum ada riwayat mengetik. <a href="{{ url('/typing') }}" class="text-brand-bright hover:underline">Mulai tes pertamamu →</a></p>
                    @endif
                </div>
            </div>

            <!-- ===== SETTINGS TAB ===== -->
            <div x-show="activeTab === 'settings'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" style="display: none;" class="space-y-6">
                <div class="p-6 border sm:p-8 bg-surface/60 border-white/5 rounded-2xl">
                    <div class="max-w-xl">@include('profile.partials.update-profile-information-form')</div>
                </div>
                <div class="p-6 border sm:p-8 bg-surface/60 border-white/5 rounded-2xl">
                    <div class="max-w-xl">@include('profile.partials.update-password-form')</div>
                </div>
                <div class="p-6 border sm:p-8 bg-surface/60 border-white/5 rounded-2xl">
                    <div class="max-w-xl">@include('profile.partials.delete-user-form')</div>
                </div>
            </div>

            <!-- ===== BEST RECORDS TAB ===== -->
            <div x-show="activeTab === 'BestRecords'"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 style="display: none;"
                 class="space-y-4"
                 x-data="{ openMode: 'time' }">
                @php
                    $timeRecords = $bestRecords->where('mode', 'time');
                    $wordsRecords = $bestRecords->where('mode', 'words');
                    $survivalRecords = $bestRecords->where('mode', 'survival');
                    $quoteRecords = $bestRecords->where('mode', 'quote');
                @endphp

                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'time' ? '' : 'time')"
                            class="flex items-center justify-between w-full p-5 font-sans text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">⏱️</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">Time Mode</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">Rekor berdasarkan durasi waktu pengetikan</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'time' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'time'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        @if($timeRecords->count() > 0)
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @foreach($timeRecords as $record)
                                    <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                        <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono">{{ $record->mode_config }} Detik</span>
                                        <p class="mt-1 font-mono text-xl font-bold text-brand-bright tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="py-2 font-mono text-xs text-muted">Belum ada riwayat rekor untuk mode waktu.</p>
                        @endif
                    </div>
                </div>

                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'words' ? '' : 'words')"
                            class="flex items-center justify-between w-full p-5 font-sans text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">🔤</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">Words Mode</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">Rekor berdasarkan volume target jumlah kata</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'words' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'words'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        @if($wordsRecords->count() > 0)
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @foreach($wordsRecords as $record)
                                    <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                        <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono">{{ $record->mode_config }} Kata</span>
                                        <p class="mt-1 font-mono text-xl font-bold text-gold tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="py-2 font-mono text-xs text-muted">Belum ada riwayat rekor untuk mode jumlah kata.</p>
                        @endif
                    </div>
                </div>

                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'survival' ? '' : 'survival')"
                            class="flex items-center justify-between w-full p-5 font-sans text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">❤️</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">Survival Mode</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">Rekor durasi bertahan terlama tanpa kehabisan nyawa</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'survival' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'survival'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        @if($survivalRecords->count() > 0)
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                @foreach($survivalRecords as $record)
                                    <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                        <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono capitalize">{{ $record->mode_config }} Difficulty</span>
                                        <p class="mt-1 font-mono text-xl font-bold text-gold tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="py-2 font-mono text-xs text-muted">Belum ada riwayat rekor untuk mode survival.</p>
                        @endif
                    </div>
                </div>

                @if($quoteRecords->count() > 0)
                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'quote' ? '' : 'quote')"
                            class="flex items-center justify-between w-full p-5 font-sans text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">💬</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">Quote Mode</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">Rekor pengetikan kalimat kutipan utuh</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'quote' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'quote'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach($quoteRecords as $record)
                                <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                    <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono capitalize">{{ $record->mode_config }}</span>
                                    <p class="mt-1 font-mono text-xl font-bold text-brand-bright tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
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