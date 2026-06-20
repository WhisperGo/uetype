<div class="min-h-screen bg-typing-bg text-typing-muted font-mono selection:bg-typing-accent selection:text-typing-bg outline-none flex py-12"
    x-data
    @keydown.window="if($event.key === 'Tab') { $event.preventDefault(); document.getElementById('restartButton').focus(); }">
    <div class="max-w-5xl w-full px-4 m-auto">

        <!-- Header -->
        <div class="flex items-center justify-center gap-3 mb-2">
            <span class="font-sans text-xs uppercase tracking-[0.3em] text-typing-muted">hasil</span>
        </div>
        <div class="flex items-center gap-3 mb-8 text-lg tracking-widest justify-center">
            <span class="text-typing-accent">{{ $mode }}</span>
            <span class="text-typing-muted">/</span>
            <span class="text-typing-accent">{{ $subMode }}</span>
        </div>

        <!-- SURVIVAL: skor utama = jumlah kata yang berhasil sebelum mati -->
        @if ($mode === 'survival')
            <div
                class="max-w-md mx-auto mb-6 bg-typing-surface/70 border border-typing-accent/30 rounded-2xl p-6 flex flex-col items-center shadow-glow">
                <span class="font-sans text-xs uppercase tracking-[0.25em] text-typing-muted mb-1">kata bertahan</span>
                <span class="text-6xl text-typing-accent font-bold leading-none">{{ $score ?? 0 }}</span>
                <span class="font-sans text-[0.65rem] uppercase tracking-[0.15em] text-typing-muted mt-2">skor survival</span>
            </div>
        @endif

        <!-- Main stat cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
            <!-- Net WPM = metrik utama (blueprint Scoring 2) -->
            <div
                class="col-span-2 md:col-span-1 bg-typing-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center shadow-glow">
                <span class="font-sans text-xs uppercase tracking-[0.2em] text-typing-muted mb-1">wpm</span>
                <span class="text-5xl md:text-6xl text-typing-accent font-bold leading-none">{{ $wpm }}</span>
                <span class="font-sans text-[0.65rem] uppercase tracking-[0.15em] text-typing-muted mt-1">net</span>
            </div>
            <div class="bg-typing-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                <span class="font-sans text-xs uppercase tracking-[0.2em] text-typing-muted mb-1">accuracy</span>
                <span class="text-5xl md:text-6xl text-typing-accent2 font-bold leading-none">{{ $accuracy }}<span
                        class="text-2xl">%</span></span>
            </div>
            <!-- Raw WPM = stat sampingan -->
            <div class="bg-typing-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                <span class="font-sans text-xs uppercase tracking-[0.2em] text-typing-muted mb-1">raw wpm</span>
                <span class="text-4xl text-typing-text font-bold leading-none">{{ $rawWpm }}</span>
            </div>
            <div class="bg-typing-surface/70 border border-white/5 rounded-2xl p-5 flex flex-col justify-center">
                <span class="font-sans text-xs uppercase tracking-[0.2em] text-typing-muted mb-1">waktu</span>
                <span class="text-4xl text-typing-text font-bold leading-none">{{ round($time, 2) }}<span
                        class="text-xl text-typing-muted">s</span></span>
            </div>
        </div>

        <!-- Secondary stat row: rincian karakter (benar / salah / total) -->
        <div class="grid grid-cols-3 gap-3 mb-6">
            <div class="bg-typing-surface/40 border border-white/5 rounded-xl px-5 py-3 flex flex-col">
                <span class="font-sans text-[0.65rem] uppercase tracking-[0.2em] text-typing-muted mb-1">benar</span>
                <span class="text-2xl text-typing-text font-bold leading-none">{{ $correctKeystrokes }}</span>
            </div>
            <div class="bg-typing-surface/40 border border-white/5 rounded-xl px-5 py-3 flex flex-col">
                <span class="font-sans text-[0.65rem] uppercase tracking-[0.2em] text-typing-muted mb-1">salah</span>
                <span class="text-2xl text-typing-error font-bold leading-none">{{ $incorrectKeystrokes }}</span>
            </div>
            <div class="bg-typing-surface/40 border border-white/5 rounded-xl px-5 py-3 flex flex-col">
                <span class="font-sans text-[0.65rem] uppercase tracking-[0.2em] text-typing-muted mb-1">total tuts</span>
                <span class="text-2xl text-typing-text font-bold leading-none">{{ $totalKeystrokes }}</span>
            </div>
        </div>

        <!-- Chart -->
        <div class="mt-6 bg-typing-surface/40 border border-white/5 rounded-2xl p-4 md:p-6">
            <h3 class="font-sans text-xs uppercase tracking-[0.2em] text-typing-muted mb-3">progres wpm</h3>
            <div class="w-full h-56 md:h-64" wire:ignore>
                <canvas id="wpmChart"></canvas>
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
                                    borderColor: '#22d3ee',
                                    backgroundColor: 'rgba(34, 211, 238, 0.12)',
                                    fill: true,
                                    borderWidth: 3,
                                    tension: 0.4,
                                    pointRadius: 2,
                                    pointBackgroundColor: '#22d3ee',
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
                                    bodyColor: '#22d3ee',
                                    borderColor: '#22d3ee',
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
            class="mt-6 bg-typing-surface/40 border border-white/5 rounded-2xl p-4 md:p-6 flex flex-col items-center gap-2">
            <h3 class="font-sans text-xs uppercase tracking-[0.2em] text-typing-muted mb-4 self-start">heatmap kesalahan
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
                                        class="absolute -top-10 bg-typing-surface text-typing-error px-2 py-1 rounded text-xs opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap z-10 shadow-lg border border-typing-error/50">
                                        {{ $missCount }} salah
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-10 flex justify-center">
            <a id="restartButton" href="/typing" wire:navigate
                class="flex items-center gap-2 px-6 py-3 rounded-xl bg-typing-accent text-typing-bg font-sans font-semibold text-sm hover:shadow-glow focus:scale-105 transition-all transform hover:scale-105 outline-none group"
                title="Tes Berikutnya">
                <span>tes berikutnya</span>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </div>
    </div>
</div>
