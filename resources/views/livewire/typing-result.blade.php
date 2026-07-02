<div class="text-muted font-mono selection:bg-brand selection:text-foreground outline-none py-12"
    x-data
    @keydown.window="if($event.key === 'Tab') { $event.preventDefault(); document.getElementById('restartButton').focus(); }">
    <div class="max-w-6xl w-full px-4 mx-auto">

        @php
            $isSurvival = $mode === 'survival';
            $mins = intdiv((int) $time, 60);
            $secs = (int) $time % 60;
            $heroValue = $isSurvival ? sprintf('%d:%02d', $mins, $secs) : $wpm;
            $heroLabel = $isSurvival ? 'survived' : 'wpm';
        @endphp

        <!-- 2 kolom: kiri stats & aksi, kanan chart -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">

            <!-- ===== KOLOM KIRI ===== -->
            <div class="flex flex-col gap-6">

                <!-- Hero -->
                <div>
                    <div class="flex items-end gap-3">
                        <span class="font-display text-5xl md:text-6xl text-gold leading-none">{{ $heroValue }}</span>
                        <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted pb-1">{{ $heroLabel }}</span>
                    </div>
                    @if ($isPersonalBest)
                        <p class="mt-3 flex items-center gap-1.5 text-sm text-gold font-mono">
                            <span>✦</span> new personal best
                        </p>
                    @endif
                    <p class="mt-2 font-mono text-xs uppercase tracking-[0.2em] text-muted">
                        {{ $mode }} · {{ $subMode }}
                    </p>
                </div>

                <!-- Sub-stats 2x2 -->
                <div class="grid grid-cols-2 gap-3">
                    @if ($isSurvival)
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">avg wpm</span>
                            <span class="text-3xl text-foreground font-bold font-mono leading-none">{{ $wpm }}</span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">accuracy</span>
                            <span class="text-3xl text-gold font-bold font-mono leading-none">{{ $accuracy }}<span class="text-xl">%</span></span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">correct</span>
                            <span class="text-3xl text-foreground font-bold font-mono leading-none">{{ $correctKeystrokes }}</span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">difficulty</span>
                            <span class="text-2xl text-foreground font-bold font-mono leading-none capitalize">{{ $subMode }}</span>
                        </div>
                    @else
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">raw wpm</span>
                            <span class="text-3xl text-foreground font-bold font-mono leading-none">{{ $rawWpm }}</span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">accuracy</span>
                            <span class="text-3xl text-gold font-bold font-mono leading-none">{{ $accuracy }}<span class="text-xl">%</span></span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">duration</span>
                            <span class="text-3xl text-foreground font-bold font-mono leading-none">{{ round($time, 1) }}<span class="text-xl text-muted">s</span></span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-1">correct</span>
                            <span class="text-3xl text-foreground font-bold font-mono leading-none">{{ $correctKeystrokes }}</span>
                        </div>
                    @endif
                </div>

                <!-- XP + level bar -->
                @auth
                    @if ($levelData)
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-5">
                            <div class="flex items-baseline justify-between mb-2">
                                <div class="flex flex-col">
                                    <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">xp earned</span>
                                    <span class="text-2xl font-bold font-mono text-brand leading-none mt-1">+{{ $xpEarned }} XP</span>
                                </div>
                                <div class="text-right font-mono text-xs text-muted">
                                    <div>{{ number_format($levelData['progress']) }} / {{ number_format($levelData['needed']) }} XP</div>
                                    <div class="mt-1">Level {{ $levelData['level'] }} → {{ $levelData['next_level'] }}</div>
                                </div>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-brand transition-all"
                                    style="width: {{ $levelData['needed'] > 0 ? min(100, ($levelData['progress'] / $levelData['needed']) * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endif
                @endauth

                <!-- Tombol aksi -->
                <div class="flex items-center gap-3">
                    <a id="restartButton" href="/typing" wire:navigate
                        class="flex items-center gap-2 px-6 py-3 rounded-xl bg-gold text-background font-sans font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-1 focus-visible:ring-border transition-all"
                        title="{{ $isSurvival ? 'Main Lagi' : 'Tes Berikutnya' }}">
                        <span>{{ $isSurvival ? 'play again' : 'next test' }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                    @unless ($isSurvival)
                        <a href="/typing" wire:navigate
                            class="px-6 py-3 rounded-xl bg-surface border border-white/5 text-muted hover:text-foreground font-sans font-semibold text-sm focus:outline-none focus-visible:ring-1 focus-visible:ring-border transition">
                            retry
                        </a>
                    @endunless
                </div>
            </div>

            <!-- ===== KOLOM KANAN: chart ===== -->
            <div class="bg-surface/40 border border-white/5 rounded-2xl p-4 md:p-6">
                <h3 class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-3">{{ $isSurvival ? 'stamina' : 'performance' }}</h3>
                <div class="w-full h-72 lg:h-96" wire:ignore>
                    <canvas id="wpmChart"></canvas>
                </div>
            </div>
        </div>

        @script
            <script>
                const wpmData = @json($wpmHistory);
                const rawData = @json($rawHistory);
                const labelsData = Array.from({
                    length: wpmData.length
                }, (_, i) => i + 1);

                const renderChart = () => {
                    const canvas = document.getElementById('wpmChart');
                    if (!canvas) return;

                    const ctx = canvas.getContext('2d');
                    if (window.myWpmChart) {
                        window.myWpmChart.destroy();
                    }

                    window.myWpmChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: labelsData,
                            datasets: [{
                                    label: 'wpm',
                                    data: wpmData,
                                    borderColor: '#C69F68',
                                    backgroundColor: 'rgba(198, 159, 104, 0.12)',
                                    fill: true,
                                    borderWidth: 3,
                                    tension: 0.4,
                                    pointRadius: 2,
                                    pointBackgroundColor: '#C69F68',
                                },
                                {
                                    label: 'raw',
                                    data: rawData,
                                    borderColor: '#475569',
                                    borderWidth: 2,
                                    tension: 0.4,
                                    pointRadius: 0,
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false,
                            },
                            scales: {
                                x: {
                                    grid: {
                                        color: 'rgba(255,255,255,0.04)'
                                    },
                                    ticks: {
                                        color: '#94a3b8',
                                        autoSkip: true,
                                        maxTicksLimit: 10,
                                        maxRotation: 0
                                    }
                                },
                                y: {
                                    grid: {
                                        color: 'rgba(255,255,255,0.04)'
                                    },
                                    ticks: {
                                        color: '#94a3b8'
                                    },
                                    beginAtZero: true
                                }
                            },
                            plugins: {
                                legend: {
                                    display: false
                                },
                                tooltip: {
                                    backgroundColor: '#1e293b',
                                    titleColor: '#e2e8f0',
                                    bodyColor: '#C69F68',
                                    borderColor: '#C69F68',
                                    borderWidth: 1
                                }
                            }
                        }
                    });
                };

                if (typeof Chart === 'undefined') {
                    const script = document.createElement('script');
                    script.src = 'https://cdn.jsdelivr.net/npm/chart.js';
                    script.onload = renderChart;
                    document.head.appendChild(script);
                } else {
                    renderChart();
                }
            </script>
        @endscript

        <!-- Keyboard Heatmap -->
        @php
            $keyboard = [
                ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p', '[', ']'],
                ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l', ';', "'"],
                ['z', 'x', 'c', 'v', 'b', 'n', 'm', ',', '.', '/'],
            ];
            $maxMiss = count($missedChars) > 0 ? max($missedChars) : 0;
        @endphp

        <div
            class="mt-8 bg-surface/40 border border-white/5 rounded-2xl p-4 md:p-6 flex flex-col items-center gap-2">
            <h3 class="font-sans text-xs uppercase tracking-[0.2em] text-muted mb-4 self-start">heatmap kesalahan
            </h3>
            <div class="flex flex-col gap-2 md:gap-3">
                @foreach ($keyboard as $rowIndex => $row)
                    <div class="flex justify-center gap-2 md:gap-3" style="margin-left: {{ $rowIndex * 1.5 }}rem;">
                        @foreach ($row as $key)
                            @php
                                $missCount = $missedChars[$key] ?? 0;
                                $opacity = $maxMiss > 0 && $missCount > 0 ? 0.3 + ($missCount / $maxMiss) * 0.7 : 0;
                                $style =
                                    $opacity > 0
                                        ? "background-color: rgba(244, 63, 94, {$opacity}); color: #e2e8f0;"
                                        : 'background-color: rgba(255,255,255,0.04); color: #94a3b8;';
                            @endphp
                            <div class="w-10 h-10 md:w-12 md:h-12 rounded-lg flex items-center justify-center text-sm md:text-base font-bold transition-colors relative group"
                                style="{{ $style }}">
                                {{ strtoupper($key) }}

                                @if ($missCount > 0)
                                    <div
                                        class="absolute -top-10 bg-surface text-danger px-2 py-1 rounded text-xs opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap z-10 shadow-lg border border-danger/50">
                                        {{ $missCount }} salah
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
