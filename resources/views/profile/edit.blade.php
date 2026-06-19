<x-app-layout>
    <x-slot name="header">
        <h2 class="font-sans font-semibold text-xl text-typing-text tracking-tight">
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
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">

            <!-- ===== IDENTITY HEADER ===== -->
            <div class="relative overflow-hidden bg-typing-surface/70 border border-white/10 rounded-3xl p-6 sm:p-8 shadow-glow">
                <div class="absolute -top-16 -right-16 w-48 h-48 rounded-full bg-typing-accent/10 blur-3xl"></div>
                <div class="relative flex flex-col sm:flex-row sm:items-center gap-6">
                    <div class="flex items-center justify-center w-20 h-20 rounded-2xl bg-gradient-to-br from-typing-accent to-typing-accent2 text-typing-bg text-3xl font-bold uppercase shrink-0">
                        {{ \Illuminate\Support\Str::substr($user->username, 0, 1) }}
                    </div>
                    <div class="flex-1">
                        <div class="flex items-center gap-3 flex-wrap">
                            <h1 class="font-sans text-2xl font-bold text-typing-text">{{ $user->username }}</h1>
                            @if($user->clan)
                                <span class="px-2 py-0.5 rounded-md bg-typing-accent/15 text-typing-accent text-xs font-mono font-semibold">
                                    [{{ $user->clan->tag }}] {{ ucfirst($user->clan_role ?? 'member') }}
                                </span>
                            @endif
                            <span class="px-2 py-0.5 rounded-md bg-white/5 text-typing-muted text-xs font-mono">Level {{ $stats['level'] }}</span>
                        </div>
                        <p class="text-typing-muted text-sm mt-1 font-mono">{{ $user->email }}</p>
                        <p class="text-typing-muted text-xs mt-1">
                            Bergabung {{ optional($user->joined_at ?? $user->created_at)->translatedFormat('d F Y') }}
                        </p>
                        <div class="mt-3 max-w-xs">
                            <div class="flex justify-between text-[0.65rem] text-typing-muted font-mono mb-1">
                                <span>{{ $stats['level_progress'] }} / 1000 XP</span>
                                <span>Level {{ $stats['level'] + 1 }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                                <div class="h-full rounded-full bg-gradient-to-r from-typing-accent to-typing-accent2" style="width: {{ ($stats['level_progress'] / 1000) * 100 }}%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="text-center sm:text-right shrink-0 border-t sm:border-t-0 sm:border-l border-white/10 pt-4 sm:pt-0 sm:pl-6">
                        <p class="text-xs uppercase tracking-[0.2em] text-typing-muted font-sans">ELO</p>
                        <p class="text-4xl font-bold text-typing-accent2 font-mono leading-none mt-1">{{ $user->elo_rating ?? 0 }}</p>
                        <p class="text-xs text-typing-muted mt-1">Ranking</p>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-white/10">
                <nav class="-mb-px flex gap-6" aria-label="Tabs">
                    <button @click="activeTab = 'stats'"
                            :class="activeTab === 'stats' ? 'border-typing-accent text-typing-accent' : 'border-transparent text-typing-muted hover:text-typing-text'"
                            class="font-sans whitespace-nowrap py-3 px-1 border-b-2 text-sm font-semibold transition-colors">
                        Statistik
                    </button>
                    <button @click="activeTab = 'settings'"
                            :class="activeTab === 'settings' ? 'border-typing-accent text-typing-accent' : 'border-transparent text-typing-muted hover:text-typing-text'"
                            class="font-sans whitespace-nowrap py-3 px-1 border-b-2 text-sm font-semibold transition-colors">
                        Pengaturan Akun
                    </button>
                </nav>
            </div>

            <!-- ===== STATS TAB ===== -->
            <div x-show="activeTab === 'stats'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-6">

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    @php
                        $cards = [
                            ['label' => 'WPM Tertinggi', 'value' => rtrim(rtrim(number_format($user->highest_wpm, 1), '0'), '.'), 'accent' => 'text-typing-accent'],
                            ['label' => 'Rata-rata WPM', 'value' => $stats['avg_wpm'], 'accent' => 'text-typing-accent2'],
                            ['label' => 'Rata-rata Akurasi', 'value' => $stats['avg_accuracy'].'%', 'accent' => 'text-typing-success'],
                            ['label' => 'Total Tes', 'value' => $stats['total_matches'], 'accent' => 'text-typing-text'],
                        ];
                    @endphp
                    @foreach($cards as $card)
                        <div class="bg-typing-surface/60 border border-white/5 rounded-2xl p-5">
                            <p class="text-xs uppercase tracking-[0.15em] text-typing-muted font-sans mb-1">{{ $card['label'] }}</p>
                            <p class="text-3xl font-bold font-mono {{ $card['accent'] }}">{{ $card['value'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <div class="bg-typing-surface/40 border border-white/5 rounded-2xl p-5">
                        <p class="text-xs uppercase tracking-[0.15em] text-typing-muted font-sans mb-1">Total Waktu</p>
                        <p class="text-2xl font-bold font-mono text-typing-text">{{ $timeLabel }}</p>
                    </div>
                    <div class="bg-typing-surface/40 border border-white/5 rounded-2xl p-5">
                        <p class="text-xs uppercase tracking-[0.15em] text-typing-muted font-sans mb-1">XP</p>
                        <p class="text-2xl font-bold font-mono text-typing-success">{{ $user->xp ?? 0 }}</p>
                    </div>
                    <div class="bg-typing-surface/40 border border-white/5 rounded-2xl p-5">
                        <p class="text-xs uppercase tracking-[0.15em] text-typing-muted font-sans mb-1">Koin</p>
                        <p class="text-2xl font-bold font-mono text-yellow-400">{{ $user->coins ?? 0 }}</p>
                    </div>
                    <div class="bg-typing-surface/40 border border-white/5 rounded-2xl p-5">
                        <p class="text-xs uppercase tracking-[0.15em] text-typing-muted font-sans mb-1">Best WPM</p>
                        <p class="text-2xl font-bold font-mono text-typing-accent">{{ $stats['best_wpm'] }}</p>
                    </div>
                </div>

                <div class="bg-typing-surface/40 border border-white/5 rounded-2xl p-5 sm:p-6">
                    <h3 class="font-sans text-sm font-semibold text-typing-text mb-4">Progres WPM (tes terakhir)</h3>
                    @if(count($wpmProgress) >= 2)
                        <div class="w-full h-48" wire:ignore>
                            <canvas id="profileWpmChart"></canvas>
                        </div>
                    @else
                        <p class="text-typing-muted text-sm font-mono">Belum cukup data. Selesaikan beberapa tes untuk melihat progresmu.</p>
                    @endif
                </div>

                <div class="bg-typing-surface/40 border border-white/5 rounded-2xl p-5 sm:p-6">
                    <h3 class="font-sans text-sm font-semibold text-typing-text mb-4">Riwayat Pertandingan Terakhir</h3>
                    @if(isset($recentMatches) && $recentMatches->count() > 0)
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead>
                                    <tr class="text-typing-muted text-xs uppercase tracking-wider font-sans">
                                        <th class="pb-3 font-semibold">Tanggal</th>
                                        <th class="pb-3 font-semibold">Mode</th>
                                        <th class="pb-3 font-semibold text-right">WPM</th>
                                        <th class="pb-3 font-semibold text-right">Akurasi</th>
                                    </tr>
                                </thead>
                                <tbody class="font-mono text-sm">
                                    @foreach($recentMatches as $p)
                                    <tr class="border-t border-white/5">
                                        <td class="py-2.5 text-typing-muted">{{ $p->created_at ? $p->created_at->format('d M Y H:i') : '-' }}</td>
                                        <td class="py-2.5 text-typing-text capitalize">
                                            {{ $p->match?->mode_played ?? 'practice' }}
                                            @if($p->is_suspicious)
                                                <span class="ml-1 text-typing-error" title="Ditandai mencurigakan">⚠</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 text-right text-typing-accent font-bold">{{ rtrim(rtrim(number_format($p->wpm, 1), '0'), '.') }}</td>
                                        <td class="py-2.5 text-right text-typing-text">{{ rtrim(rtrim(number_format($p->accuracy, 1), '0'), '.') }}%</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @else
                        <p class="text-typing-muted text-sm font-mono">Belum ada riwayat mengetik. <a href="{{ url('/typing') }}" class="text-typing-accent hover:underline">Mulai tes pertamamu →</a></p>
                    @endif
                </div>
            </div>

            <!-- ===== SETTINGS TAB ===== -->
            <div x-show="activeTab === 'settings'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" style="display: none;" class="space-y-6">
                <div class="p-6 sm:p-8 bg-typing-surface/60 border border-white/5 rounded-2xl">
                    <div class="max-w-xl">@include('profile.partials.update-profile-information-form')</div>
                </div>
                <div class="p-6 sm:p-8 bg-typing-surface/60 border border-white/5 rounded-2xl">
                    <div class="max-w-xl">@include('profile.partials.update-password-form')</div>
                </div>
                <div class="p-6 sm:p-8 bg-typing-surface/60 border border-white/5 rounded-2xl">
                    <div class="max-w-xl">@include('profile.partials.delete-user-form')</div>
                </div>
            </div>
        </div>
    </div>

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
                            borderColor: '#22d3ee',
                            backgroundColor: 'rgba(34,211,238,0.12)',
                            fill: true, borderWidth: 3, tension: 0.4,
                            pointRadius: 2, pointBackgroundColor: '#22d3ee',
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
