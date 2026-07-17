<div class="text-muted font-mono selection:bg-brand selection:text-foreground outline-none py-16"
    x-data
    @keydown.window="if($event.key === 'Tab') { $event.preventDefault(); document.getElementById('restartButton').focus(); }">
    <div class="max-w-6xl w-full px-4 mx-auto">

        @php
            $isSurvival = $mode === 'survival';
            $mins = intdiv((int) $time, 60);
            $secs = (int) $time % 60;
            $heroValue = $isSurvival ? sprintf('%d:%02d', $mins, $secs) : $wpm;
            $heroLabel = $isSurvival ? __('result.survived') : __('result.wpm');

            $modeLabel = match ($mode) {
                'time' => __('result.mode.time', ['config' => $subMode]),
                'words' => __('result.mode.words', ['config' => $subMode]),
                'survival' => __('result.mode.survival', ['config' => ucfirst($subMode)]),
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

                    <p class="font-mono text-xs uppercase tracking-[0.35em] text-danger/80 mb-4">{{ __('result.game_over') }}</p>

                    <div class="flex flex-col items-center gap-1">
                        <span class="font-display text-fluid-hero text-gold leading-none tabular-nums"
                            x-text="clock">{{ sprintf('%d:%02d', $mins, $secs) }}</span>
                        <span class="font-mono text-xs uppercase tracking-[0.3em] text-muted mt-3">{{ __('result.survived') }}</span>
                    </div>

                    <div class="mt-6 flex items-center justify-center gap-2 text-x-small">
                        <span class="px-3 py-1 rounded-full border border-border text-muted uppercase tracking-[0.2em]">{{ ucfirst($subMode) }}</span>
                        <span class="text-muted/70">{{ __('result.stamina_depleted_at', ['time' => sprintf('%d:%02d', $mins, $secs)]) }}</span>
                    </div>

                    @if ($isSurvivalPersonalBest)
                        <p class="mt-6 inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-gold/10 border border-gold/40 text-gold font-mono text-sm">
                            <span>★</span> {{ __('result.new_personal_best') }}
                        </p>
                    @elseif ($pbSeconds !== null)
                        <p class="mt-6 font-mono text-sm text-muted tabular-nums">
                            {{ __('result.pb', ['time' => sprintf('%d:%02d', $pbMins, $pbSecsPart)]) }}
                            @if ($survivalDelta > 0)
                                <span class="text-muted/70">· {{ __('result.pb_short_by', ['seconds' => $survivalDelta]) }}</span>
                            @endif
                        </p>
                    @endif
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    @php
                        $survivalStats = [
                            [__('result.stat.avg_wpm'), $wpm, 'text-foreground'],
                            [__('result.stat.accuracy'), $accuracy . '%', 'text-gold'],
                            [__('result.stat.words'), $wordsTyped, 'text-foreground'],
                            [__('result.stat.drain_events'), $drainEventCount, 'text-danger'],
                        ];
                    @endphp
                    @foreach ($survivalStats as [$label, $value, $tone])
                        <div class="rounded-2xl bg-surface/70 border border-white/5 p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ $label }}</span>
                            <span class="text-xl sm:text-2xl font-bold font-mono leading-none tabular-nums {{ $tone }}">{{ $value }}</span>
                        </div>
                    @endforeach
                </div>

                @auth
                    @if ($levelData)
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex flex-col gap-2">
                                    <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.xp_earned') }}</span>
                                    <span class="text-xl sm:text-2xl font-bold font-mono text-foreground leading-none">{{ __('result.xp_gained', ['amount' => $xpEarned]) }}</span>
                                </div>
                                <div class="text-right font-mono text-xs text-muted leading-relaxed">
                                    <div>{{ __('result.xp_progress', ['progress' => number_format($levelData['progress']), 'needed' => number_format($levelData['needed'])]) }}</div>
                                    <div>{{ __('result.xp_level_up', ['from' => $levelData['level'], 'to' => $levelData['next_level']]) }}</div>
                                </div>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-foreground transition-all"
                                    style="width: {{ $levelData['needed'] > 0 ? min(100, ($levelData['progress'] / $levelData['needed']) * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endif
                @endauth

                <a id="restartButton" href="/typing"
                    class="inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-mono font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition">
                    <span>{{ __('result.play_again_title') }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
            </div>
        @else

        {{-- Island error inspector: membungkus grid DAN heatmap (keduanya bersaudara) supaya
             klik titik di grafik bisa menyorot tuts + menampilkan katanya. Tanpa class layout
             -- grid tetap memegang display:grid sendiri, mt-8 heatmap tetap margin sibling. --}}
        <div x-data="errorInspector(@js($this->errorSeries['events']))"
            x-on:error-inspect.window="select($event.detail.index)">

        <!-- 2 kolom: kiri stats & aksi, kanan chart -->
        {{-- TANPA items-start: tinggi baris grid ditentukan kolom kiri (yang berbeda tinggi
             antara guest & login), lalu kartu chart ikut meregang -> bottom kedua kolom rata. --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">

            <!-- ===== KOLOM KIRI ===== -->
            <div class="flex flex-col gap-6">

                <!-- Hero -->
                <div>
                    <p class="font-mono text-xs uppercase tracking-[0.2em] text-muted mb-2">{{ $modeLabel }}</p>
                    <div class="flex items-end gap-3">
                        <span class="font-display text-fluid-hero text-gold leading-none tabular-nums">{{ $heroValue }}</span>
                        <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted pb-1.5">{{ $heroLabel }}</span>
                    </div>
                    @if ($isPersonalBest)
                        <p class="mt-3 flex items-center gap-1.5 text-sm text-gold font-mono">
                            <span>✦</span> {{ __('result.new_personal_best') }}
                        </p>
                    @elseif (!is_null($recordDelta))
                        <p class="mt-3 font-mono text-sm text-muted tabular-nums">
                            {{ $recordDelta >= 0 ? '+' : '' }}{{ $recordDelta }} <span class="text-muted/70">{{ __('result.vs_record', ['best' => rtrim(rtrim(number_format($previousBest, 1), '0'), '.')]) }}</span>
                        </p>
                    @endif

                    @if ($ghostResult)
                        <p class="mt-3 flex items-center gap-1.5 text-sm font-mono {{ $ghostResult['playerWon'] ? 'text-gold' : 'text-muted' }}">
                            <span>{{ $ghostResult['playerWon'] ? '✦' : '·' }}</span>
                            {{ $ghostResult['playerWon'] ? __('result.beat_ghost') : __('result.lost_ghost') }}
                            <span class="text-muted/70">{{ __('result.vs_ghost', ['label' => $ghostResult['label'], 'wpm' => rtrim(rtrim(number_format($ghostResult['wpm'], 1), '0'), '.')]) }}</span>
                        </p>
                    @endif
                </div>

                <!-- Sub-stats -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    @if ($isSurvival)
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.avg_wpm') }}</span>
                            <span class="text-xl sm:text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $wpm }}</span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.accuracy') }}</span>
                            <span class="text-xl sm:text-2xl text-gold font-bold font-mono leading-none tabular-nums">{{ $accuracy }}<span class="text-lg">%</span></span>
                        </div>
                        @if (!is_null($consistency))
                            <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                                <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.consistency') }}</span>
                                <span class="text-xl sm:text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $consistency }}<span class="text-lg">%</span></span>
                            </div>
                        @endif
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.characters') }}</span>
                            <span class="text-xl sm:text-2xl font-bold font-mono leading-none tabular-nums">
                                <span class="text-foreground">{{ $correctKeystrokes }}</span><span class="text-muted"> / </span><span class="text-danger">{{ $incorrectKeystrokes }}</span>
                            </span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.difficulty') }}</span>
                            <span class="text-xl sm:text-2xl text-foreground font-bold font-mono leading-none capitalize">{{ $subMode }}</span>
                        </div>
                    @else
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.raw_wpm') }}</span>
                            <span class="text-xl sm:text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $rawWpm }}</span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.accuracy') }}</span>
                            <span class="text-xl sm:text-2xl text-gold font-bold font-mono leading-none tabular-nums">{{ $accuracy }}<span class="text-lg">%</span></span>
                        </div>
                        @if (!is_null($consistency))
                            <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                                <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.consistency') }}</span>
                                <span class="text-xl sm:text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ $consistency }}<span class="text-lg">%</span></span>
                            </div>
                        @endif
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.duration') }}</span>
                            <span class="text-xl sm:text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ round($time, 1) }}<span class="text-lg text-muted">s</span></span>
                        </div>
                        <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2 {{ is_null($consistency) ? 'col-span-2 sm:col-span-1' : '' }}">
                            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.characters') }}</span>
                            <span class="text-xl sm:text-2xl font-bold font-mono leading-none tabular-nums">
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
                                    <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.xp_earned') }}</span>
                                    <span class="text-xl sm:text-2xl font-bold font-mono text-foreground leading-none">{{ __('result.xp_gained', ['amount' => $xpEarned]) }}</span>
                                </div>
                                <div class="text-right font-mono text-xs text-muted leading-relaxed">
                                    <div>{{ __('result.xp_progress', ['progress' => number_format($levelData['progress']), 'needed' => number_format($levelData['needed'])]) }}</div>
                                    <div>{{ __('result.xp_level_up', ['from' => $levelData['level'], 'to' => $levelData['next_level']]) }}</div>
                                </div>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-foreground transition-all"
                                    style="width: {{ $levelData['needed'] > 0 ? min(100, ($levelData['progress'] / $levelData['needed']) * 100) : 0 }}%"></div>
                            </div>
                        </div>
                    @endif
                @endauth

                <!-- Tombol aksi -->
                <div class="flex items-stretch gap-3">
                    <a id="restartButton" href="/typing"
                        class="flex-1 inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-mono font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition"
                        title="{{ $isSurvival ? __('result.play_again_title') : __('result.next_test_title') }}">
                        <span>{{ $isSurvival ? __('result.play_again_title') : __('result.next_test_title') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                    {{-- Retry hanya untuk Words: mengulang rangkaian kata yang sama persis
                         (lewat retry() -> session typing_retry), berbeda dari Next Test yang acak. --}}
                    @if ($mode === 'words' && $textToType)
                        <button type="button" wire:click="retry"
                            class="inline-flex items-center justify-center h-12 px-6 rounded-2xl bg-surface border border-white/5 text-foreground/80 hover:text-foreground hover:border-white/10 font-mono font-semibold text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-border transition"
                            title="{{ __('result.retry_title') }}">
                            {{ __('result.retry_title') }}
                        </button>
                    @endif
                </div>
            </div>

            <!-- ===== KOLOM KANAN: chart ===== -->
            <div class="bg-surface/40 border border-white/5 rounded-2xl p-4 md:p-6 flex flex-col">
                <h3 class="font-mono text-xs uppercase tracking-[0.2em] text-muted mb-2">{{ $isSurvival ? __('result.chart_stamina') : __('result.chart_performance') }}</h3>

                {{-- Legenda HTML, bukan plugins.legend bawaan Chart.js: yang bawaan digambar di
                     canvas (tak bisa ikut font-mono/tracking token) dan item-nya bisa diklik
                     untuk menyembunyikan dataset -- afordans yang tak kita inginkan di sini.
                     Bentuknya sengaja sama dengan legenda heatmap di bawah.
                     Warna di-hardcode agar SAMA PERSIS dengan warna dataset di blok @script;
                     #C69F68 kebetulan = token gold, tapi #475569 & #F43F5E memang di luar token
                     (lihat catatan dataset). Saat design-cleanup jalan, ganti di kedua tempat. --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 font-mono text-[0.65rem] text-muted/60 mb-3">
                    <span class="inline-flex items-center gap-2">
                        <span class="w-4 h-[3px] rounded-full bg-gold"></span>
                        {{ __('result.wpm') }}
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="w-4 h-[2px] rounded-full" style="background-color: #475569;"></span>
                        {{ __('result.stat.raw_wpm') }}
                    </span>
                    {{-- Ikut aturan sumbu y1 (display: hasErrors): run bersih -> tak ada titik,
                         tak ada sumbu, jadi tak ada pula legendanya. --}}
                    @if (array_sum($this->errorSeries['counts']) > 0)
                        <span class="inline-flex items-center gap-2">
                            <span class="font-bold leading-none" style="color: #F43F5E;">✕</span>
                            {{ __('result.error_axis') }}
                        </span>
                    @endif
                </div>

                {{-- Canvas WAJIB absolute: out-of-flow berkontribusi nol ke intrinsic size, jadi
                     tinggi baris grid tak bergantung pada canvas. Kalau dibuat in-flow lagi,
                     dependensinya melingkar (baris <- card <- canvas <- Chart.js baca parent)
                     dan Chart.js masuk loop resize. flex-basis:0% tidak cukup memutusnya. --}}
                <div class="relative w-full h-72 lg:h-auto lg:flex-1 lg:min-h-0" wire:ignore>
                    <canvas id="wpmChart" class="absolute inset-0"></canvas>
                </div>
            </div>
        </div>

        @script
            <script>
                const wpmData = @json($wpmHistory);
                const rawData = @json($rawHistory);
                const errorCounts = @json($this->errorSeries['counts']);
                const hasErrors = errorCounts.some(c => c > 0);
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
                                },
                                {
                                    label: @js(__('result.error_axis')),
                                    // line + showLine:false, BUKAN type:'scatter'. Dataset ini harus
                                    // memakai jalur parsing yang sama dengan dua di atasnya: sumbu x
                                    // di sini skala CATEGORY (label 1..N) dengan array angka polos
                                    // ter-index posisi. ScatterController default-nya parsing {x,y} --
                                    // jalan, tapi lewat jalur beda, dan interaction.mode:'index'
                                    // bergantung padanya. showLine:false hasilnya identik.
                                    type: 'line',
                                    showLine: false,
                                    // null = titik tak digambar. Titik yang duduk di 0 cuma jadi
                                    // derau sepanjang sumbu; yang informatif justru KETIADAAN-nya.
                                    data: errorCounts.map(c => c > 0 ? c : null),
                                    yAxisID: 'y1',
                                    pointStyle: 'crossRot',   // X, bukan bulatan -- terbaca "error"
                                    pointRadius: 5,
                                    pointHoverRadius: 7,
                                    pointHitRadius: 12,
                                    // #F43F5E menyamai persis rgba(244,63,94) inline di heatmap.
                                    // SENGAJA memakai token typing.error yang deprecated, bukan
                                    // --color-danger: titik & heatmap adalah DATA YANG SAMA dan harus
                                    // terlihat sebagai hal yang sama. Dua merah berbeda menyiratkan
                                    // dua metrik berbeda. Saat design-cleanup jalan, ganti KEDUANYA.
                                    borderColor: '#F43F5E',
                                    pointBackgroundColor: '#F43F5E',
                                    borderWidth: 2,
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
                            // mode 'index' menyamai interaction.mode yang sudah dipakai tooltip:
                            // klik di mana pun dalam satu kolom detik memilih detik itu -- target
                            // kliknya jadi seluruh tinggi grafik, bukan titik 5px. Klik kolom TANPA
                            // error tetap dikirim: island menutup panelnya sendiri, jadi tak perlu
                            // tombol close.
                            onClick: (evt, activeEls, chart) => {
                                const hits = chart.getElementsAtEventForMode(evt, 'index', { intersect: false }, true);
                                if (!hits.length) return;
                                window.dispatchEvent(new CustomEvent('error-inspect', {
                                    detail: { index: hits[0].index }
                                }));
                            },
                            onHover: (evt, activeEls, chart) => {
                                const hits = chart.getElementsAtEventForMode(evt, 'index', { intersect: false }, true);
                                chart.canvas.style.cursor =
                                    (hits.length && errorCounts[hits[0].index] > 0) ? 'pointer' : 'default';
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
                                },
                                y1: {
                                    position: 'right',
                                    beginAtZero: true,
                                    // Grid kanan tak digambar di area chart: dua set gridline di
                                    // kartu sekecil ini membuat garis wpm sulit dibaca.
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    ticks: {
                                        color: '#94a3b8',
                                        precision: 0,
                                        stepSize: 1
                                    },
                                    // Sesi bersih (data semua null) -> Chart.js tak punya min/max &
                                    // sumbunya jadi 0..1 yang terlihat rusak. suggestedMax menjaga
                                    // rentangnya waras; display menyembunyikannya sama sekali: tak
                                    // ada error, tak ada sumbu error, grafik persis seperti dulu.
                                    suggestedMax: 5,
                                    display: hasErrors
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

        <div x-ref="heatmap"
            class="mt-8 bg-surface/40 border border-white/5 rounded-2xl p-4 md:p-6 flex flex-col items-center gap-2">
            <h3 class="font-mono text-xs uppercase tracking-[0.2em] text-muted mb-0 self-start">{{ __('result.error_heatmap') }}</h3>
            {{-- Titik grafik & heatmap menghitung KARAKTER TARGET yang gagal diproduksi;
                 tile "characters" menghitung KEYSTROKE. Keduanya sengaja beda (lihat docs),
                 jadi definisinya dinyatakan di sini -- tepat di titik kebingungannya. --}}
            <p class="font-mono text-[0.65rem] text-muted/60 self-start -mt-1">{{ __('result.error_scope') }}</p>

            {{-- Slot panel SELALU dirender dengan min-h supaya membuka/menutupnya tak
                 menggeser layout; empty state-nya merangkap satu-satunya petunjuk bahwa
                 grafik di atas bisa diklik. --}}
            <div class="w-full min-h-[5rem] mt-2 self-start">
                <template x-if="! selected">
                    <p class="font-mono text-xs text-muted/70">{{ __('result.error_hint') }}</p>
                </template>

                <template x-if="selected">
                    <div class="flex flex-col gap-1.5">
                        <p class="font-mono text-xs uppercase tracking-[0.2em] text-muted"
                            x-text="@js(__('result.error_at')).replace(':second', selected[0].label)"></p>

                        {{-- Banyak error dalam satu detik -> satu baris per error (bisa kata
                             yang sama dengan offset berbeda). Jujur apa adanya. --}}
                        {{-- :key POSISI, bukan e.second+e.index: `index` tak pernah ada di
                             output enrich (kunci "10-undefined" untuk semua -> Alpine anggap
                             duplikat & cuma render 1 baris). Backspace-lalu-ketik-ulang juga
                             menghasilkan dua event ber-{second,index} SAMA, jadi field apa pun
                             dari data tetap bisa bentrok. selected diganti utuh & tak pernah
                             diurut ulang, jadi posisi array itu kunci yang aman. --}}
                        <template x-for="(e, i) in selected" :key="i">
                            {{-- Tombol: baris inilah yang menyetir pasangan ring di keyboard.
                                 Penanda terpilih & cursor cuma muncul kalau memang ada pilihan
                                 (>1 error) -- kalau cuma satu, tombol yang "bisa diklik" tapi
                                 tak mengubah apa pun itu janji palsu. --}}
                            <button type="button" @click="row = i"
                                class="flex flex-wrap items-baseline gap-x-3 gap-y-1 font-mono text-sm text-left w-full border-l-2 pl-2 -ml-2 py-0.5 rounded-r transition-colors focus:outline-none focus-visible:ring-1 focus-visible:ring-gold/50"
                                :class="selected.length > 1
                                    ? (i === row
                                        ? 'border-gold bg-white/[0.03] cursor-pointer'
                                        : 'border-transparent hover:bg-white/[0.02] cursor-pointer')
                                    : 'border-transparent cursor-default'">
                                <template x-if="e.word">
                                    <span class="text-muted"
                                        ><span x-text="e.word.slice(0, e.offset)"></span
                                        ><span class="text-danger font-bold underline underline-offset-4"
                                            x-text="e.word[e.offset]"></span
                                        ><span x-text="e.word.slice(e.offset + 1)"></span
                                    ></span>
                                </template>
                                <template x-if="! e.word">
                                    <span class="text-muted/50">{{ __('result.error_no_word') }}</span>
                                </template>

                                <template x-if="e.actual !== null">
                                    <span class="text-foreground">
                                        <span x-text="@js(__('result.error_expected')).replace(':expected', e.expected ?? '?')"></span>
                                        <span class="text-muted/50"> · </span>
                                        <span class="text-danger"
                                            x-text="@js(__('result.error_typed')).replace(':actual', e.actual)"></span>
                                    </span>
                                </template>
                                <template x-if="e.actual === null">
                                    <span class="text-muted/70">{{ __('result.error_skipped') }}</span>
                                </template>
                            </button>
                        </template>
                    </div>
                </template>
            </div>

            {{-- Legenda memakai swatch dengan class ring/outline yang SAMA PERSIS dengan
                 tuts, jadi bahasanya mengajarkan dirinya sendiri ketimbang minta user
                 menghafal kata "solid"/"dashed". Selalu tampil: tak ada layout shift, dan
                 sudah terbaca bahkan sebelum titik pertama diklik. --}}
            <div class="w-full flex flex-wrap items-center gap-x-5 gap-y-1.5 font-mono text-[0.65rem] text-muted/60">
                <span class="inline-flex items-center gap-2">
                    <span class="w-3 h-3 rounded-sm bg-white/5 ring-2 ring-gold ring-offset-2 ring-offset-surface"></span>
                    {{ __('result.error_legend_needed') }}
                </span>
                <span class="inline-flex items-center gap-2">
                    <span class="w-3 h-3 rounded-sm bg-white/5 outline outline-2 outline-dashed outline-offset-2 outline-gold/50"></span>
                    {{ __('result.error_legend_pressed') }}
                </span>
            </div>

            {{-- overflow-x-auto memaksa overflow-y ikut MEMOTONG (spek: overflow-y:visible
                 terhitung jadi auto begitu overflow-x bukan visible), jadi apa pun yang
                 menjulur ke luar kotak butuh ruang eksplisit di sini:
                   pt-10 : tooltip baris teratas (-top-10)
                   pb-2  : ring/outline tuts terpilih di baris terbawah -- ring-offset-2 +
                           ring-2 menggambar 4px di bawah tuts, tanpa ini baris z/x terpotong. --}}
            <div class="w-full overflow-x-auto pt-10 pb-2">
            <div class="flex flex-col gap-2 md:gap-3 w-max mx-auto">
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
                            {{-- Ring EMAS, bukan merah: tuts ini sudah ber-background merah
                                 (opacity miss), merah di atas merah tak terbaca. Emas = warna
                                 "terpilih" di seluruh app. Pakai ring/outline BUKAN background:
                                 style inline di bawah memiliki penuh background-color tuts ini.
                                 Solid = tuts yang kamu BUTUHKAN; dashed = tuts yang kamu TEKAN. --}}
                            <div data-key="{{ $key }}"
                                class="w-10 h-10 md:w-12 md:h-12 rounded-lg flex items-center justify-center text-sm md:text-base font-bold transition-colors relative group"
                                :class="{
                                    'ring-2 ring-gold ring-offset-2 ring-offset-surface': expectedKey === @js($key),
                                    'outline outline-2 outline-dashed outline-offset-2 outline-gold/50': actualKey === @js($key),
                                }"
                                style="{{ $style }}">
                                {{ strtoupper($key) }}

                                @if ($missCount > 0)
                                    <div
                                        class="absolute -top-10 bg-surface text-danger px-2 py-1 rounded text-xs opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap z-10 shadow-lg border border-danger/50">
                                        {{ __('result.miss_count', ['count' => $missCount]) }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
            </div>
        </div>

        </div>{{-- /island error inspector --}}

        @endif
    </div>
</div>

{{-- Factory Alpine WAJIB di <script> biasa, BUKAN @script: script biasa dieksekusi saat
     parsing HTML (sebelum Alpine menelusuri DOM & mengevaluasi x-data), sedangkan @script
     jalan saat init Livewire -- berlomba dengan penelusuran itu dan melempar
     "errorInspector is not defined". Pembagian yang sama dipakai typingGame() di engine. --}}
<script>
    function errorInspector(events) {
        return {
            // Dikelompokkan di klien, bukan PHP: array PHP ber-key int rapat 0..n di-encode
            // jadi ARRAY oleh json_encode, yang jarang jadi OBJECT. Mengelompokkan di sini
            // menghilangkan jebakan bentuk itu sepenuhnya.
            grouped: events.reduce((acc, e) => ((acc[e.second] ??= []).push(e), acc), {}),
            selected: null,
            // Baris yang sedang disorot DI DALAM detik terpilih. Keyboard selalu menampilkan
            // tepat SATU pasang (butuh -> tekan). Menyorot seluruh detik sekaligus bikin
            // maknanya runtuh: satu tuts bisa jadi "yang dibutuhkan" di error A sekaligus
            // "yang ditekan" di error B, dan solid-vs-dashed kehilangan artinya.
            row: 0,

            select(index) {
                this.selected = this.grouped[index] ?? null;
                this.row = 0;
                if (this.selected) this.revealHeatmap();
            },

            get current() {
                return this.selected?.[this.row] ?? null;
            },

            // Di-lowercase agar cocok dengan kunci heatmap (yang juga lowercase dari
            // missedChars). Panel tetap menampilkan karakter ASLINYA -- "kamu menekan T"
            // vs "t" itu justru informasinya (menekan Shift saat tak perlu).
            get expectedKey() {
                return (this.current?.expected ?? '').toLowerCase();
            },

            get actualKey() {
                return (this.current?.actual ?? '').toLowerCase();
            },

            revealHeatmap() {
                // block:'nearest', BUKAN 'center'. 'nearest' menggulir SEMINIMAL mungkin dan
                // tak melakukan apa pun pada elemen yang sudah terlihat penuh -- jadi "gulir
                // hanya kalau di luar layar" sudah jadi semantik bawaan browser. Cek
                // getBoundingClientRect sendiri justru salah saat kartunya lebih tinggi
                // dari viewport, dan 'center' akan membuang grafiknya dari layar.
                this.$refs.heatmap?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            },
        };
    }
</script>
