{{--
    Statistics page (app/Livewire/Stats.php). Every number here is aggregated from
    typing_results and recomputed on each render — there are no stored summary columns.

    Charts are rendered by Chart.js inside wire:ignore so Livewire doesn't overwrite the
    <canvas> that Chart.js already owns when the day range changes; changing the range
    re-runs draw() (via the morph.updated hook) to swap the chart data in place.
--}}
<div class="py-8 text-muted font-mono" x-data="{ tab: 'solo' }">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-8">

        @php
            // "1m 24s" / "48h 22m": zero-valued units are trimmed to keep it compact.
            $fmtDuration = function (int $s) {
                if ($s <= 0) return '0s';
                $h = intdiv($s, 3600);
                $m = intdiv($s % 3600, 60);
                $sec = $s % 60;
                if ($h > 0) return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
                return $sec > 0 ? "{$m}m {$sec}s" : "{$m}m";
            };

            // 847231 -> "847K", 1_200_000 -> "1.2M"
            $fmtCompact = function (int $n) {
                if ($n >= 1_000_000) return rtrim(rtrim(number_format($n / 1_000_000, 1), '0'), '.') . 'M';
                if ($n >= 1_000) return number_format($n / 1_000, 0) . 'K';
                return (string) $n;
            };

            // Sort numeric configs (15, 25, 50) numerically, not lexicographically.
            $sortNumeric = function (array $rows) {
                uksort($rows, fn ($a, $b) => (int) $a <=> (int) $b);
                return $rows;
            };

            $bestWords = $sortNumeric($bestWords);
            $bestTime = $sortNumeric($bestTime);

            $modeColors = [
                'words' => 'bg-brand',
                'time' => 'bg-brand-bright',
                'survival' => 'bg-gold',
            ];

            // The `mode` column may hold an enum value without a translation yet;
            // use its raw text rather than leaking a lang key to the screen.
            $modeLabel = fn (string $m) => Lang::has('stats.mode.' . $m) ? __('stats.mode.' . $m) : ucfirst($m);

            // Same as multiplayer-lobby.blade.php: the ordinal suffix is English-only
            // (Indonesian uses a plain "ke-N", see stats.multiplayer.place_prefix).
            $placeOrdinal = function (int $place) {
                if (app()->getLocale() !== 'en') {
                    return __('stats.multiplayer.place_prefix') . $place;
                }
                $suffix = match ($place) {
                    1 => 'st',
                    2 => 'nd',
                    3 => 'rd',
                    default => 'th',
                };

                return $place . $suffix;
            };
        @endphp

        {{-- Page tabs: Solo (populated) vs Multiplayer. --}}
        <div class="flex justify-center gap-8">
            @foreach (['solo', 'multiplayer'] as $t)
                <button type="button" x-on:click="tab = '{{ $t }}'"
                    :class="tab === '{{ $t }}'
                        ? 'text-foreground border-brand-bright'
                        : 'text-muted border-transparent hover:text-foreground'"
                    class="flex items-center gap-2 pb-2 border-b-2 text-small transition-colors">
                    @if ($t === 'solo')
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.5 20.25a7.5 7.5 0 0 1 15 0v.75h-15v-.75Z" />
                        </svg>
                    @else
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.1 9.1 0 0 0 3.74-.95V17a3 3 0 0 0-2.4-2.94M18 18.72a9.1 9.1 0 0 1-6 0m6 0v-.31a4.5 4.5 0 0 0-.34-1.72M12 18.72a9.1 9.1 0 0 1-6 0m6 0v-.31c0-.6-.12-1.18-.34-1.72M6 18.72a9.1 9.1 0 0 1-3.74-.95V17a3 3 0 0 1 2.4-2.94M6 18.72v-.31c0-.6.12-1.18.34-1.72m0 0a5.25 5.25 0 0 1 9.32 0M15 8.25a3 3 0 1 1-6 0 3 3 0 0 1 6 0Zm6 3a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Zm-13.5 0a2.25 2.25 0 1 1-4.5 0 2.25 2.25 0 0 1 4.5 0Z" />
                        </svg>
                    @endif
                    {{ __('stats.tab.' . $t) }}
                </button>
            @endforeach
        </div>

        {{-- ===================== TAB: SOLO ===================== --}}
        <div x-show="tab === 'solo'" x-transition.opacity class="space-y-8">

            {{-- Identity + XP bar --}}
            <div class="relative rounded-2xl border border-border bg-surface/60 p-5 sm:p-6">
                <a href="{{ route('profile.me') }}" title="{{ __('stats.edit_profile') }}"
                    class="absolute top-5 right-5 text-muted hover:text-gold transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.86 4.49 2.65 2.65m-1.6-3.7a1.87 1.87 0 1 1 2.65 2.65L7.5 18.75l-3.5.75.75-3.5L17.91 3.44Z" />
                    </svg>
                </a>

                <div class="flex flex-col sm:flex-row gap-5 sm:items-center">
                    @if ($user->avatar)
                        <img src="{{ $user->avatar }}" alt="{{ $user->username }}" referrerpolicy="no-referrer"
                            class="w-20 h-20 rounded-xl object-cover border border-brand/40 shrink-0">
                    @else
                        <div class="w-20 h-20 rounded-xl bg-gradient-to-br from-brand to-gold text-background
                            flex items-center justify-center text-h3 font-bold uppercase shrink-0">
                            {{ \Illuminate\Support\Str::substr($user->username, 0, 1) }}
                        </div>
                    @endif

                    <div class="flex-1 min-w-0">
                        <h1 class="text-h5 font-bold text-foreground truncate">{{ $user->username }}</h1>
                        <p class="text-x-small text-muted mt-2">
                            {{ __('stats.level') }} <span class="text-foreground font-bold">{{ $levelData['level'] }}</span>
                        </p>

                        @php $pct = $levelData['needed'] > 0 ? ($levelData['progress'] / $levelData['needed']) * 100 : 0; @endphp
                        <div class="mt-3">
                            <div class="flex justify-end text-[0.65rem] text-muted mb-1">
                                {{ __('stats.xp_progress', ['progress' => number_format($levelData['progress']), 'needed' => number_format($levelData['needed'])]) }}
                            </div>
                            <div class="h-1.5 rounded-full bg-white/5 overflow-hidden">
                                <div class="h-full rounded-full bg-gold transition-all" style="width: {{ $pct }}%"></div>
                            </div>
                        </div>

                        <p class="text-x-small text-muted mt-3">
                            {{ __('stats.since', ['date' => \App\Support\AppTime::format($user->joined_at ?? $user->created_at, 'F Y')]) }}
                            ·
                            {{ __('stats.tests_completed', ['count' => number_format($activity['total_tests'])]) }}
                        </p>
                    </div>
                </div>
            </div>

            {{-- ===== All time Statistics ===== --}}
            <section>
                <h2 class="text-h6 font-bold text-foreground mb-5">{{ __('stats.all_time') }}</h2>

                @if (empty($bestWords) && empty($bestTime) && empty($bestSurvival))
                    <div class="rounded-2xl border border-border bg-surface/40 px-5 py-8 text-center text-small text-muted">
                        {{ __('stats.empty_records') }}
                    </div>
                @else
                    <p class="text-center text-x-small text-muted mb-3">{{ __('stats.standard_wpm') }}</p>
                    <div class="grid gap-4 {{ ($bestWords && $bestTime) ? 'lg:grid-cols-2' : '' }}">
                        @foreach ([['rows' => $bestWords, 'unit' => 'stats.words_unit'], ['rows' => $bestTime, 'unit' => 'stats.seconds_unit']] as $group)
                            @if (! empty($group['rows']))
                                <div class="rounded-2xl border border-border bg-surface/60 px-4 py-5">
                                    <div class="grid gap-2" style="grid-template-columns: repeat({{ count($group['rows']) }}, minmax(0, 1fr))">
                                        @foreach ($group['rows'] as $config => $wpm)
                                            <div class="text-center">
                                                {{-- The best record in this group is highlighted gold. --}}
                                                <p class="font-pixel text-h3 leading-none tabular-nums {{ $wpm >= max($group['rows']) ? 'text-gold' : 'text-foreground' }}">
                                                    {{ round($wpm) }}
                                                </p>
                                                <p class="text-x-small text-muted mt-2">{{ __($group['unit'], ['count' => $config]) }}</p>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    @if (! empty($bestSurvival))
                        @php
                            // Order easy -> medium -> hard, regardless of the DB row order.
                            $order = ['easy' => 0, 'medium' => 1, 'hard' => 2];
                            uksort($bestSurvival, fn ($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
                        @endphp
                        <p class="text-center text-x-small text-muted mt-6 mb-3">{{ __('stats.survival_duration') }}</p>
                        <div class="flex justify-center">
                            <div class="rounded-2xl border border-border bg-surface/60 px-6 py-5">
                                <div class="grid gap-6" style="grid-template-columns: repeat({{ count($bestSurvival) }}, minmax(0, 1fr))">
                                    @foreach ($bestSurvival as $difficulty => $seconds)
                                        <div class="text-center">
                                            <p class="font-pixel text-h3 leading-none tabular-nums {{ $seconds >= max($bestSurvival) ? 'text-gold' : 'text-foreground' }}">
                                                {{ $fmtDuration($seconds) }}
                                            </p>
                                            {{-- survival mode_config may hold legacy values outside easy/medium/hard;
                                                 fall back to its raw text rather than showing a lang key. --}}
                                            <p class="text-x-small text-muted mt-2">
                                                {{ Lang::has('stats.difficulty.' . $difficulty) ? __('stats.difficulty.' . $difficulty) : ucfirst($difficulty) }}
                                            </p>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    @endif
                @endif
            </section>

            {{-- ===== Achievements (badge saja; detail di halaman Achievements) ===== --}}
            <section>
                <div class="flex items-center justify-between gap-3 mb-4">
                    <div class="flex items-baseline gap-3">
                        <h2 class="text-h6 font-bold text-foreground">{{ __('stats.achievements') }}</h2>
                        <span class="text-x-small text-muted">
                            {{ __('stats.achievements_count', ['earned' => count($achievements['earned']), 'total' => $achievements['total']]) }}
                        </span>
                    </div>
                    <a href="{{ route('achievements.index') }}"
                        class="text-x-small text-gold hover:underline inline-flex items-center gap-1 shrink-0">
                        {{ __('stats.view_all') }}
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                </div>

                {{-- Only earned ones. Locked ones and their progress live at /achievements. --}}
                @if (empty($achievements['earned']))
                    <div class="rounded-2xl border border-border bg-surface/40 px-5 py-8 text-center text-small text-muted">
                        {{ __('stats.no_achievements') }}
                    </div>
                @else
                    <div class="flex flex-wrap gap-3">
                        @foreach ($achievements['earned'] as $a)
                            <div title="{{ __('achievements.defs.' . $a['key'] . '.title') }}"
                                class="flex flex-col items-center justify-center w-16 h-16 rounded-xl shrink-0
                                    border border-brand bg-brand/15 text-gold">
                                <span class="font-pixel text-h6 leading-none">{{ $a['icon_value'] }}</span>
                                <span class="mt-1 text-[0.5rem] uppercase tracking-wider text-gold/70">
                                    {{ $a['icon_unit'] }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- ===== WPM Progression ===== --}}
            <section>
                <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                    <h2 class="text-h6 font-bold text-foreground">{{ __('stats.wpm_progression') }}</h2>
                    <div class="flex gap-2">
                        @foreach (['7', '30', 'all'] as $r)
                            <button type="button" wire:click="setRange('{{ $r }}')"
                                class="px-3 py-1.5 rounded-lg border text-x-small transition-colors
                                    {{ $range === $r
                                        ? 'bg-brand border-brand text-foreground'
                                        : 'bg-surface/60 border-border text-muted hover:text-foreground' }}">
                                {{ __('stats.range.' . $r) }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-2xl border border-border bg-surface/40 p-4 sm:p-5">
                    @if (count($series['wpm']) >= 2)
                        <div class="w-full h-56" wire:ignore>
                            <canvas id="statsWpmChart"></canvas>
                        </div>
                    @else
                        <p class="py-10 text-center text-small text-muted">{{ __('stats.not_enough_data') }}</p>
                    @endif
                </div>
            </section>

            {{-- ===== Activity ===== --}}
            <section>
                <h2 class="text-h6 font-bold text-foreground mb-4">{{ __('stats.activity') }}</h2>

                @php
                    $activityCards = [
                        ['label' => __('stats.card.total_time'), 'value' => $fmtDuration($activity['total_seconds'])],
                        ['label' => __('stats.card.total_tests'), 'value' => number_format($activity['total_tests'])],
                        ['label' => __('stats.card.avg_accuracy'), 'value' => $activity['avg_accuracy'] . '%'],
                        ['label' => __('stats.card.avg_wpm'), 'value' => $activity['avg_wpm']],
                        ['label' => __('stats.card.total_chars'), 'value' => $fmtCompact($activity['total_chars'])],
                    ];
                @endphp
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                    @foreach ($activityCards as $card)
                        <div class="rounded-2xl border border-border bg-surface/40 px-4 py-4">
                            <p class="text-h6 font-bold text-foreground tabular-nums">{{ $card['value'] }}</p>
                            <p class="text-x-small text-muted mt-1">{{ $card['label'] }}</p>
                        </div>
                    @endforeach
                </div>

                {{-- Mode Distribution: each segment's width = its share of tests. --}}
                <div class="mt-4 rounded-2xl border border-border bg-surface/40 p-4 sm:p-5">
                    <h3 class="text-small text-foreground mb-3">{{ __('stats.mode_distribution') }}</h3>

                    @if (empty($distribution))
                        <p class="py-4 text-small text-muted">{{ __('stats.no_distribution') }}</p>
                    @else
                        <div class="flex gap-1.5 mb-3">
                            @foreach ($distribution as $slice)
                                <div class="{{ $modeColors[$slice['mode']] ?? 'bg-muted' }} rounded-lg px-3 py-2.5 min-w-0 overflow-hidden"
                                    style="flex: {{ max($slice['percent'], 1) }} 1 0%">
                                    <span class="text-x-small text-background font-bold whitespace-nowrap">
                                        {{ $slice['percent'] }}%
                                    </span>
                                </div>
                            @endforeach
                        </div>
                        <div class="flex flex-wrap gap-x-5 gap-y-1.5">
                            @foreach ($distribution as $slice)
                                <span class="inline-flex items-center gap-2 text-x-small text-muted">
                                    <span class="w-2 h-2 rounded-full {{ $modeColors[$slice['mode']] ?? 'bg-muted' }}"></span>
                                    {{ $modeLabel($slice['mode']) }} {{ $slice['percent'] }}%
                                </span>
                            @endforeach
                        </div>
                    @endif
                </div>
            </section>

            {{-- ===== Accuracy Trend ===== --}}
            <section>
                <h2 class="text-h6 font-bold text-foreground mb-4">{{ __('stats.accuracy_trend') }}</h2>
                <div class="rounded-2xl border border-border bg-surface/40 p-4 sm:p-5">
                    @if (count($series['accuracy']) >= 2)
                        <div class="w-full h-48" wire:ignore>
                            <canvas id="statsAccuracyChart"></canvas>
                        </div>
                    @else
                        <p class="py-10 text-center text-small text-muted">{{ __('stats.not_enough_data') }}</p>
                    @endif
                </div>
            </section>
        </div>

        {{-- ===================== TAB: MULTIPLAYER ===================== --}}
        <div x-show="tab === 'multiplayer'" x-transition.opacity style="display: none;" class="space-y-8">
            @if ($multiplayerStats['total_races'] === 0)
                <div class="rounded-2xl border border-border bg-surface/40 px-5 py-16 text-center text-small text-muted">
                    {{ __('stats.multiplayer.empty') }}
                </div>
            @else
                {{-- ===== Activity ===== --}}
                <section>
                    <h2 class="text-h6 font-bold text-foreground mb-4">{{ __('stats.activity') }}</h2>

                    @php
                        $mpCards = [
                            ['label' => __('stats.multiplayer.card.races'), 'value' => number_format($multiplayerStats['total_races'])],
                            ['label' => __('stats.multiplayer.card.win_rate'), 'value' => $multiplayerStats['win_rate'] . '%'],
                            ['label' => __('stats.multiplayer.card.avg_wpm'), 'value' => $multiplayerStats['avg_wpm']],
                            ['label' => __('stats.multiplayer.card.best_wpm'), 'value' => $multiplayerStats['best_wpm']],
                            ['label' => __('stats.multiplayer.card.avg_accuracy'), 'value' => $multiplayerStats['avg_accuracy'] . '%'],
                        ];

                        $placeColors = [1 => 'bg-gold', 2 => 'bg-brand-bright', 3 => 'bg-brand'];
                    @endphp
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        @foreach ($mpCards as $card)
                            <div class="rounded-2xl border border-border bg-surface/40 px-4 py-4">
                                <p class="text-h6 font-bold text-foreground tabular-nums">{{ $card['value'] }}</p>
                                <p class="text-x-small text-muted mt-1">{{ $card['label'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Placement Distribution: same pattern as Mode Distribution on the Solo tab. --}}
                    <div class="mt-4 rounded-2xl border border-border bg-surface/40 p-4 sm:p-5">
                        <h3 class="text-small text-foreground mb-3">{{ __('stats.multiplayer.placements') }}</h3>

                        @if (empty($placementDistribution))
                            <p class="py-4 text-small text-muted">{{ __('stats.no_distribution') }}</p>
                        @else
                            <div class="flex gap-1.5 mb-3">
                                @foreach ($placementDistribution as $slice)
                                    <div class="{{ $placeColors[$slice['place']] ?? 'bg-muted' }} rounded-lg px-3 py-2.5 min-w-0 overflow-hidden"
                                        style="flex: {{ max($slice['percent'], 1) }} 1 0%">
                                        <span class="text-x-small text-background font-bold whitespace-nowrap">
                                            {{ $slice['percent'] }}%
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                            <div class="flex flex-wrap gap-x-5 gap-y-1.5">
                                @foreach ($placementDistribution as $slice)
                                    <span class="inline-flex items-center gap-2 text-x-small text-muted">
                                        <span class="w-2 h-2 rounded-full {{ $placeColors[$slice['place']] ?? 'bg-muted' }}"></span>
                                        {{ $placeOrdinal($slice['place']) }} {{ $slice['percent'] }}%
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                {{-- ===== Recent Matches ===== --}}
                <section>
                    <h2 class="text-h6 font-bold text-foreground mb-4">{{ __('stats.multiplayer.recent_matches') }}</h2>

                    <div class="rounded-2xl border border-border bg-surface/40 overflow-x-auto">
                        <table class="w-full text-small">
                            <thead>
                                <tr class="border-b border-border text-x-small text-muted uppercase tracking-wider">
                                    <th class="text-left px-4 py-3 font-normal">{{ __('stats.multiplayer.th_place') }}</th>
                                    <th class="text-left px-4 py-3 font-normal">{{ __('stats.multiplayer.th_wpm') }}</th>
                                    <th class="text-left px-4 py-3 font-normal">{{ __('stats.multiplayer.th_accuracy') }}</th>
                                    <th class="text-left px-4 py-3 font-normal">{{ __('stats.multiplayer.th_players') }}</th>
                                    <th class="text-left px-4 py-3 font-normal">{{ __('stats.multiplayer.th_date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($recentMatches as $match)
                                    <tr class="border-b border-border last:border-0">
                                        <td class="px-4 py-3 font-bold {{ $match->place === 1 ? 'text-gold' : 'text-foreground' }}">
                                            {{ $placeOrdinal($match->place) }}
                                        </td>
                                        <td class="px-4 py-3 text-foreground tabular-nums">{{ $match->wpm }}</td>
                                        <td class="px-4 py-3 text-foreground tabular-nums">{{ $match->accuracy }}%</td>
                                        <td class="px-4 py-3 text-muted tabular-nums">{{ $match->player_count }}</td>
                                        <td class="px-4 py-3 text-muted">@localtime($match->created_at, 'M j, Y')</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif
        </div>
    </div>

    {{--
        Chart.js comes from the Vite bundle (window.Chart via app.js) and is reused.
        The chart is redrawn on each render via the morph.updated hook, since the
        <canvas> sits in wire:ignore and Livewire won't update its contents itself.
    --}}
    @script
    <script>
        const palette = { gold: '#C69F68', brand: '#9BA5D1', grid: 'rgba(255,255,255,0.04)', tick: '#9C9FA3' };
        let wpmChart = null;
        let accChart = null;

        const baseOptions = (min) => ({
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { color: palette.grid }, ticks: { color: palette.tick, maxTicksLimit: 8 } },
                y: { grid: { color: palette.grid }, ticks: { color: palette.tick }, min },
            },
        });

        const dataset = (data, color) => ({
            data,
            borderColor: color,
            backgroundColor: 'transparent',
            borderWidth: 2,
            tension: 0.35,
            pointRadius: 2,
            pointBackgroundColor: color,
        });

        const draw = () => {
            const series = @js($series);

            const wpmEl = document.getElementById('statsWpmChart');
            if (wpmEl && series.wpm.length >= 2) {
                wpmChart?.destroy();
                wpmChart = new Chart(wpmEl.getContext('2d'), {
                    type: 'line',
                    data: { labels: series.labels, datasets: [dataset(series.wpm, palette.brand)] },
                    options: baseOptions(0),
                });
            }

            const accEl = document.getElementById('statsAccuracyChart');
            if (accEl && series.accuracy.length >= 2) {
                accChart?.destroy();
                // Accuracy is rarely below 90%: start the axis at 90 so the variation reads.
                const lowest = Math.min(...series.accuracy);
                accChart = new Chart(accEl.getContext('2d'), {
                    type: 'line',
                    data: { labels: series.labels, datasets: [dataset(series.accuracy, palette.gold)] },
                    options: baseOptions(Math.min(90, Math.floor(lowest))),
                });
            }
        };

        // Chart comes from the Vite bundle (window.Chart via app.js), not a CDN runtime.
        draw();

        // Change the day range -> Livewire re-renders -> redraw the charts.
        Livewire.hook('morph.updated', ({ component }) => {
            if (component.id === $wire.id) draw();
        });
    </script>
    @endscript
</div>
