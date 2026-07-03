<div class="text-muted font-mono selection:bg-brand selection:text-foreground outline-none py-16"
    x-data
    @keydown.window="if($event.key === 'Tab') { $event.preventDefault(); document.getElementById('restartButton').focus(); }">
    <div class="max-w-6xl w-full px-4 mx-auto">

        @php
            $isSurvival = $mode === 'survival';
            $mins = intdiv((int) $time, 60);
            $secs = (int) $time % 60;
            $heroValue = $isSurvival ? sprintf('%d:%02d', $mins, $secs) : $wpm;
            $heroLabel = $isSurvival ? 'survived' : 'wpm';

            $modeLabel = match ($mode) {
                'time' => 'Time · ' . $subMode . 's',
                'words' => 'Words · ' . $subMode,
                'quote' => 'Quote',
                'survival' => 'Survival · ' . ucfirst($subMode),
                default => ucfirst($mode) . ' · ' . $subMode,
            };

            $recordDelta = null;
            if (!$isSurvival && !$isPersonalBest && $previousBest > 0) {
                $recordDelta = round($wpm - $previousBest, 1);
            }

            $wordsTyped = (int) floor(($correctKeystrokes ?? 0) / 5);

            $pbSeconds = $survivalPreviousBest !== null ? (int) round($survivalPreviousBest) : null;
            $pbMins = $pbSeconds !== null ? intdiv($pbSeconds, 60) : null;
            $pbSecsPart = $pbSeconds !== null ? $pbSeconds % 60 : null;
            $survivalDelta = $pbSeconds !== null ? $pbSeconds - (int) $time : null;
        @endphp

        @if ($isSurvival)
            <div x-data="{
                    duration: 0,
                    target: {{ (int) $time }},
                    animate() {
                        const start = performance.now();
                        const step = (now) => {
                            const t = Math.min(1, (now - start) / 900);
                            this.duration = Math.round(this.target * (1 - Math.pow(1 - t, 3)));
                            if (t < 1) requestAnimationFrame(step);
                        };
                        requestAnimationFrame(step);
                    },
                    get clock() {
                        const m = Math.floor(this.duration / 60);
                        const s = this.duration % 60;
                        return m + ':' + String(s).padStart(2, '0');
                    }
                }"
                x-init="animate()"
                class="max-w-2xl mx-auto flex flex-col gap-8">

                <div class="relative overflow-hidden rounded-3xl border border-border bg-surface/60 px-8 py-10 text-center">
                    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-transparent via-danger to-transparent opacity-70"></div>

                    <p class="font-sans text-xs uppercase tracking-[0.35em] text-danger/80 mb-4">game over</p>

                    <div class="flex flex-col items-center gap-1">
                        <span class="font-display text-5xl md:text-6xl text-gold leading-none tabular-nums"
                            x-text="clock">{{ sprintf('%d:%02d', $mins, $secs) }}</span>
                        <span class="font-sans text-xs uppercase tracking-[0.3em] text-muted mt-3">survived</span>
                    </div>

                    <div class="mt-6 flex items-center justify-center gap-2 text-x-small">
                        <span class="px-3 py-1 rounded-full border border-border text-muted uppercase tracking-[0.2em]">{{ ucfirst($subMode) }}</span>
                        <span class="text-muted/70">stamina habis di {{ sprintf('%d:%02d', $mins, $secs) }}</span>
                    </div>

                    @if ($isSurvivalPersonalBest)
                        <p class="mt-6 inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-gold/10 border border-gold/40 text-gold font-mono text-sm">
                            <span>★</span> new personal best
                        </p>
                    @elseif ($pbSeconds !== null)
                        <p class="mt-6 font-mono text-sm text-muted tabular-nums">
                            PB {{ sprintf('%d:%02d', $pbMins, $pbSecsPart) }}
                            @if ($survivalDelta > 0)
                                <span class="text-muted/70">· kurang {{ $survivalDelta }}s lagi</span>
                            @endif
                        </p>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @php
                        $survivalStats = [
                            ['avg wpm', $wpm, 'text-foreground'],
                            ['accuracy', $accuracy . '%', 'text-gold'],
                            ['words', $wordsTyped, 'text-foreground'],
                            ['drain events', $drainEventCount, 'text-danger'],
                        ];
                    @endphp
                    @foreach ($survivalStats as [$label, $value, $tone])
                        <div class="rounded-2xl bg-surface/70 border border-white/5 p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">{{ $label }}</span>
                            <span class="text-2xl font-bold font-mono leading-none tabular-nums {{ $tone }}">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                @auth
                    @if ($levelData)
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex flex-col gap-2">
                                    <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">xp earned</span>
                                    <span class="text-2xl font-bold font-mono text-brand-bright leading-none">+{{ $xpEarned }} XP</span>
                                </div>
                                <div class="text-right font-mono text-xs text-muted leading-relaxed">
                                    <div>{{ number_format($levelData['progress']) }} / {{ number_format($levelData['needed']) }} XP</div>
                                    <div>Level {{ $levelData['level'] }} → {{ $levelData['next_level'] }}</div>
                                </div>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-brand-bright transition-all"
                                    style="width: {{ $levelData['needed'] > 0 ? min(100, ($levelData['progress'] / $levelData['needed']) * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endif
                @endauth

                <a id="restartButton" href="/typing" wire:navigate
                    class="inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-sans font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition">
                    <span>main lagi</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>
        @else

        <!-- 2 kolom: kiri stats & aksi, kanan chart -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 items-start">

            <!-- ===== KOLOM KIRI ===== -->
            <div class="flex flex-col gap-6">

                <!-- Hero -->
                <div>
                    <p class="font-mono text-xs uppercase tracking-[0.2em] text-muted mb-2">{{ $modeLabel }}</p>
                    <div class="flex items-end gap-3">
                        <span class="font-display text-6xl md:text-7xl text-gold leading-none tabular-nums">{{ $heroValue }}</span>
                        <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted pb-1.5">{{ $heroLabel }}</span>
                    </div>
                    @if ($isPersonalBest)
                        <p class="mt-3 flex items-center gap-1.5 text-sm text-gold font-mono">
                            <span>✦</span> new personal best
                        </p>
                    @elseif (!is_null($recordDelta))
                        <p class="mt-3 font-mono text-sm text-muted tabular-nums">
                            {{ $recordDelta >= 0 ? '+' : '' }}{{ $recordDelta }} <span class="text-muted/70">vs rekor {{ rtrim(rtrim(number_format($previousBest, 1), '0'), '.') }}</span>
                        </p>
                    @endif
                </div>

                <!-- Sub-stats -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    @if ($isSurvival)
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">avg wpm</span>
                            <span class="text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $wpm }}</span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">accuracy</span>
                            <span class="text-2xl text-gold font-bold font-mono leading-none tabular-nums">{{ $accuracy }}<span class="text-lg">%</span></span>
                        </div>
                        @if (!is_null($consistency))
                            <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                                <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">consistency</span>
                                <span class="text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $consistency }}<span class="text-lg">%</span></span>
                            </div>
                        @endif
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">characters</span>
                            <span class="text-2xl font-bold font-mono leading-none tabular-nums">
                                <span class="text-foreground">{{ $correctKeystrokes }}</span><span class="text-muted"> / </span><span class="text-danger">{{ $incorrectKeystrokes }}</span>
                            </span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">difficulty</span>
                            <span class="text-2xl text-foreground font-bold font-mono leading-none capitalize">{{ $subMode }}</span>
                        </div>
                    @else
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">raw wpm</span>
                            <span class="text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $rawWpm }}</span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">accuracy</span>
                            <span class="text-2xl text-gold font-bold font-mono leading-none tabular-nums">{{ $accuracy }}<span class="text-lg">%</span></span>
                        </div>
                        @if (!is_null($consistency))
                            <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                                <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">consistency</span>
                                <span class="text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $consistency }}<span class="text-lg">%</span></span>
                            </div>
                        @endif
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">duration</span>
                            <span class="text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ round($time, 1) }}<span class="text-lg text-muted">s</span></span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2 {{ is_null($consistency) ? 'col-span-2 sm:col-span-1' : '' }}">
                            <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">characters</span>
                            <span class="text-2xl font-bold font-mono leading-none tabular-nums">
                                <span class="text-foreground">{{ $correctKeystrokes }}</span><span class="text-muted"> / </span><span class="text-danger">{{ $incorrectKeystrokes }}</span>
                            </span>
                        </div>
                    @endif
                </div>

                <!-- XP + level bar -->
                @auth
                    @if ($levelData)
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex flex-col gap-2">
                                    <span class="font-sans text-xs uppercase tracking-[0.2em] text-muted">xp earned</span>
                                    <span class="text-2xl font-bold font-mono text-brand leading-none">+{{ $xpEarned }} XP</span>
                                </div>
                                <div class="text-right font-mono text-xs text-muted leading-relaxed">
                                    <div>{{ number_format($levelData['progress']) }} / {{ number_format($levelData['needed']) }} XP</div>
                                    <div>Level {{ $levelData['level'] }} → {{ $levelData['next_level'] }}</div>
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
                <div class="flex items-stretch gap-3">
                    <a id="restartButton" href="/typing" wire:navigate
                        class="flex-1 inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-sans font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition"
                        title="{{ $isSurvival ? 'Main Lagi' : 'Tes Berikutnya' }}">
                        <span>{{ $isSurvival ? 'play again' : 'next test' }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                    @unless ($isSurvival)
                        <a href="/typing" wire:navigate
                            class="inline-flex items-center justify-center h-12 px-6 rounded-2xl bg-surface border border-white/5 text-foreground/80 hover:text-foreground hover:border-white/10 font-sans font-semibold text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-border transition"
                            title="Ulangi Tes">
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

        @endif
    </div>
</div>
