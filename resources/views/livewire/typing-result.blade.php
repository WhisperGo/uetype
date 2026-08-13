<div class="text-muted font-mono selection:bg-brand selection:text-foreground outline-none py-16"
    x-data
    {{-- Tab moves focus to THIS screen's primary action. The target is resolved first and the
         key is only swallowed once one exists: the previous version called preventDefault()
         and then getElementById('restartButton').focus() with no guard, so on a Clan War
         result -- where the primary action is <x-result-back-to-war />, not #restartButton --
         every Tab press threw a TypeError after the key had already been eaten, leaving
         nothing reachable by keyboard at all.

         An attribute rather than an id: the primary action is "back to war" on one branch and
         "next test" on another, and an id named restartButton cannot honestly name both. --}}
    @keydown.window="if ($event.key === 'Tab') {
        const target = $root.querySelector('[data-result-primary]');
        if (target) { $event.preventDefault(); target.focus(); }
    }">
    {{-- `sm:px-6 lg:px-8` to match every other page (see x-page-container). This was the one
         page stuck at a flat `px-4`, so from 640px up its content sat closer to the edge than
         the rest of the app -- visible as soon as you moved between pages. --}}
    <div class="max-w-6xl w-full px-4 sm:px-6 lg:px-8 mx-auto">

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

            $wordsTyped = (int) floor(($correctKeystrokes ?? 0) / 5);

            $pbSeconds = $survivalPreviousBest !== null ? (int) round($survivalPreviousBest) : null;
            $pbMins = $pbSeconds !== null ? intdiv($pbSeconds, 60) : null;
            $pbSecsPart = $pbSeconds !== null ? $pbSeconds % 60 : null;
            $survivalDelta = $pbSeconds !== null ? $pbSeconds - (int) $time : null;

            // Non-survival stat cards: raw wpm + accuracy + characters always present;
            // consistency & duration are conditional. Duration is DROPPED in time mode
            // (redundant with the "Time · Ns" label above the hero), but kept in words mode
            // (duration varies there).
            $statCount = 3 + (! is_null($consistency) ? 1 : 0) + ($mode === 'words' ? 1 : 0);
            // Survival-style stat row, now FULL-WIDTH (aligned with the chart) -> fits one full
            // row: 3 (time without consistency), 4 (time), 5 (words). At this width 5 cards in a
            // row look tidier than 3+2, which leaves a conspicuous empty cell.
            $statCols = match ($statCount) {
                3 => 'sm:grid-cols-3',
                5 => 'sm:grid-cols-5',
                default => 'sm:grid-cols-4',
            };
            // Odd count -> the characters card (the last one) spans full width on the mobile
            // 2-column layout so no cell is left dangling.
            $charsSpan = $statCount % 2 === 1 ? 'col-span-2 sm:col-span-1' : '';
        @endphp

        {{-- Abandoned run: every number below is still shown, but nothing was written to the --}}
        {{-- database (no XP, no personal best, not in stats). Deliberately `gold` and not the --}}
        {{-- `danger` used by the multiplayer reject banner: that one is an anti-cheat verdict, --}}
        {{-- while walking away is not an accusation -- just a session that wasn't really typed. --}}
        @if ($afk)
            <div class="mb-6 flex items-center gap-2 rounded-lg border border-gold/40 bg-gold/10 px-4 py-3 text-x-small font-mono text-gold">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span>{{ __('result.afk_not_recorded') }}</span>
            </div>
        @endif

        @if (! $afk && $resultStatus === 'pending')
            <div role="status" class="mb-6 rounded-lg border border-gold/40 bg-gold/10 px-4 py-3 font-mono text-gold">
                <p class="flex items-center gap-2 text-x-small font-bold">
                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ __('result.integrity.pending_title') }}</span>
                </p>
                <p class="mt-2 text-x-small text-gold/80">{{ __('result.integrity.pending_body') }}</p>
                @auth
                    @if(in_array($mode, ['time', 'words'], true)
                        && in_array($resultReason, ['no_history_high', 'longitudinal_spike'], true))
                        <a href="{{ route('typing.verify') }}"
                            class="mt-3 inline-flex min-h-11 items-center rounded-lg bg-gold px-4 py-2 text-x-small font-bold text-background transition hover:bg-gold/90">
                            {{ __('result.integrity.verify_speed') }}
                        </a>
                    @endif
                @endauth
            </div>
        @elseif (! $afk && $resultStatus === 'clear')
            <div role="status" class="mb-6 flex items-center gap-2 rounded-lg border border-brand/30 bg-brand/10 px-4 py-3 text-x-small font-mono text-brand-bright">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 12 4 4L19 6" />
                </svg>
                <span>{{ $autoClearedCount > 1
                    ? __('result.integrity.auto_cleared', ['count' => $autoClearedCount])
                    : __('result.integrity.clear') }}</span>
            </div>
        @endif

        {{-- Achievements this session unlocked are announced as a TOAST, handed to the global
             stack in app.blade.php rather than drawn here.

             Queued on window instead of dispatched as an event: <x-toast-stack /> is mounted
             AFTER the page slot, so an event fired while this page initialises would have no
             listener yet. The queue works whichever order the two arrive in.

             ALL of them go in ONE toast: push() shows a single toast at a time (a new one
             replaces the old), so sending two would silently drop the first. Titles are
             resolved here from the lang files -- their only home -- and the separator comes
             from there too rather than being hardcoded in JavaScript.

             Nothing is queued for an abandoned run or a guest: neither records anything, so
             the list is empty and this block never renders.

             Runs exactly once because this screen never re-renders in place -- its only
             action, retry(), always ends in a full redirect. An action that re-rendered
             instead would queue the same toast again. --}}
        @if (! empty($newAchievements))
            <script>
                window.__uetypeToasts = window.__uetypeToasts || [];
                window.__uetypeToasts.push({
                    icon: 'star',
                    tone: 'gold',
                    title: @js(__('result.achievement_unlocked')),
                    message: @js(collect($newAchievements)->map(fn ($key) => __('achievements.defs.'.$key.'.title'))->join(__('common.list_separator'))),
                    href: @js(route('achievements.index')),
                });
                // Nudges the stack in case it is already alive (it drains the queue itself
                // on mount, so this can never show the same toast twice).
                window.dispatchEvent(new CustomEvent('uetype-toast'));
            </script>
        @endif

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

                <div class="relative overflow-hidden rounded-3xl border border-border bg-surface/60 px-5 xs:px-8 py-10 text-center">
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
                        <x-xp-bar :earned="$xpEarned" :level="$levelData" />
                    @endif
                @endauth

                @if ($war)
                    <x-result-war-points :war="$war" :accuracy="$accuracy" />
                    <x-result-back-to-war />
                @else
                    <a id="restartButton" data-result-primary href="/typing"
                        class="inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-mono font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition">
                        <span>{{ __('result.play_again_title') }}</span>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                        </svg>
                    </a>
                @endif
            </div>
        @else

        {{-- Scoreboard (full-width, aligned with the chart block below it): hero card
             centered -> stat row -> XP. Mirrors survival's vertical ORDER, but its width
             follows the chart (not the narrow max-w-2xl column) so the stat & XP cards
             don't look like they're floating above the wide chart. Hero content stays centered. --}}
        <div class="flex flex-col gap-8">

            {{-- Hero card: reuses the survival card pattern (top accent line + centered), but the
                 accent is GOLD not danger -- solo isn't "game over". The mode label plays the role
                 of the "game over" line above the hero; heroLabel ("wpm") plays the role of "survived". --}}
            <div class="relative overflow-hidden rounded-3xl border border-border bg-surface/60 px-5 xs:px-8 py-10 text-center">
                <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-transparent via-gold to-transparent opacity-70"></div>

                <p class="font-mono text-xs uppercase tracking-[0.35em] text-muted mb-4">{{ $modeLabel }}</p>

                <div class="flex flex-col items-center gap-1">
                    <span class="font-display text-fluid-hero text-gold leading-none tabular-nums">{{ $heroValue }}</span>
                    <span class="font-mono text-xs uppercase tracking-[0.3em] text-muted mt-3">{{ $heroLabel }}</span>
                </div>

                {{-- Achievement moment = gold pill (matching the PB treatment in the survival
                     branch around line 82), NOT small text. Solo can show a PB AND a ghost at once,
                     so this stays a column.

                     The record is mentioned ONLY when it is broken. There used to be an
                     "-39 vs record 70.2" line on every other session, which compared the run
                     against a global cross-mode record it could not fairly be measured
                     against -- so it was near-permanently negative and read as a reminder of
                     failure rather than information. --}}
                <div class="mt-6 flex flex-col items-center gap-2">
                    @if ($isPersonalBest)
                        <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-gold/10 border border-gold/40 text-gold font-mono text-sm">
                            <span>✦</span> {{ __('result.new_personal_best') }}
                        </span>
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

            {{-- Survival-style stat row (grid-cols-2 sm:grid-cols-4). We're already in the
                 non-survival branch, so these are solo-only cards: raw wpm + accuracy + characters
                 always present; consistency & duration (words) conditional. --}}
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
                {{-- Duration is for WORDS only: in time mode it always equals the config
                     (e.g. the "Time · 15s" label), so the card would just repeat it. --}}
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

            {{-- XP + level bar (full-width within the centered column) --}}
            @auth
                @if ($levelData)
                    <x-xp-bar :earned="$xpEarned" :level="$levelData" />
                @endif
            @endauth
        </div>{{-- /scoreboard center --}}

        {{-- Error-review block: full-width chart + heatmap RIGHT below it, wrapped in one island
             so clicking a chart point immediately highlights the keys. Because the two now sit
             adjacent, revealHeatmap() (scrollIntoView) becomes a no-op when already visible --
             kept as a safety net. --}}
        <div x-data="errorInspector(@js($this->errorSeries['events']))"
            x-on:error-inspect.window="select($event.detail.index)"
            class="mt-10 flex flex-col gap-6">

            <!-- ===== CHART (full-width) ===== -->
            <div class="bg-surface/40 border border-white/5 rounded-2xl p-4 md:p-6 flex flex-col">
                <h3 class="font-mono text-xs uppercase tracking-[0.2em] text-muted mb-2">{{ $isSurvival ? __('result.chart_stamina') : __('result.chart_performance') }}</h3>

                {{-- HTML legend, not Chart.js's built-in plugins.legend: the built-in one is drawn
                     on the canvas (can't inherit the font-mono/tracking tokens) and its items are
                     clickable to hide datasets -- an affordance we don't want here. Its shape
                     deliberately matches the heatmap legend below.
                     Colors are hardcoded to be EXACTLY the same as the dataset colors in the @script
                     block; #C69F68 happens to equal the gold token, but #475569 & #F43F5E really are
                     outside the tokens (see the dataset notes). When design-cleanup runs, change both places. --}}
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1.5 font-mono text-[0.65rem] text-muted/60 mb-3">
                    <span class="inline-flex items-center gap-2">
                        <span class="w-4 h-[3px] rounded-full bg-gold"></span>
                        {{ __('result.wpm') }}
                    </span>
                    <span class="inline-flex items-center gap-2">
                        <span class="w-4 h-[2px] rounded-full" style="background-color: #475569;"></span>
                        {{ __('result.stat.raw_wpm') }}
                    </span>
                    {{-- Follows the y1 axis rule (display: hasErrors): a clean run -> no points,
                         no axis, so no legend entry either. --}}
                    @if (array_sum($this->errorSeries['counts']) > 0)
                        <span class="inline-flex items-center gap-2">
                            <span class="font-bold leading-none" style="color: #F43F5E;">✕</span>
                            {{ __('result.error_axis') }}
                        </span>
                    @endif
                </div>

                {{-- Canvas MUST be absolute: out-of-flow contributes zero to intrinsic size, so the
                     grid row's height doesn't depend on the canvas. If it were in-flow again, the
                     dependency becomes circular (row <- card <- canvas <- Chart.js reads parent) and
                     Chart.js enters a resize loop. flex-basis:0% isn't enough to break it. --}}
                {{-- EXPLICIT height (no longer lg:flex-1 following the left column's height):
                     the chart now has the same proportions for guest & logged-in users. The canvas
                     stays absolute so it adds no intrinsic size -> Chart.js won't enter a resize loop. --}}
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

                const renderChart = async () => {
                    const canvas = document.getElementById('wpmChart');
                    if (!canvas) return;

                    // Chart.js is loaded on demand (its own Vite chunk), not shipped in the main
                    // bundle -- see app.js window.ensureChart & docs/review-performance-2026-07-27.md.
                    const Chart = await window.ensureChart();
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
                                    // line + showLine:false, NOT type:'scatter'. This dataset must use
                                    // the same parsing path as the two above it: the x axis here is a
                                    // CATEGORY scale (labels 1..N) with a plain numeric array indexed by
                                    // position. ScatterController parses {x,y} by default -- that works,
                                    // but via a different path, and interaction.mode:'index' depends on
                                    // this one. showLine:false yields an identical result.
                                    type: 'line',
                                    showLine: false,
                                    // null = point not drawn. A point sitting at 0 is just noise along
                                    // the axis; here it's the ABSENCE of a point that's informative.
                                    data: errorCounts.map(c => c > 0 ? c : null),
                                    yAxisID: 'y1',
                                    pointStyle: 'crossRot',   // an X, not a dot -- reads as "error"
                                    pointRadius: 5,
                                    pointHoverRadius: 7,
                                    pointHitRadius: 12,
                                    // #F43F5E exactly matches the inline rgba(244,63,94) in the heatmap.
                                    // DELIBERATELY uses the deprecated typing.error token, not
                                    // --color-danger: the points & heatmap are THE SAME DATA and must
                                    // look like the same thing. Two different reds would imply two
                                    // different metrics. When design-cleanup runs, change BOTH.
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
                            // mode 'index' matches the interaction.mode the tooltip already uses:
                            // clicking anywhere within one second-column selects that second -- the
                            // click target becomes the full chart height, not the 5px point. Clicks on
                            // columns WITHOUT errors are still dispatched: the island closes its own
                            // panel, so no close button is needed.
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
                                    // Right-side grid not drawn in the chart area: two sets of
                                    // gridlines in a card this small make the wpm line hard to read.
                                    grid: {
                                        drawOnChartArea: false
                                    },
                                    ticks: {
                                        color: '#94a3b8',
                                        precision: 0,
                                        stepSize: 1
                                    },
                                    // Clean session (all data null) -> Chart.js has no min/max & the
                                    // axis becomes a broken-looking 0..1. suggestedMax keeps the range
                                    // sane; display hides it entirely: no errors, no error axis, and
                                    // the chart looks exactly as it did before.
                                    suggestedMax: 5,
                                    // display: hasErrors turns off the WHOLE axis (title included) on a
                                    // clean run -> no errors, no right-hand axis at all.
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

                // Chart comes from a lazily-loaded Vite chunk (window.ensureChart in app.js), not a CDN.
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
            // Clean run = no missed keys AND no error points. Use BOTH so that old sessions
            // (missedChars populated but no errorEvents) still count as having errors ->
            // the heatmap still renders (keeps the assertSee 'error heatmap' in the layout test).
            $isCleanRun = empty($missedChars) && array_sum($this->errorSeries['counts']) === 0;
        @endphp

        @if ($isCleanRun)
            {{-- Clean run: DON'T show an empty error console + a "click a marker" hint that's
                 impossible (there are no markers). Show a positive acknowledgement instead; the
                 chart above stays. --}}
            <div class="bg-surface/40 border border-white/5 rounded-2xl p-6 flex items-center justify-center gap-3">
                <span class="text-gold text-lg leading-none">✦</span>
                <span class="font-mono text-sm text-gold uppercase tracking-[0.2em]">{{ __('result.error_none') }}</span>
            </div>
        @else
        <div x-ref="heatmap"
            class="bg-surface/40 border border-white/5 rounded-2xl p-4 md:p-6 flex flex-col items-center gap-2">
            <h3 class="font-mono text-xs uppercase tracking-[0.2em] text-muted mb-0 self-start">{{ __('result.error_heatmap') }}</h3>
            {{-- Chart points & heatmap count the TARGET CHARACTERS that failed to be produced;
                 the "characters" tile counts KEYSTROKES. The two are intentionally different (see
                 docs), so the definition is stated here -- right at the point of confusion. --}}

            {{-- Idle: just a brief hint (the affordance that the chart is clickable), WITHOUT a
                 large min-h -- so there's no gaping empty space before a point is clicked. When a
                 point is clicked, the detail panel expands SMOOTHLY via x-collapse (no jump).
                 x-show, not x-if: x-collapse needs the element to stay present to animate its
                 height. If the Collapse plugin is absent, x-collapse becomes a no-op & the panel
                 still toggles instantly (graceful). Access to `selected` is guarded (?. / ?? [])
                 because the element is now always in the DOM even while `selected` is still null. --}}
            <div class="w-full mt-2 self-start">
                <p x-show="! open" class="font-mono text-xs text-muted/70">{{ __('result.error_hint') }}</p>

                <div x-show="open" x-collapse class="flex flex-col gap-1.5">
                        <p class="font-mono text-xs uppercase tracking-[0.2em] text-muted"
                            x-text="@js(__('result.error_at')).replace(':second', selected?.[0]?.label ?? '')"></p>

                        {{-- Multiple errors in one second -> one row per error (can be the same
                             word at different offsets). Honest and literal. --}}
                        {{-- :key is POSITION, not e.second+e.index: `index` never exists in the
                             enrich output (key "10-undefined" for all -> Alpine treats them as
                             duplicates & renders only 1 row). Backspace-then-retype also produces
                             two events with the SAME {second,index}, so any field from the data
                             can still collide. selected is replaced wholesale & never reordered, so
                             the array position is a safe key. --}}
                        <template x-for="(e, i) in (selected ?? [])" :key="i">
                            {{-- Button: this row is what drives the ring pair on the keyboard.
                                 The selected marker & cursor only appear when there's actually a
                                 choice (>1 error) -- with only one, a button that looks "clickable"
                                 but changes nothing is a false promise. --}}
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

            {{-- overflow-x-auto forces overflow-y to CLIP too (per spec: overflow-y:visible
                 computes to auto once overflow-x isn't visible), so anything that spills outside
                 the box needs explicit room here. The miss-count tips now stay WITHIN the board
                 (each lands over an adjacent row -- see the tip's row-aware position), so only the
                 selected-key ring needs slack: ring-offset-2 + ring-2 draw ~4px past the top and
                 bottom rows -> pt-2 / pb-2. --}}
            <div class="w-full overflow-x-auto pt-2 pb-2">
            <div class="flex flex-col gap-1 sm:gap-2 md:gap-3 w-max mx-auto">
                {{-- Staircase offset per row. Smaller on mobile (ml-3/ml-6) so the whole board
                     fits a phone without horizontal scroll; sm+ restores the roomier ml-6/ml-12
                     (== the old inline 1.5rem/3rem). --}}
                @php $rowIndent = ['', 'ml-3 sm:ml-6', 'ml-6 sm:ml-12']; @endphp
                @foreach ($keyboard as $rowIndex => $row)
                    <div class="flex justify-center gap-1 sm:gap-2 md:gap-3 {{ $rowIndent[$rowIndex] ?? '' }}">
                        @foreach ($row as $key)
                            @php
                                $missCount = $missedChars[$key] ?? 0;
                                $opacity = $maxMiss > 0 && $missCount > 0 ? 0.3 + ($missCount / $maxMiss) * 0.7 : 0;
                                $style =
                                    $opacity > 0
                                        ? "background-color: rgba(244, 63, 94, {$opacity}); color: #e2e8f0;"
                                        : 'background-color: rgba(255,255,255,0.04); color: #94a3b8;';
                            @endphp
                            {{-- GOLD ring, not red: this key already has a red background (miss
                                 opacity), and red on red is unreadable. Gold = the "selected" color
                                 across the whole app. Use ring/outline NOT background: the inline
                                 style below fully owns this key's background-color.
                                 Solid = the key you NEEDED; dashed = the key you PRESSED. --}}
                            <div data-key="{{ $key }}"
                                class="w-6 h-6 sm:w-10 sm:h-10 md:w-12 md:h-12 rounded sm:rounded-lg flex items-center justify-center text-[0.6rem] sm:text-sm md:text-base font-bold transition-colors relative group"
                                :class="{
                                    'ring-2 ring-gold ring-offset-2 ring-offset-surface': expectedKeys.includes(@js($key)),
                                    'outline outline-2 outline-dashed outline-offset-2 outline-gold/50': actualKey === @js($key),
                                }"
                                style="{{ $style }}">
                                {{ strtoupper($key) }}

                                @if ($missCount > 0)
                                    {{-- Position is SIZE-RELATIVE (bottom-full/top-full), not a
                                         fixed -top-10 that ignored the key size and clipped above
                                         the board. Row-aware: the top row drops its tip BELOW, all
                                         other rows above -- so every tip lands over an adjacent row,
                                         never past the keyboard's top/bottom edge. Centered on the
                                         key so it doesn't lean off the right edge. --}}
                                    <div
                                        class="absolute left-1/2 -translate-x-1/2 {{ $rowIndex === 0 ? 'top-full mt-2' : 'bottom-full mb-2' }} bg-surface text-danger px-2 py-1 rounded text-xs opacity-0 group-hover:opacity-100 transition-opacity pointer-events-none whitespace-nowrap z-20 shadow-lg border border-danger/50">
                                        {{ __('result.miss_count', ['count' => $missCount]) }}
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
            </div>

            {{-- Legend: MOVED below the keyboard so the reading flow is hint -> keyboard ->
                 symbol explanation, and it no longer wedges tightly between the hint & keyboard.
                 Centered (justify-center) to follow the keyboard, which is also centered. Swatches
                 use the EXACT same ring/outline classes as the keys -- it teaches itself. --}}
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

        {{-- Action buttons: full-width below the entire summary + error-review block. --}}
        {{-- War attempt: the run is one-shot (the claim is filled), so Next Test / Retry are
             replaced by a single way back to the war. Solo keeps the usual randomized flow. --}}
        @if ($war)
            <div class="mt-10 space-y-4">
                <x-result-war-points :war="$war" :accuracy="$accuracy" />
                <x-result-back-to-war />
            </div>
        @else
            <div class="mt-10 flex items-stretch gap-3">
                <a id="restartButton" data-result-primary href="/typing"
                    class="flex-1 inline-flex items-center justify-center gap-2 h-12 rounded-2xl bg-gold text-background font-mono font-semibold text-sm hover:opacity-90 focus:outline-none focus-visible:ring-2 focus-visible:ring-gold/50 transition"
                    title="{{ __('result.next_test_title') }}">
                    <span>{{ __('result.next_test_title') }}</span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7" />
                    </svg>
                </a>
                {{-- Retry is for Words only: replays the exact same sequence of words
                     (via retry() -> session typing_retry), unlike Next Test which is randomized. --}}
                @if ($mode === 'words' && $textToType)
                    <button type="button" wire:click="retry"
                        class="inline-flex items-center justify-center h-12 px-6 rounded-2xl bg-surface border border-white/5 text-foreground/80 hover:text-foreground hover:border-white/10 font-mono font-semibold text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-border transition"
                        title="{{ __('result.retry_title') }}">
                        {{ __('result.retry_title') }}
                    </button>
                @endif
            </div>
        @endif

        @endif
    </div>
</div>

{{-- The Alpine factory MUST live in a plain <script>, NOT @script: a plain script runs during
     HTML parsing (before Alpine walks the DOM & evaluates x-data), whereas @script runs at
     Livewire init -- racing that walk and throwing "errorInspector is not defined". The engine's
     typingGame() uses the same split. --}}
<script>
    function errorInspector(events) {
        return {
            // Grouped on the client, not in PHP: a PHP array with contiguous int keys 0..n is
            // encoded as an ARRAY by json_encode, rarely as an OBJECT. Grouping here removes that
            // shape trap entirely. Each second is then run through mergeSkippedRuns() -> "display
            // rows": a run of consecutive SKIPPED characters within one word becomes ONE row (the
            // chart & heatmap stay per-character; this is purely presentational).
            grouped: Object.fromEntries(
                Object.entries(
                    events.reduce((acc, e) => ((acc[e.second] ??= []).push(e), acc), {})
                ).map(([sec, evs]) => [sec, mergeSkippedRuns(evs)])
            ),
            selected: null,
            // Panel visibility is SEPARATED from `selected`. x-collapse measures the element's
            // height exactly when x-show flips to true; if visibility were bound directly to
            // `selected`, the x-show effect (on the parent) could run BEFORE x-for (on the child)
            // inserts the rows -> the measured height is only the label's height -> the animation
            // expands to the wrong height and then "jumps" to full. `open` is flipped AFTER the
            // content renders (see select()).
            open: false,
            // The row currently highlighted WITHIN the selected second. The keyboard highlights
            // keys from ONE row only (a run row can have many "needed" keys; a mistyped row = 1
            // need->press pair). Highlighting the whole second at once collapses the meaning: one
            // key could be "the needed one" in error A and "the pressed one" in error B, and
            // solid-vs-dashed loses its meaning.
            row: 0,

            select(index) {
                const group = this.grouped[index] ?? null;

                // Click on a column without errors -> close the panel & clear the keyboard highlight.
                if (! group) {
                    this.open = false;
                    this.selected = null;
                    this.row = 0;
                    return;
                }

                // Fill the content FIRST: x-for renders the rows on this flush tick.
                this.selected = group;
                this.row = 0;

                // Only open on the next tick, once the rows are in the DOM -> x-collapse measures
                // the correct height & the animation is smooth (no jump). The scroll is also
                // deferred to here so it isn't computed while the panel is still 0 height.
                this.$nextTick(() => {
                    this.open = true;
                    this.revealHeatmap();
                });
            },

            get current() {
                return this.selected?.[this.row] ?? null;
            },

            // The "needed" keys highlighted on the keyboard. An array, not a single string: a
            // skipped-run row represents MULTIPLE characters -> all get ringed at once. A mistyped
            // / single-skip row = a 1-element array (behavior identical to before). Already
            // lowercased in mergeSkippedRuns to match the heatmap keys (lowercase from
            // missedChars). The panel still shows the ORIGINAL characters.
            get expectedKeys() {
                return this.current?.keys ?? [];
            },

            get actualKey() {
                return (this.current?.actual ?? '').toLowerCase();
            },

            revealHeatmap() {
                // block:'nearest', NOT 'center'. 'nearest' scrolls the MINIMUM possible and does
                // nothing to an element that's already fully visible -- so "scroll only if
                // off-screen" is the browser's built-in semantics. A manual getBoundingClientRect
                // check would actually be wrong when the card is taller than the viewport, and
                // 'center' would push the chart off-screen.
                this.$refs.heatmap?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            },
        };
    }

    // Turns one second's raw events into "display rows": CONSECUTIVE SKIPPED characters
    // (actual === null) within the SAME word are merged into one row (e.g. typing "a" then
    // jumping -> "bout" becomes one run a[bout]) so the panel doesn't repeat "skipped" per letter.
    // PURELY presentational: the chart counts & missedChars/heatmap stay per-character (from PHP
    // inspect). Events within one second are already in typing order = index order, so a contiguous
    // skipped run is guaranteed adjacent. Mistyped errors & single skips become plain rows
    // (offsetEnd === offset).
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
                offset: e.offset,               // underline start
                offsetEnd: e.offset,            // underline end (== offset for a single row)
                expected: e.expected,
                actual: e.actual,
                skipped: isSkip,
                keys: [(e.expected ?? '').toLowerCase()],   // "needed" keys for the keyboard highlight
            });
        }

        return rows;
    }
</script>
