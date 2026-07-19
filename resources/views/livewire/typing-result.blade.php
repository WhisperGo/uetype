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

            // Kartu stat non-survival: raw wpm + akurasi + characters selalu ada; consistency
            // & duration kondisional. Duration DIBUANG di mode time (redundan dengan label
            // "Time · Ns" di atas hero), tapi tetap tampil di words (durasi bervariasi).
            $statCount = 3 + (! is_null($consistency) ? 1 : 0) + ($mode === 'words' ? 1 : 0);
            // Baris stat gaya survival, kini FULL-WIDTH (sejajar grafik) -> muat satu baris
            // penuh: 3 (time tanpa consistency), 4 (time), 5 (words). Di lebar segini 5 kartu
            // sebaris lebih rapi daripada 3+2 yang menyisakan sel kosong mencolok.
            $statCols = match ($statCount) {
                3 => 'sm:grid-cols-3',
                5 => 'sm:grid-cols-5',
                default => 'sm:grid-cols-4',
            };
            // Jumlah ganjil -> kartu characters (yang terakhir) direntang penuh di mobile
            // 2-kolom agar tak ada sel menggantung.
            $charsSpan = $statCount % 2 === 1 ? 'col-span-2 sm:col-span-1' : '';
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

        {{-- Scoreboard (full-width, sejajar dengan blok grafik di bawahnya): hero-card di
             tengah -> baris stat -> XP. Meniru URUTAN vertikal survival, tapi lebarnya
             mengikuti grafik (bukan kolom sempit max-w-2xl) supaya kartu stat & XP tak
             terlihat mengambang di atas grafik yang lebar. Isi hero tetap center. --}}
        <div class="flex flex-col gap-8">

            {{-- Hero card: reuse pola kartu survival (garis aksen atas + center), tapi aksennya
                 EMAS bukan danger -- solo bukan "game over". Mode label mengambil peran baris
                 "game over" di atas hero; heroLabel ("wpm") mengambil peran "survived". --}}
            <div class="relative overflow-hidden rounded-3xl border border-border bg-surface/60 px-8 py-10 text-center">
                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-transparent via-gold to-transparent opacity-70"></div>

                <p class="font-mono text-xs uppercase tracking-[0.35em] text-muted mb-4">{{ $modeLabel }}</p>

                <div class="flex flex-col items-center gap-1">
                    <span class="font-display text-fluid-hero text-gold leading-none tabular-nums">{{ $heroValue }}</span>
                    <span class="font-mono text-xs uppercase tracking-[0.3em] text-muted mt-3">{{ $heroLabel }}</span>
                </div>

                {{-- Momen pencapaian = pill emas (menyamai treatment PB cabang survival di
                     ~baris 82), BUKAN teks kecil. Solo bisa tampil PB DAN ghost sekaligus, jadi
                     tetap kolom. State kalah/di-bawah-rekor tetap teks muted -- bukan perayaan. --}}
                <div class="mt-6 flex flex-col items-center gap-2">
                    @if ($isPersonalBest)
                        <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-gold/10 border border-gold/40 text-gold font-mono text-sm">
                            <span>✦</span> {{ __('result.new_personal_best') }}
                        </span>
                    @elseif (!is_null($recordDelta))
                        <p class="font-mono text-sm text-muted tabular-nums">
                            {{ $recordDelta >= 0 ? '+' : '' }}{{ $recordDelta }} <span class="text-muted/70">{{ __('result.vs_record', ['best' => rtrim(rtrim(number_format($previousBest, 1), '0'), '.')]) }}</span>
                        </p>
                    @endif

                    @if ($ghostResult)
                        @if ($ghostResult['playerWon'])
                            <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-gold/10 border border-gold/40 text-gold font-mono text-sm">
                                <span>✦</span> {{ __('result.beat_ghost') }}
                                <span class="text-gold/60">{{ __('result.vs_ghost', ['label' => $ghostResult['label'], 'wpm' => rtrim(rtrim(number_format($ghostResult['wpm'], 1), '0'), '.')]) }}</span>
                            </span>
                        @else
                            <p class="flex items-center gap-1.5 text-sm font-mono text-muted">
                                <span>·</span> {{ __('result.lost_ghost') }}
                                <span class="text-muted/70">{{ __('result.vs_ghost', ['label' => $ghostResult['label'], 'wpm' => rtrim(rtrim(number_format($ghostResult['wpm'], 1), '0'), '.')]) }}</span>
                            </p>
                        @endif
                    @endif
                </div>
            </div>{{-- /hero card --}}

            {{-- Baris stat gaya survival (grid-cols-2 sm:grid-cols-4). Kita sudah di dalam
                 cabang non-survival, jadi cuma kartu solo: raw wpm + akurasi + characters
                 selalu ada; consistency & duration (words) kondisional. --}}
            <div class="grid grid-cols-2 {{ $statCols }} gap-3">
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
                {{-- Duration hanya untuk WORDS: di mode time selalu = konfigurasi
                     (mis. label "Time · 15s"), jadi kartunya cuma mengulang. --}}
                @if ($mode === 'words')
                    <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2">
                        <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.duration') }}</span>
                        <span class="text-xl sm:text-2xl text-foreground font-bold font-mono leading-none tabular-nums">{{ round($time, 1) }}<span class="text-lg text-muted">s</span></span>
                    </div>
                @endif
                <div class="bg-surface/70 border border-white/5 rounded-2xl p-4 flex flex-col gap-2 {{ $charsSpan }}">
                    <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.stat.characters') }}</span>
                    <span class="text-xl sm:text-2xl font-bold font-mono leading-none tabular-nums">
                        <span class="text-foreground">{{ $correctKeystrokes }}</span><span class="text-muted"> / </span><span class="text-danger">{{ $incorrectKeystrokes }}</span>
                    </span>
                </div>
            </div>

            {{-- XP + level bar (full-width dalam kolom center) --}}
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
        </div>{{-- /scoreboard center --}}

        {{-- Blok error-review: grafik full-width + heatmap TEPAT di bawahnya, dibungkus island
             supaya klik titik di grafik langsung menyorot tuts. Karena keduanya kini
             bersebelahan, revealHeatmap() (scrollIntoView) jadi no-op saat sudah terlihat --
             tetap disimpan sebagai jaring pengaman. --}}
        <div x-data="errorInspector(@js($this->errorSeries['events']))"
            x-on:error-inspect.window="select($event.detail.index)"
            class="mt-10 flex flex-col gap-6">

            <!-- ===== GRAFIK (full-width) ===== -->
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
                {{-- Tinggi EKSPLISIT (bukan lagi lg:flex-1 yang ikut tinggi kolom kiri):
                     kini grafik proporsinya sama untuk guest & login. Canvas tetap absolute
                     agar tak menyumbang intrinsic size -> Chart.js tak masuk resize-loop. --}}
                <div class="relative w-full h-72 md:h-80" wire:ignore>
                    <canvas id="wpmChart" class="absolute inset-0"></canvas>
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
                                    beginAtZero: true,
                                    title: {
                                        display: true,
                                        text: @js(__('result.wpm')),
                                        color: '#94a3b8',
                                        font: {
                                            size: 10
                                        }
                                    }
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
                                    // display: hasErrors mematikan SELURUH sumbu (judul ikut) saat
                                    // run bersih -> tak ada error, tak ada sumbu kanan sama sekali.
                                    display: hasErrors,
                                    title: {
                                        display: true,
                                        text: @js(__('result.error_axis')),
                                        color: '#94a3b8',
                                        font: {
                                            size: 10
                                        }
                                    }
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

                // Chart datang dari bundle Vite (window.Chart di app.js), bukan CDN runtime.
                renderChart();
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
            // Run bersih = tak ada tuts meleset DAN tak ada titik error. Pakai KEDUANYA supaya
            // sesi lama (missedChars terisi tanpa errorEvents) tetap terhitung ber-error ->
            // heatmap tetap tampil (menjaga assertSee 'error heatmap' di test layout).
            $isCleanRun = empty($missedChars) && array_sum($this->errorSeries['counts']) === 0;
        @endphp

        @if ($isCleanRun)
            {{-- Run bersih: JANGAN tampilkan konsol error kosong + hint "klik penanda" yang
                 mustahil (tak ada penanda). Ganti pengakuan positif; grafik di atas tetap ada. --}}
            <div class="bg-surface/40 border border-white/5 rounded-2xl p-6 flex items-center justify-center gap-3">
                <span class="text-gold text-lg leading-none">✦</span>
                <span class="font-mono text-sm text-gold uppercase tracking-[0.2em]">{{ __('result.error_none') }}</span>
            </div>
        @else
        <div x-ref="heatmap"
            class="bg-surface/40 border border-white/5 rounded-2xl p-4 md:p-6 flex flex-col items-center gap-2">
            <h3 class="font-mono text-xs uppercase tracking-[0.2em] text-muted mb-0 self-start">{{ __('result.error_heatmap') }}</h3>
            {{-- Titik grafik & heatmap menghitung KARAKTER TARGET yang gagal diproduksi;
                 tile "characters" menghitung KEYSTROKE. Keduanya sengaja beda (lihat docs),
                 jadi definisinya dinyatakan di sini -- tepat di titik kebingungannya. --}}

            {{-- Idle: cukup hint ringkas (afordans bahwa grafik bisa diklik), TANPA min-h
                 besar -- jadi tak ada ruang kosong menganga sebelum ada titik diklik. Saat
                 titik diklik, panel detail mengembang MULUS lewat x-collapse (bukan lompat).
                 x-show, bukan x-if: x-collapse perlu elemen tetap ada untuk menganimasikan
                 tingginya. Kalau plugin Collapse absen, x-collapse jadi no-op & panel tetap
                 toggle instan (graceful). Akses `selected` di-guard (?. / ?? []) karena
                 elemennya kini selalu ada di DOM meski `selected` masih null. --}}
            <div class="w-full mt-2 self-start">
                <p x-show="! open" class="font-mono text-xs text-muted/70">{{ __('result.error_hint') }}</p>

                <div x-show="open" x-collapse class="flex flex-col gap-1.5">
                        <p class="font-mono text-xs uppercase tracking-[0.2em] text-muted"
                            x-text="@js(__('result.error_at')).replace(':second', selected?.[0]?.label ?? '')"></p>

                        {{-- Banyak error dalam satu detik -> satu baris per error (bisa kata
                             yang sama dengan offset berbeda). Jujur apa adanya. --}}
                        {{-- :key POSISI, bukan e.second+e.index: `index` tak pernah ada di
                             output enrich (kunci "10-undefined" untuk semua -> Alpine anggap
                             duplikat & cuma render 1 baris). Backspace-lalu-ketik-ulang juga
                             menghasilkan dua event ber-{second,index} SAMA, jadi field apa pun
                             dari data tetap bisa bentrok. selected diganti utuh & tak pernah
                             diurut ulang, jadi posisi array itu kunci yang aman. --}}
                        <template x-for="(e, i) in (selected ?? [])" :key="i">
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
                                            x-text="e.word.slice(e.offset, e.offsetEnd + 1)"></span
                                        ><span x-text="e.word.slice(e.offsetEnd + 1)"></span
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
                                    'ring-2 ring-gold ring-offset-2 ring-offset-surface': expectedKeys.includes(@js($key)),
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

            {{-- Legenda: PINDAH ke bawah keyboard supaya alur baca hint -> keyboard ->
                 keterangan simbol, dan tak lagi menyelip mepet di antara hint & keyboard.
                 Di-center (justify-center) mengikuti keyboard yang juga center. Swatch pakai
                 class ring/outline PERSIS sama dengan tuts -- mengajarkan dirinya sendiri. --}}
            <div class="w-full flex flex-wrap items-center justify-center gap-x-5 gap-y-1.5 font-mono text-[0.65rem] text-muted/60">
                <span class="inline-flex items-center gap-2">
                    <span class="w-3 h-3 rounded-sm bg-white/5 ring-2 ring-gold ring-offset-2 ring-offset-surface"></span>
                    {{ __('result.error_legend_needed') }}
                </span>
                <span class="inline-flex items-center gap-2">
                    <span class="w-3 h-3 rounded-sm bg-white/5 outline outline-2 outline-dashed outline-offset-2 outline-gold/50"></span>
                    {{ __('result.error_legend_pressed') }}
                </span>
            </div>
        </div>
        @endif{{-- /clean-run --}}

        </div>{{-- /island error inspector --}}

        {{-- Tombol aksi: full-width di bawah seluruh ringkasan + blok error-review. --}}
        <div class="mt-10 flex items-stretch gap-3">
            <a id="restartButton" href="/typing"
                class="flex-1 inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-mono font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition"
                title="{{ __('result.next_test_title') }}">
                <span>{{ __('result.next_test_title') }}</span>
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
            // menghilangkan jebakan bentuk itu sepenuhnya. Tiap detik lalu dilewatkan
            // mergeSkippedRuns() -> "display rows": run karakter SKIPPED yang berurutan dalam
            // satu kata jadi SATU baris (grafik & heatmap tetap per-karakter, ini murni tampilan).
            grouped: Object.fromEntries(
                Object.entries(
                    events.reduce((acc, e) => ((acc[e.second] ??= []).push(e), acc), {})
                ).map(([sec, evs]) => [sec, mergeSkippedRuns(evs)])
            ),
            selected: null,
            // Visibilitas panel DIPISAH dari `selected`. x-collapse mengukur tinggi elemen
            // tepat saat x-show berubah true; kalau visibilitas diikat langsung ke `selected`,
            // efek x-show (di induk) bisa jalan SEBELUM x-for (di anak) menyisipkan baris ->
            // tinggi terukur cuma setinggi label -> animasi mengembang ke tinggi salah lalu
            // "meloncat" ke penuh. `open` di-flip SETELAH konten ter-render (lihat select()).
            open: false,
            // Baris yang sedang disorot DI DALAM detik terpilih. Keyboard menyorot tuts dari
            // SATU baris saja (baris run bisa banyak tuts "needed"; baris salah-ketik = 1 pasang
            // butuh->tekan). Menyorot seluruh detik sekaligus bikin maknanya runtuh: satu tuts
            // bisa jadi "yang dibutuhkan" di error A sekaligus "yang ditekan" di error B, dan
            // solid-vs-dashed kehilangan artinya.
            row: 0,

            select(index) {
                const group = this.grouped[index] ?? null;

                // Klik kolom tanpa error -> tutup panel & bersihkan sorotan keyboard.
                if (! group) {
                    this.open = false;
                    this.selected = null;
                    this.row = 0;
                    return;
                }

                // Isi konten DULU: x-for merender baris pada flush tick ini.
                this.selected = group;
                this.row = 0;

                // Baru buka di tick berikutnya, setelah baris ada di DOM -> x-collapse
                // mengukur tinggi yang benar & animasinya mulus (tak meloncat). Scroll juga
                // ditunda ke sini supaya tak dihitung saat panel masih tinggi 0.
                this.$nextTick(() => {
                    this.open = true;
                    this.revealHeatmap();
                });
            },

            get current() {
                return this.selected?.[this.row] ?? null;
            },

            // Tuts "needed" yang disorot di keyboard. Array, bukan string tunggal: baris run
            // skipped mewakili BEBERAPA karakter -> semua ter-ring sekaligus. Baris salah-ketik
            // / skip tunggal = array 1 elemen (perilaku identik dengan sebelumnya). Sudah
            // di-lowercase di mergeSkippedRuns agar cocok dengan kunci heatmap (lowercase dari
            // missedChars). Panel tetap menampilkan karakter ASLINYA.
            get expectedKeys() {
                return this.current?.keys ?? [];
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

    // Ubah event mentah satu detik jadi "display rows": karakter SKIPPED (actual === null)
    // yang BERURUTAN dalam kata yang SAMA digabung ke satu baris (mis. "a" ketik lalu lompat
    // -> "bout" jadi satu run a[bout]) supaya panel tak mengulang kalimat "skipped" per huruf.
    // MURNI tampilan: counts grafik & missedChars/heatmap tetap per-karakter (dari PHP inspect).
    // Event dalam satu detik sudah urut ketik = urut index, jadi run skipped kontigu pasti
    // berdampingan. Error salah-ketik & skip tunggal jadi baris biasa (offsetEnd === offset).
    function mergeSkippedRuns(events) {
        const rows = [];

        for (const e of events) {
            const prev = rows[rows.length - 1];
            const isSkip = e.actual === null;

            if (isSkip && prev && prev.skipped && prev.word && prev.word === e.word
                && typeof e.offset === 'number' && e.offset === prev.offsetEnd + 1) {
                prev.offsetEnd = e.offset;
                prev.keys.push((e.expected ?? '').toLowerCase());
                continue;
            }

            rows.push({
                word: e.word,
                offset: e.offset,               // awal garis bawah
                offsetEnd: e.offset,            // akhir garis bawah (== offset utk baris tunggal)
                expected: e.expected,
                actual: e.actual,
                skipped: isSkip,
                keys: [(e.expected ?? '').toLowerCase()],   // tuts "needed" utk sorotan keyboard
            });
        }

        return rows;
    }
</script>
