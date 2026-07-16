<div
    class="flex flex-col flex-1 min-h-full text-muted font-mono selection:bg-brand selection:text-foreground outline-none">
    <div wire:key="typing-app-{{ $mainMode }}-{{ $subMode }}-{{ $typingSessionKey }}" class="flex flex-col flex-1 justify-center min-h-0" x-data="{
        currentMain: @entangle('mainMode'),
        currentSub: @entangle('subMode'),
        ...typingGame(@js($textToType))
    }"
        @keydown.window="
            syncCapsLock($event);
            const ae = document.activeElement;
            const editing = ae && (ae.tagName === 'INPUT' || ae.tagName === 'TEXTAREA' || ae.isContentEditable);
            if($event.key === 'Tab') {
                $event.preventDefault();
                document.getElementById('restartButton')?.focus();
            } else if (ae && ae.tagName !== 'BUTTON' && !editing) {
                // Jangan tangkap ketikan saat fokus di field lain (mis. input chat overlay) —
                // biar ketikannya masuk ke sana saja, tak bocor ke area typing di belakang.
                handleInput($event);
            }
        "
        @keyup.window="syncCapsLock($event)"
        x-on:ghost-selected.window="if (ghostEligible()) { window.__uetypeGhostSelection = { active: true, wpm: $event.detail.wpm, label: $event.detail.label }; ghostActive = true; ghostWpm = $event.detail.wpm; ghostLabel = $event.detail.label; ghostCharIndex = 0; ghostFinished = false; ghostFinishTime = null; $nextTick(() => { const pos = getCharPosition(0); if (pos) { ghostCursorLeft = pos.left; ghostCursorTop = pos.top; } if (isStarted) startGhostAnimationLoop(); }) }"
        x-on:ghost-cleared.window="window.__uetypeGhostSelection = null; ghostActive = false; ghostWpm = 0; ghostLabel = ''; stopGhostAnimationLoop();">

        {{-- GhostPicker: komponen Livewire terpisah; wire:key men-scope ulang daftarnya per mode. --}}
        <livewire:ghost-picker :main-mode="$mainMode" :sub-mode="$subMode"
            wire:key="ghost-picker-{{ $mainMode }}-{{ $subMode }}" />

        <div x-cloak aria-hidden="true"
            class="fixed inset-0 z-40 pointer-events-none transition-opacity duration-300 [will-change:opacity]"
            style="background: radial-gradient(ellipse at center, transparent 55%, rgb(var(--color-danger) / 0.55) 100%);"
            :style="`opacity: ${
                (() => {
                    const active = currentMain === 'survival' && isStarted && !isFinished;
                    const base = (!active || staminaPct >= 40) ? 0 : Math.min(1, (40 - staminaPct) / 40);
                    return drainFlash && active ? Math.max(base, 0.6) : base;
                })()
            };`"></div>

        <div class="w-full max-w-5xl mx-auto shrink-0 px-4 sm:px-6 lg:px-8 py-6">

            @if (session('result_rejected'))
                <div
                    class="max-w-xl mx-auto mb-8 flex items-center gap-3 px-4 py-3 rounded-xl bg-danger/10 border border-danger/40 text-danger text-sm">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
                    </svg>
                    <span class="font-mono">{{ session('result_rejected') }}</span>
                </div>
            @endif

            @if ($warLock)
                {{-- WAR-LOCK: mode dikunci klaim Clan War; kontrol mode disembunyikan (server juga menolak setMode). --}}
                <div class="flex flex-col items-center gap-2 mb-2 transition-opacity duration-500"
                    :class="isStarted ? 'opacity-0 pointer-events-none' : 'opacity-100'">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gold/10 border border-gold/40">
                        <svg class="w-4 h-4 text-gold shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <span class="text-small font-mono font-bold text-gold">
                            Clan War ·
                            @if ($warLock['mode'] === 'survival')
                                Survival {{ ucfirst($warLock['config']) }}
                            @elseif ($warLock['mode'] === 'time')
                                Time {{ $warLock['config'] }}s
                            @else
                                Words {{ $warLock['config'] }}
                            @endif
                        </span>
                    </div>
                    <a href="{{ route('clan-war.index') }}" wire:navigate class="text-x-small font-mono text-muted hover:text-foreground transition">
                        ← Batalkan &amp; kembali ke Clan War
                    </a>
                </div>
            @else
            <!-- MODE CONTROL BAR: Standard/Survival/Ghost → config → bahasa konten.
                 Saat mengetik hanya di-fade (ruang tetap dipesan) agar tak ada layout shift.
                 "Standard" cuma grup visual; mainMode backend tetap time/words. -->
            <div class="flex flex-col items-center gap-3 mb-2 transition-opacity duration-500"
                :class="isStarted ? 'opacity-0 pointer-events-none' : 'opacity-100'">

                <!-- Row 1: Mode utama (segment) -->
                <div class="inline-flex items-stretch gap-0.5 p-[3px] rounded-lg bg-surface border border-border"
                    role="group" aria-label="{{ __('typing.aria.pick_main_mode') }}">
                    <button type="button" aria-label="{{ __('typing.aria.mode_standard') }}"
                        :aria-pressed="['time','words'].includes(currentMain)"
                        @click.prevent="if(!['time','words'].includes(currentMain)){ currentMain='time'; currentSub='30'; $wire.setMode('time','30'); } $el.blur()"
                        class="px-3 sm:px-[18px] py-[7px] rounded-md text-small font-mono font-bold transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand"
                        :class="['time','words'].includes(currentMain) ? 'bg-brand text-foreground' : 'text-muted hover:text-foreground'">{{ __('typing.standard') }}</button>

                    <button type="button" aria-label="{{ __('typing.aria.mode_survival') }}"
                        :aria-pressed="currentMain === 'survival'"
                        @click.prevent="currentMain='survival'; currentSub='medium'; $wire.setMode('survival','medium'); $el.blur()"
                        class="px-3 sm:px-[18px] py-[7px] rounded-md text-small font-mono font-bold transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand"
                        :class="currentMain === 'survival' ? 'bg-brand text-foreground' : 'text-muted hover:text-foreground'">{{ __('typing.survival') }}</button>
                </div>

                <!-- Row 2: Config (Standard → Time/Words + durasi; Survival → difficulty) -->
                <div class="flex flex-wrap items-center justify-center gap-1.5 min-h-[34px] text-small font-mono"
                    role="group" aria-label="{{ __('typing.aria.mode_config') }}">
                    <!-- STANDARD: pemilih tipe + sub-konfigurasi -->
                    <template x-if="['time','words'].includes(currentMain)">
                        <div class="flex flex-wrap items-center justify-center gap-1.5">
                            @foreach (['time' => __('typing.type_time'), 'words' => __('typing.type_words')] as $type => $label)
                                <button type="button" aria-label="{{ __('typing.aria.type', ['label' => $label]) }}"
                                    :aria-pressed="currentMain === '{{ $type }}'"
                                    @click.prevent="currentMain='{{ $type }}'; currentSub='{{ $type === 'time' ? '15' : '25' }}'; $wire.setMode('{{ $type }}', currentSub); $el.blur()"
                                    class="px-3 sm:px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-brand"
                                    :class="currentMain === '{{ $type }}' ? 'bg-elevated border-border text-foreground font-bold' : 'border-border text-muted hover:text-foreground'">{{ $label }}</button>
                            @endforeach

                            <span class="w-px h-4 bg-border mx-1" aria-hidden="true"></span>

                            <template x-if="currentMain === 'time'">
                                <div class="flex gap-1.5">
                                    @foreach (['15', '30', '60', '120'] as $t)
                                        <button type="button" aria-label="{{ __('typing.aria.duration_seconds', ['seconds' => $t]) }}"
                                            :aria-pressed="currentSub == '{{ $t }}'"
                                            @click.prevent="currentSub='{{ $t }}'; $wire.setMode('time','{{ $t }}'); $el.blur()"
                                            class="px-3 sm:px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-gold"
                                            :class="currentSub == '{{ $t }}' ? 'bg-gold border-gold text-background font-bold' : 'border-border text-muted hover:text-foreground'">{{ $t }}s</button>
                                    @endforeach
                                </div>
                            </template>
                            <template x-if="currentMain === 'words'">
                                <div class="flex gap-1.5">
                                    @foreach (['10', '25', '50', '100'] as $w)
                                        <button type="button" aria-label="{{ __('typing.aria.words_count', ['count' => $w]) }}"
                                            :aria-pressed="currentSub == '{{ $w }}'"
                                            @click.prevent="currentSub='{{ $w }}'; $wire.setMode('words','{{ $w }}'); $el.blur()"
                                            class="px-3 sm:px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-gold"
                                            :class="currentSub == '{{ $w }}' ? 'bg-gold border-gold text-background font-bold' : 'border-border text-muted hover:text-foreground'">{{ $w }}</button>
                                    @endforeach
                                </div>
                            </template>
                        </div>
                    </template>

                    <!-- SURVIVAL: difficulty -->
                    <template x-if="currentMain === 'survival'">
                        <div class="flex gap-1.5">
                            @foreach (['easy', 'medium', 'hard'] as $d)
                                <button type="button" aria-label="{{ __('typing.aria.difficulty', ['level' => $d]) }}"
                                    :aria-pressed="currentSub == '{{ $d }}'"
                                    @click.prevent="currentSub='{{ $d }}'; $wire.setMode('survival','{{ $d }}'); $el.blur()"
                                    class="px-3 sm:px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none capitalize hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-gold"
                                    :class="currentSub == '{{ $d }}' ? 'bg-gold border-gold text-background font-bold' : 'border-border text-muted hover:text-foreground'">{{ $d }}</button>
                            @endforeach
                        </div>
                    </template>
                </div>

                <!-- Baris Ghost: lawan tambahan hanya untuk Standard (time/words); hilang saat Survival. -->
                <template x-if="['time','words'].includes(currentMain)">
                    <div class="flex items-center justify-center gap-2 text-small font-mono" role="group"
                        aria-label="{{ __('typing.aria.mode_ghost') }}">
                        <template x-if="!ghostActive">
                            <button type="button"
                                @click.prevent="$dispatch('open-modal', 'ghost-picker'); $el.blur()"
                                class="px-3 py-[6px] rounded-md border border-border text-muted hover:text-foreground transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand">
                                {{ __('typing.ghost_pick') }}</button>
                        </template>
                        <template x-if="ghostActive">
                            <div class="inline-flex items-center gap-2">
                                <span class="text-muted">{{ __('typing.ghost') }}</span>
                                <span class="text-gold font-bold">- <span x-text="ghostLabel"></span></span>
                                <button type="button"
                                    @click.prevent="$dispatch('open-modal', 'ghost-picker'); $el.blur()"
                                    class="px-2.5 py-[5px] rounded-md border border-border text-muted hover:text-foreground transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand">
                                    {{ __('typing.ghost_change') }}</button>
                                <button type="button"
                                    @click.prevent="$dispatch('ghost-cleared'); $el.blur()"
                                    class="px-2.5 py-[5px] rounded-md border border-border text-muted hover:text-danger transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-danger">
                                    {{ __('typing.ghost_clear') }}</button>
                            </div>
                        </template>
                    </div>
                </template>

                <!-- Row 3: Language switch (EN/ID) — memilih bahasa KONTEN yang diketik (bukan bahasa UI) -->
                <div class="inline-flex items-stretch gap-0.5 p-[3px] rounded-lg bg-surface border border-border"
                    role="group" aria-label="{{ __('typing.aria.pick_content_language') }}">
                    @foreach (['en' => 'EN', 'id' => 'ID'] as $code => $label)
                        <button type="button" aria-label="{{ $label }}"
                            :aria-pressed="'{{ $code }}' === @js($contentLang)"
                            @click.prevent="$wire.setContentLang('{{ $code }}'); $el.blur()"
                            class="px-[16px] py-[5px] rounded-md text-small font-mono font-bold transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand {{ $contentLang === $code ? 'bg-brand text-foreground' : 'text-muted hover:text-foreground' }}">{{ $label }}</button>
                    @endforeach
                </div>
            </div>
            @endif

            <div x-cloak class="group mb-6 transition-opacity duration-500"
                :class="!isStarted ? 'opacity-0' : (isFinished ? 'opacity-100' : 'opacity-60 hover:opacity-100')">
                <div class="flex items-start gap-4 sm:gap-8 lg:gap-10">
                    <div class="flex flex-col">
                        <span class="text-fluid-timer font-mono font-bold tabular-nums leading-none transition-colors duration-300"
                            :class="{
                                'text-danger': (currentMain === 'time' && timer < 5 && isStarted) || (currentMain === 'survival' && staminaPct < 25 && isStarted && !isFinished),
                                'text-gold': !((currentMain === 'time' && timer < 5 && isStarted) || (currentMain === 'survival' && staminaPct < 25 && isStarted && !isFinished)),
                                'motion-safe:animate-[stamina-critical_0.5s_ease-in-out_infinite]': currentMain === 'survival' && staminaPct < 25 && isStarted && !isFinished
                            }"
                            aria-live="polite" x-text="currentMain === 'time' ? timer : timer + 's'">0</span>
                        <span class="text-x-small uppercase tracking-wide text-muted mt-2"
                            x-text="currentMain === 'time' ? @js(__('typing.stat_left')) : @js(__('typing.stat_time'))">{{ __('typing.stat_time') }}</span>
                    </div>

                    <div class="flex flex-col">
                        <span class="text-xl sm:text-2xl text-muted font-mono font-bold tabular-nums leading-none" x-text="wpm">0</span>
                        <span class="text-x-small uppercase tracking-wide text-muted mt-1.5">{{ __('typing.stat_wpm') }}</span>
                    </div>

                    <div class="flex flex-col">
                        <span class="text-xl sm:text-2xl text-muted font-mono font-bold tabular-nums leading-none">
                            <span x-text="accuracy">0</span>%
                        </span>
                        <span class="text-x-small uppercase tracking-wide text-muted mt-1.5">{{ __('typing.stat_acc') }}</span>
                    </div>
                </div>

                <div class="h-[28px] mt-2">
                    <template x-if="currentMain !== 'survival'">
                        <svg x-show="wpmHistory.length > 1" x-cloak width="120" height="28"
                            viewBox="0 0 120 28" preserveAspectRatio="none" fill="none" aria-hidden="true">
                            <polyline :points="sparklinePoints" stroke="rgb(var(--color-brand))"
                                stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </template>

                    <template x-if="currentMain === 'survival'">
                        <div class="flex items-center gap-3">
                            <span class="font-display text-[0.55rem] uppercase tracking-[0.15em] text-muted shrink-0">{{ __('typing.stamina') }}</span>
                            <div class="relative flex-1 flex gap-[3px] p-[3px] bg-surface/80 border border-border/60"
                                :class="(staminaPct < 25 && isStarted && !isFinished) ? 'animate-[pulse_0.7s_ease-in-out_infinite]' : ''">
                                <template x-for="cell in staminaCells" :key="cell">
                                    <div class="h-[14px] flex-1 transition-colors duration-150"
                                        :style="`background-color: ${
                                            cell <= Math.ceil(staminaPct / 100 * staminaCells.length)
                                                ? (staminaPct > 50 ? 'rgb(var(--color-brand))' : (staminaPct > 25 ? 'rgb(var(--color-gold))' : 'rgb(var(--color-danger))'))
                                                : 'rgb(var(--color-border) / 0.35)'
                                        };`">
                                    </div>
                                </template>
                                <div class="absolute inset-0 pointer-events-none transition-opacity duration-150 bg-danger/70"
                                    :class="drainFlash ? 'opacity-100' : 'opacity-0'"></div>
                            </div>
                            <span class="font-display text-[0.55rem] uppercase tracking-[0.15em] text-muted shrink-0"
                                x-text="currentSub"></span>
                        </div>
                    </template>
                </div>
            </div>

            <div class="relative">
            <div x-cloak x-show="capsLockOn" x-transition.opacity
                class="absolute bottom-full left-0 right-0 flex justify-center mb-2 pointer-events-none"
                role="status" aria-live="polite">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gold/10 border border-gold/40">
                    <svg class="w-4 h-4 text-gold shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                    </svg>
                    <span class="text-small font-mono font-bold text-gold">{{ __('typing.caps_lock') }}</span>
                </div>
            </div>

            <!-- Kontainer 3 Baris -->
            <div class="relative overflow-hidden text-fluid-type tracking-tight select-none outline-none"
                style="max-height: 4.875em;">

                <!-- SINGLE SMOOTH CURSOR -->
                <div x-ref="caret" x-show="!isFinished"
                    class="absolute top-0 left-0 w-[0.1em] h-[1.2em] z-20 rounded [transform-origin:top_left] [will-change:transform] [transition:transform_var(--caret-dur,100ms)_var(--caret-ease,linear),background-color_150ms_ease-out]"
                    :style="{
                        backgroundColor: (currentMain === 'survival' && isStarted && !isFinished)
                            ? (staminaPct > 50 ? 'rgb(var(--color-brand-bright))' : (staminaPct > 25 ? 'rgb(var(--color-gold))' : 'rgb(var(--color-danger))'))
                            : 'rgb(var(--color-brand-bright))'
                    }"
                    :class="isTyping ? '' : 'animate-[caret-flash-smooth_1s_infinite]'">
                </div>

                <div x-ref="textContainer"
                    class="relative flex flex-wrap content-start gap-x-0 transition-transform duration-[85ms] ease-out"
                    :style="`transform: translateY(-${scrollOffset}px)`">

                    <!-- GHOST CURSOR: cursor kedua di jalur teks yang sama (z-10, di bawah cursor asli).
                         Tak mengontrol scroll. Sengaja tanpa transition pada transform — posisi
                         sudah dimuluskan per-frame oleh updateGhostPosition(); transisi hanya untuk opacity. -->
                    <div x-show="ghostActive && !isFinished" x-cloak
                        class="absolute top-0 left-0 w-[2.5px] h-[1.5em] transition-opacity duration-150 z-10 rounded opacity-40"
                        :style="`transform: translate(${ghostCursorLeft}px, ${ghostCursorTop}px); background-color: rgb(var(--color-muted));`">
                    </div>

                    @php
                        $words = explode(' ', $textToType);
                        $charPointer = 0;
                    @endphp

                    @foreach ($words as $word)
                        <div class="flex" wire:key="word-{{ $loop->index }}-{{ $textToType }}">
                            @foreach (str_split($word) as $char)
                                <span id="char-{{ $charPointer }}" class="char-element relative inline-block"
                                    :class="{
                                        'text-foreground': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === true,
                                        'text-danger': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === false,
                                        'text-muted': {{ $charPointer }} >= currentIndex || ({{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === 'skipped'),
                                        'border-b-2 border-danger': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === 'skipped'
                                    }">
                                    {{ $char }}
                                </span>
                                @php $charPointer++; @endphp
                            @endforeach

                            <!-- EXTRA CHARACTERS -->
                            <template
                                x-if="extraChars[{{ $loop->index }}] && extraChars[{{ $loop->index }}].length > 0">
                                <template x-for="(extra, idx) in extraChars[{{ $loop->index }}]"
                                    :key="idx">
                                    <span :id="'extra-' + {{ $loop->index }} + '-' + idx"
                                        class="char-element relative inline-block text-danger tracking-tight opacity-90">
                                        <span x-text="extra"></span>
                                    </span>
                                </template>
                            </template>

                            @if (!$loop->last)
                                <span id="char-{{ $charPointer }}"
                                    class="char-element relative w-[0.5em] inline-block"
                                    :class="inputResults[{{ $charPointer }}] === false ? 'bg-danger/30' : ''">
                                    &nbsp;
                                </span>
                                @php $charPointer++; @endphp
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            </div>

            <div class="mt-8 flex justify-center">
                {{-- War-lock: restart dinonaktifkan (satu klaim = satu kesempatan). Gerbangnya di server. --}}
                @if ($warLock)
                    <div class="flex flex-col items-center gap-1.5 select-none">
                        <div class="flex items-center gap-2 text-muted/40 px-4 py-2 rounded-xl cursor-not-allowed"
                            title="{{ __('typing.war_locked_restart') }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            <span class="font-mono text-xs uppercase tracking-widest">{{ __('typing.war_locked_restart') }}</span>
                        </div>
                    </div>
                @else
                    <button id="restartButton" @click.prevent="$wire.restart(); $el.blur()"
                        class="flex items-center gap-2 text-muted hover:text-foreground focus-visible:text-foreground focus-visible:ring-1 focus-visible:ring-border focus:bg-surface/60 transition-all transform hover:scale-105 outline-none px-4 py-2 rounded-xl hover:bg-surface/60">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                            stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                        <span class="font-mono text-xs uppercase tracking-widest">{{ __('typing.restart') }}</span>
                        <kbd class="font-mono text-[0.6rem] px-1.5 py-0.5 rounded bg-surface border border-white/10">tab</kbd>
                        <span class="font-mono text-[0.6rem] text-muted">{{ __('typing.then') }}</span>
                        <kbd class="font-mono text-[0.6rem] px-1.5 py-0.5 rounded bg-surface border border-white/10">enter</kbd>
                    </button>
                @endif
            </div>
        </div>
    </div>

    <script>
        // Preset stamina Survival.
        //   sMax/sStart : kapasitas & stamina awal
        //   graceSec    : detik awal dengan drain dilembutkan
        //   dStart      : drain pasif per detik
        //   dAccel      : percepatan drain per detik²
        //   refill      : stamina per karakter benar
        //   penalty     : drain ekstra saat kata kotor di-commit (cap per-kata)
        const SURVIVAL_PRESETS = {
            easy:   { sMax: 120, sStart: 120, graceSec: 4, dStart: 3.0, dAccel: 0.11, refill: 2.4, penalty: 7 },
            medium: { sMax: 100, sStart: 100, graceSec: 3, dStart: 3.8, dAccel: 0.20, refill: 1.9, penalty: 10 },
            hard:   { sMax: 85,  sStart: 70,  graceSec: 0, dStart: 5.5, dAccel: 0.40, refill: 1.3, penalty: 16 },
        };

        function survivalConfig(difficulty) {
            return SURVIVAL_PRESETS[difficulty] || SURVIVAL_PRESETS.medium;
        }

        function typingGame(initialText) {
            return {
                targetArray: initialText.split(''),
                currentIndex: 0,
                inputResults: [],
                startTime: null,
                timer: 0,
                wpm: 0,
                rawWpm: 0,
                accuracy: 0,
                isStarted: false,
                isFinished: false,
                timerInterval: null,
                scrollOffset: 0,
                lineHeight: 0,
                containerTop: null,
                caretHeight: 0,
                positionFrame: null,
                caretInstant: true,
                caretDrawn: false,
                _caretDurFrame: null,
                currentWordIndex: 0,
                wordBounds: [],
                extraChars: {},
                cursorLeft: 0,
                cursorTop: 0,

                // --- Ghost Mode: cursor kedua ber-pacing linear (WPM konstan), diisi lewat event 'ghost-selected'. ---
                ghostActive: false,
                ghostWpm: 0,
                ghostLabel: '',
                ghostCharIndex: 0,
                ghostCursorLeft: 0,
                ghostCursorTop: 0,
                ghostFinished: false,
                ghostFinishTime: null,
                _ghostRafId: null,

                capsLockOn: false,

                isTyping: false,
                typingTimeout: null,
                totalKeystrokes: 0,
                correctKeystrokes: 0,
                wpmHistory: [],
                rawHistory: [],
                missedChars: {},
                modeChangedCleanup: null,

                // --- Survival: stamina menyusut per detik, terisi per karakter benar, habis = game over. ---
                stamina: 100,         // nilai stamina sekarang
                staminaMax: 100,      // kapasitas/cap bar (di-set dari preset difficulty)
                staminaPct: 100,      // persentase untuk UI (0–100)
                survivalCfg: null,    // preset parameter aktif (lihat SURVIVAL_PRESETS)
                staminaInterval: null,// loop tick drain (halus, ~100ms)
                lastTickTime: 0,      // timestamp tick terakhir (untuk Δt presisi)
                currentWordDirty: false, // apakah kata yang sedang diketik sudah pernah error
                committedWordResults: {}, // {wordIndex: 'clean'|'dirty'} — kata yang sudah dinilai (idempoten)

                staminaCells: Array.from({ length: 16 }, (_, i) => i + 1),
                drainFlash: false,
                drainFlashTimeout: null,
                drainEventCount: 0,

                triggerDrainFlash() {
                    this.drainFlash = true;
                    clearTimeout(this.drainFlashTimeout);
                    this.drainFlashTimeout = setTimeout(() => { this.drainFlash = false; }, 250);
                },

                syncCapsLock(e) {
                    if (typeof e.getModifierState === 'function') {
                        this.capsLockOn = e.getModifierState('CapsLock');
                    }
                },

                init() {
                    this.resetProgress();
                    this.restoreGhostSelection();

                    // Cleanup disimpan agar listener tak menumpuk saat Alpine remount.
                    const cleanup = this.$wire.on('mode-changed', (payload) => {
                        this.resetForNewText(payload.text ?? '');
                    });
                    this.modeChangedCleanup = typeof cleanup === 'function' ? cleanup : null;
                },

                // Ghost hanya sah di time/words. Kalau state global tersisa dari mode
                // sebelumnya sementara mode sekarang survival, buang -- jangan
                // dihidupkan lagi saat Alpine remount.
                ghostEligible() {
                    return ['time', 'words'].includes(this.currentMain);
                },

                restoreGhostSelection() {
                    if (!this.ghostEligible()) {
                        window.__uetypeGhostSelection = null;
                        this.ghostActive = false;
                        this.ghostWpm = 0;
                        this.ghostLabel = '';

                        return;
                    }

                    const selection = window.__uetypeGhostSelection;
                    if (!selection || !selection.active) return;

                    this.ghostActive = true;
                    this.ghostWpm = selection.wpm;
                    this.ghostLabel = selection.label;
                    this.ghostCharIndex = 0;
                    this.ghostFinished = false;
                    this.ghostFinishTime = null;

                    this.$nextTick(() => {
                        const pos = this.getCharPosition(0);
                        if (pos) {
                            this.ghostCursorLeft = pos.left;
                            this.ghostCursorTop = pos.top;
                        }
                    });
                },

                // Reset penuh untuk teks BARU yang dikirim lewat event Livewire.
                resetForNewText(newText) {
                    this.targetArray = newText.split('');
                    this.resetProgress();
                },

                // Reset state (wordBounds, survival, dsb) dari targetArray saat ini.
                resetProgress() {
                    this.stopRuntime();

                    this.currentIndex = 0;
                    this.inputResults = [];
                    this.startTime = null;
                    this.isStarted = false;
                    this.isFinished = false;

                    // Reset (mis. restart / ganti mode di tengah sesi) -> overlay chat tampil lagi.
                    window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } }));
                    this.timer = (this.currentMain === 'time') ? parseInt(this.currentSub) : 0;
                    this.wpm = 0;
                    this.rawWpm = 0;
                    this.accuracy = 0;
                    this.cursorLeft = 0;
                    this.cursorTop = 0;
                    this.scrollOffset = 0;
                    this.lineHeight = 0;
                    this.containerTop = null;
                    this.caretHeight = 0;
                    this.isTyping = false;

                    // Preset survival diambil dari currentSub (easy|medium|hard).
                    this.survivalCfg = survivalConfig(this.currentSub);
                    this.staminaMax = this.survivalCfg.sMax;
                    this.stamina = this.survivalCfg.sStart;
                    this.staminaPct = Math.round((this.stamina / this.staminaMax) * 100);
                    this.lastTickTime = 0;
                    this.currentWordDirty = false;
                    this.committedWordResults = {};
                    this.drainEventCount = 0;
                    this.drainFlash = false;

                    this.wordBounds = [];
                    this.extraChars = {};
                    this.currentWordIndex = 0;
                    this.totalKeystrokes = 0;
                    this.correctKeystrokes = 0;
                    this.wpmHistory = [];
                    this.rawHistory = [];
                    this.missedChars = {};
                    this.ghostCharIndex = 0;
                    this.ghostFinished = false;
                    this.ghostFinishTime = null;
                    this.ghostCursorLeft = 0;
                    this.ghostCursorTop = 0;

                    let start = 0;
                    let wordIdx = 0;
                    for (let i = 0; i < this.targetArray.length; i++) {
                        if (this.targetArray[i] === ' ') {
                            this.wordBounds[wordIdx] = {
                                start: start,
                                end: i - 1,
                                space: i
                            };
                            start = i + 1;
                            wordIdx++;
                        }
                    }
                    this.wordBounds[wordIdx] = {
                        start: start,
                        end: this.targetArray.length - 1,
                        space: null
                    };

                    this.caretInstant = true;
                    this.caretDrawn = false;
                    this.$nextTick(() => {
                        this.updatePosition();
                        requestAnimationFrame(() => { this.caretInstant = false; });
                    });
                },

                wordHasError(wordIndex) {
                    const bounds = this.wordBounds[wordIndex];
                    if (!bounds) return false;
                    for (let i = bounds.start; i <= bounds.end; i++) {
                        if (this.inputResults[i] === false || this.inputResults[i] === 'skipped' || this.inputResults[i] === undefined) {
                            return true;
                        }
                    }
                    if (this.extraChars[wordIndex] && this.extraChars[wordIndex].length > 0) {
                        return true;
                    }
                    return false;
                },

                // --- Helper Survival ---

                // Tandai kata aktif "kotor" saat error. Nyawa dipotong nanti saat commit, bukan di sini.
                markWordDirty() {
                    if (this.currentMain !== 'survival' || this.isFinished) return;
                    this.currentWordDirty = true;
                },

                // Nilai satu kata yang selesai. Kata kotor kena penalti stamina sekali saja
                // (cap per-kata, idempoten kalau di-commit ulang); kata bersih tak kena apa-apa.
                completeWord(wordIndex) {
                    if (this.currentMain !== 'survival') return;

                    const isDirty = this.currentWordDirty;
                    this.currentWordDirty = false;

                    const alreadyPenalized = this.committedWordResults[wordIndex] === 'dirty';

                    if (isDirty) {
                        this.committedWordResults[wordIndex] = 'dirty';
                        if (!alreadyPenalized) {
                            this.stamina = Math.max(0, this.stamina - this.survivalCfg.penalty);
                            this.syncStaminaPct();
                            this.triggerDrainFlash();
                            this.drainEventCount++;
                            if (this.stamina <= 0) this.survivalGameOver();
                        }
                        return;
                    }

                    this.committedWordResults[wordIndex] = 'clean';
                },

                // Backspace mundur ke kata sebelumnya: batalkan penilaian commit terakhir.
                // Status 'dirty' dipertahankan agar penalti tak dikenakan dua kali saat re-commit.
                uncommitWord(wordIndex) {
                    if (this.currentMain !== 'survival') return;

                    const prev = this.committedWordResults[wordIndex];
                    if (prev === undefined) return;

                    if (prev === 'clean') {
                        delete this.committedWordResults[wordIndex];
                    }
                },

                // Refill stamina tiap satu karakter benar (cap di staminaMax).
                refillStamina() {
                    if (this.currentMain !== 'survival' || this.isFinished) return;
                    this.stamina = Math.min(this.staminaMax, this.stamina + this.survivalCfg.refill);
                    this.syncStaminaPct();
                },

                // Satu tick drain pasif; Δt presisi dari tick sebelumnya, drain naik seiring waktu.
                staminaTick() {
                    if (this.currentMain !== 'survival' || this.isFinished || !this.startTime) return;

                    const now = Date.now();
                    const dt = this.lastTickTime ? (now - this.lastTickTime) / 1000 : 0;
                    this.lastTickTime = now;
                    if (dt <= 0) return;

                    const elapsed = (now - this.startTime) / 1000;
                    const cfg = this.survivalCfg;

                    const graceFactor = elapsed < cfg.graceSec ? (elapsed / cfg.graceSec) : 1;

                    // D = D_start + D_accel * elapsed
                    const drainPerSec = (cfg.dStart + cfg.dAccel * elapsed) * graceFactor;

                    this.stamina = Math.max(0, this.stamina - drainPerSec * dt);
                    this.syncStaminaPct();

                    if (this.stamina <= 0) this.survivalGameOver();
                },

                // Sinkronkan persentase bar untuk UI (0–100).
                syncStaminaPct() {
                    const nextPct = Math.max(0, Math.min(100, Math.round((this.stamina / this.staminaMax) * 100)));
                    if (nextPct !== this.staminaPct) {
                        this.staminaPct = nextPct;
                    }
                },

                // Stamina habis: hentikan loop tick, selesaikan sesi lewat finish() seperti mode lain.
                survivalGameOver() {
                    if (this.isFinished) return;
                    if (this.staminaInterval) {
                        clearInterval(this.staminaInterval);
                        this.staminaInterval = null;
                    }
                    this.finish();
                },

                destroy() {
                    if (this.modeChangedCleanup) {
                        this.modeChangedCleanup();
                        this.modeChangedCleanup = null;
                    }
                    this.stopRuntime();
                },

                stopRuntime() {
                    if (this.timerInterval) {
                        clearInterval(this.timerInterval);
                        this.timerInterval = null;
                    }
                    if (this.staminaInterval) {
                        clearInterval(this.staminaInterval);
                        this.staminaInterval = null;
                    }
                    clearTimeout(this.drainFlashTimeout);
                    this.drainFlashTimeout = null;
                    clearTimeout(this.typingTimeout);
                    this.typingTimeout = null;
                    this.stopGhostAnimationLoop();
                    if (this.positionFrame) {
                        cancelAnimationFrame(this.positionFrame);
                        this.positionFrame = null;
                    }
                    if (this._caretDurFrame) {
                        cancelAnimationFrame(this._caretDurFrame);
                        this._caretDurFrame = null;
                    }
                },

                // Lookup posisi DOM char-{index}; fallback ke karakter terakhir bila index melewati teks.
                getCharPosition(index) {
                    let activeEl = document.getElementById('char-' + index);
                    let isEnd = false;

                    if (!activeEl) {
                        activeEl = document.getElementById('char-' + (index - 1));
                        isEnd = true;
                    }

                    if (!activeEl) return null;

                    return {
                        left: isEnd ? activeEl.offsetLeft + activeEl.offsetWidth : activeEl.offsetLeft,
                        top: activeEl.offsetTop,
                    };
                },

                // Posisi ghost dari progres waktu (pacing linear); dipanggil per-frame lewat rAF.
                // ghostCharIndex (integer) dipakai untuk menang/kalah di finish(); posisi visual
                // dihitung dari nilai pecahan agar cursor bergerak halus melintasi tiap karakter.
                updateGhostPosition() {
                    if (!this.startTime || this.ghostFinished) return;

                    const minutesElapsed = (Date.now() - this.startTime) / 60000;
                    const fractionalChars = Math.max(0, Math.min(
                        this.ghostWpm * 5 * minutesElapsed,
                        this.targetArray.length
                    ));

                    const flooredIndex = Math.floor(fractionalChars);
                    this.ghostCharIndex = Math.min(flooredIndex, this.targetArray.length);

                    if (fractionalChars >= this.targetArray.length) {
                        if (!this.ghostFinished) {
                            this.ghostFinished = true;
                            this.ghostFinishTime = Date.now() - this.startTime;
                        }
                        const pos = this.getCharPosition(this.targetArray.length);
                        if (pos) {
                            this.ghostCursorLeft = pos.left;
                            this.ghostCursorTop = pos.top;
                        }
                        return;
                    }

                    // Interpolasi pixel antar karakter; hanya bila keduanya sebaris (kalau wrap, lompat langsung).
                    const fraction = fractionalChars - flooredIndex;
                    const currentPos = this.getCharPosition(flooredIndex);
                    if (!currentPos) return;

                    const nextPos = this.getCharPosition(flooredIndex + 1);

                    if (nextPos && nextPos.top === currentPos.top) {
                        this.ghostCursorLeft = currentPos.left + (nextPos.left - currentPos.left) * fraction;
                        this.ghostCursorTop = currentPos.top;
                    } else {
                        this.ghostCursorLeft = currentPos.left;
                        this.ghostCursorTop = currentPos.top;
                    }
                },

                // Loop animasi ghost via rAF (~60fps), terpisah dari timerInterval 1 detik.
                startGhostAnimationLoop() {
                    if (this._ghostRafId) return; // sudah berjalan

                    const tick = () => {
                        if (!this.ghostActive || this.isFinished) {
                            this._ghostRafId = null;
                            return;
                        }
                        this.updateGhostPosition();
                        this._ghostRafId = requestAnimationFrame(tick);
                    };

                    this._ghostRafId = requestAnimationFrame(tick);
                },

                stopGhostAnimationLoop() {
                    if (this._ghostRafId) {
                        cancelAnimationFrame(this._ghostRafId);
                        this._ghostRafId = null;
                    }
                },

                updatePosition() {
                    if (!this.$refs.textContainer) return;

                    let activeEl;
                    let isEnd = false;

                    // Cek overtyping di spasi
                    if (this.extraChars[this.currentWordIndex] && this.extraChars[this.currentWordIndex].length > 0 && this
                        .currentIndex === this.wordBounds[this.currentWordIndex].space) {
                        let lastIdx = this.extraChars[this.currentWordIndex].length - 1;
                        activeEl = document.getElementById('extra-' + this.currentWordIndex + '-' + lastIdx);
                        isEnd = true; // Taruh kursor di KANAN karakter ekstra
                    } else {
                        activeEl = document.getElementById('char-' + this.currentIndex);
                        if (!activeEl) {
                            activeEl = document.getElementById('char-' + (this.currentIndex - 1));
                            isEnd = true; // Taruh kursor di KANAN karakter terakhir
                        }
                    }

                    if (!activeEl) return;

                    if (this.containerTop === null || !this.lineHeight || !this.caretHeight) {
                        const firstChar = document.getElementById('char-0');
                        if (firstChar) {
                            if (this.containerTop === null) this.containerTop = firstChar.offsetTop;
                            if (!this.lineHeight) this.lineHeight = firstChar.offsetHeight;
                        }
                        if (!this.caretHeight) {
                            this.caretHeight = this.$refs.caret?.offsetHeight || activeEl.offsetHeight;
                        }
                    }

                    const left = activeEl.offsetLeft;
                    const top = activeEl.offsetTop;
                    const width = activeEl.offsetWidth;
                    const height = activeEl.offsetHeight;

                    const caretHeight = this.caretHeight || height;
                    const targetTop = top + ((height - caretHeight) / 2);

                    this.cursorLeft = isEnd ? left + width : left;
                    this.cursorTop = targetTop;

                    const currentTop = top - (this.containerTop || 0);
                    const lh = this.lineHeight || 48;
                    this.scrollOffset = currentTop >= lh * 2 ? currentTop - lh : 0;

                    this.moveCaret(this.cursorLeft, this.cursorTop - this.scrollOffset, this.caretInstant);
                },

                moveCaret(targetLeft, targetTop, instant) {
                    const el = this.$refs.caret;
                    if (!el) return;

                    const target = `translate(${targetLeft}px, ${targetTop}px)`;

                    if (instant || !this.caretDrawn) {
                        if (this._caretDurFrame) cancelAnimationFrame(this._caretDurFrame);
                        el.style.setProperty('--caret-dur', '0ms');
                        el.style.transform = target;
                        this.caretDrawn = true;
                        this._caretDurFrame = requestAnimationFrame(() => {
                            this._caretDurFrame = null;
                            el.style.removeProperty('--caret-dur');
                        });
                        return;
                    }

                    el.style.transform = target;
                },

                schedulePositionUpdate() {
                    if (this.positionFrame) return;
                    this.positionFrame = requestAnimationFrame(() => {
                        this.positionFrame = null;
                        this.updatePosition();
                    });
                },

                deleteOneStep() {
                    if (this.currentIndex <= 0) return false;

                    const bounds = this.wordBounds[this.currentWordIndex];

                    if (this.extraChars[this.currentWordIndex] && this.extraChars[this.currentWordIndex].length > 0) {
                        this.extraChars[this.currentWordIndex].pop();
                        this.schedulePositionUpdate();
                        return true;
                    }

                    if (this.currentIndex === bounds.start) {
                        if (this.currentWordIndex > 0) {
                            let prevWordIdx = this.currentWordIndex - 1;
                            if (this.wordHasError(prevWordIdx)) {
                                this.currentWordIndex--;

                                this.uncommitWord(this.currentWordIndex);
                                this.markWordDirty();

                                let prevBounds = this.wordBounds[this.currentWordIndex];
                                let jumpIndex = prevBounds.space;

                                this.inputResults[jumpIndex] = null;

                                while (jumpIndex > prevBounds.start && this.inputResults[jumpIndex - 1] === 'skipped') {
                                    jumpIndex--;
                                    this.inputResults[jumpIndex] = null;
                                }

                                this.currentIndex = jumpIndex;
                                this.schedulePositionUpdate();
                                return true;
                            }
                        }
                        return false;
                    }

                    this.currentIndex--;
                    this.inputResults[this.currentIndex] = null;
                    this.schedulePositionUpdate();
                    return true;
                },

                handleInput(e) {
                    if (this.isFinished) return;
                    if ((e.ctrlKey || e.metaKey) && e.key !== 'Backspace') return;
                    if (e.key === ' ') e.preventDefault();
                    if (e.key.length > 1 && e.key !== 'Backspace') return;

                    // Kursor berhenti berkedip selama mengetik.
                    this.isTyping = true;
                    clearTimeout(this.typingTimeout);
                    this.typingTimeout = setTimeout(() => {
                        this.isTyping = false;
                    }, 500);

                    if (!this.isStarted) {
                        this.isStarted = true;
                        this.startTime = Date.now();

                        // Sembunyikan overlay chat selama sesi ketik berjalan.
                        window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: true } }));

                        // Survival: loop drain ~100ms agar tekanan terasa mulus (drain & game over di staminaTick).
                        if (this.currentMain === 'survival') {
                            this.lastTickTime = this.startTime;
                            this.staminaInterval = setInterval(() => this.staminaTick(), 100);
                        }

                        // Ghost: loop rAF terpisah dari timerInterval 1 detik di bawah.
                        if (this.ghostActive) {
                            this.startGhostAnimationLoop();
                        }

                        this.timerInterval = setInterval(() => {
                            const timeElapsed = Math.floor((Date.now() - this.startTime) / 1000);
                            if (this.currentMain === 'time') {
                                let remaining = parseInt(this.currentSub) - timeElapsed;
                                this.timer = remaining > 0 ? remaining : 0;
                                if (this.timer <= 0) this.finish();
                            } else {
                                this.timer = timeElapsed;
                            }
                            this.calculateStats();

                            if (timeElapsed > 0 && !this.isFinished) {
                                this.wpmHistory.push(this.wpm);
                                const timeElapsedMins = (Date.now() - this.startTime) / 60000;
                                const raw = Math.round((this.totalKeystrokes / 5) / timeElapsedMins) || 0;
                                this.rawHistory.push(raw);
                            }
                        }, 1000);
                    }

                    let bounds = this.wordBounds[this.currentWordIndex];

                    if (e.key === 'Backspace') {
                        if (e.ctrlKey || e.metaKey) {
                            const startWord = this.currentWordIndex;
                            let guard = 0;
                            while (this.currentIndex > 0 && guard++ < 500) {
                                const atWordStart = this.currentIndex === this.wordBounds[this.currentWordIndex].start;
                                const moved = this.deleteOneStep();
                                if (!moved) break;
                                if (this.currentWordIndex !== startWord) break;
                                if (atWordStart) break;
                            }
                            this.schedulePositionUpdate();
                            return;
                        }

                        this.deleteOneStep();
                        return;
                    }

                    // Sejak titik ini: user menekan karakter/spasi, bukan backspace.
                    this.totalKeystrokes++;

                    // Kursor di posisi spasi pembatas antar kata.
                    if (this.currentIndex === bounds.space) {
                        if (e.key !== ' ') {
                            // Overtyping: karakter berlebih ditampung, kata ditandai kotor.
                            if (!this.extraChars[this.currentWordIndex]) this.extraChars[this.currentWordIndex] = [];
                            if (this.extraChars[this.currentWordIndex].length < 15) {
                                this.extraChars[this.currentWordIndex].push(e.key);
                            }
                            this.markWordDirty();
                            this.calculateStats();
                            this.schedulePositionUpdate();
                            return;
                        } else {
                            // Spasi ditekan: pindah ke kata berikutnya & nilai kata yang baru selesai.
                            this.correctKeystrokes++;
                            this.refillStamina();
                            this.inputResults[this.currentIndex] = true;
                            this.currentIndex++;
                            this.currentWordIndex++;
                            this.completeWord(this.currentWordIndex - 1);
                            if (this.currentIndex === this.targetArray.length) this.finish();
                            this.calculateStats();
                            this.schedulePositionUpdate();
                            return;
                        }
                    }

                    // Spasi di tengah kata: sisa huruf ditandai terlewat (kata di-skip).
                    if (e.key === ' ') {
                        // Abaikan spasi kalau kata ini belum diketik sama sekali (cegah spam spasi).
                        if (this.currentIndex === bounds.start) {
                            return;
                        }

                        for (let i = this.currentIndex; i <= bounds.end; i++) {
                            this.inputResults[i] = 'skipped';

                            const expectedChar = this.targetArray[i].toLowerCase();
                            if (expectedChar !== ' ' && expectedChar.length === 1) {
                                this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                            }
                        }
                        this.markWordDirty();
                        if (bounds.space !== null) {
                            this.inputResults[bounds.space] = 'skipped'; // spasi di-skip tak dihitung benar
                            this.currentIndex = bounds.space + 1;
                            this.currentWordIndex++;
                            this.completeWord(this.currentWordIndex - 1);
                        } else {
                            this.currentIndex = this.targetArray.length;
                            this.finish();
                        }
                        this.calculateStats();
                        this.schedulePositionUpdate();
                        return;
                    }

                    // Pengetikan normal.
                    const isCorrect = (e.key === this.targetArray[this.currentIndex]);
                    if (isCorrect) {
                        this.correctKeystrokes++;
                        this.refillStamina();
                    } else {
                        const expectedChar = this.targetArray[this.currentIndex].toLowerCase();
                        if (expectedChar !== ' ' && expectedChar.length === 1) {
                            this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                        }
                        this.markWordDirty();
                    }

                    this.inputResults[this.currentIndex] = isCorrect;
                    this.currentIndex++;

                    if (this.currentIndex === this.targetArray.length) this.finish();
                    this.calculateStats();
                    this.schedulePositionUpdate();
                },

                calculateStats() {
                    if (!this.startTime) return;

                    const elapsedMs = Date.now() - this.startTime;

                    // Lantai 1 detik: cegah WPM meledak di awal ketikan.
                    const effectiveMs = (elapsedMs < 1000 && !this.isFinished) ? 1000 : elapsedMs;
                    const timeElapsed = effectiveMs / 60000;

                    if (timeElapsed <= 0) return;

                    // Net WPM dari correctKeystrokes — sumber yang sama dengan finish/server,
                    // sehingga angka live identik dengan angka di halaman hasil.
                    this.wpm = Math.round((this.correctKeystrokes / 5) / timeElapsed) || 0;

                    // Raw WPM: mengabaikan error (total tuts / 5).
                    this.rawWpm = Math.round((this.totalKeystrokes / 5) / timeElapsed) || 0;

                    // Akurasi berbasis tuts fisik (gaya Monkeytype).
                    if (this.totalKeystrokes > 0) {
                        this.accuracy = Math.round((this.correctKeystrokes / this.totalKeystrokes) * 100);
                    } else {
                        this.accuracy = 0;
                    }
                },

                // wpmHistory[] -> string `points` untuk <polyline> sparkline, auto-scale ke min/max.
                get sparklinePoints() {
                    const h = this.wpmHistory;
                    if (h.length < 2) return '';
                    const w = 120, ht = 28, pad = 2;
                    const max = Math.max(...h), min = Math.min(...h);
                    const range = max - min || 1;
                    const stepX = w / (h.length - 1);
                    return h.map((v, i) => {
                        const x = i * stepX;
                        const y = ht - pad - ((v - min) / range) * (ht - pad * 2);
                        return `${x.toFixed(1)},${y.toFixed(1)}`;
                    }).join(' ');
                },

                finish() {
                    this.isFinished = true;
                    // Sesi ketik selesai — tampilkan lagi overlay chat.
                    window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } }));
                    clearInterval(this.timerInterval);
                    if (this.staminaInterval) {
                        clearInterval(this.staminaInterval);
                        this.staminaInterval = null;
                    }

                    // Durasi presisi (ms) sejak keystroke pertama — sumber yang sama dengan
                    // perhitungan live, agar WPM final identik dengan WPM saat mengetik.
                    const durationMs = this.startTime ? (Date.now() - this.startTime) : 0;

                    const correct = this.correctKeystrokes;
                    const total = this.totalKeystrokes;

                    // Hitung posisi ghost tepat pada momen finish (bukan snapshot rAF terakhir).
                    if (this.ghostActive && !this.ghostFinished) {
                        this.updateGhostPosition();
                    }
                    this.stopGhostAnimationLoop();
                    const ghostWpmArg = this.ghostActive ? this.ghostWpm : null;
                    const ghostLabelArg = this.ghostActive ? this.ghostLabel : null;
                    const ghostCharsArg = this.ghostActive ? this.ghostCharIndex : null;

                    this.$wire.saveResult(durationMs, total, correct, this.wpmHistory, this.rawHistory, this.missedChars, this.drainEventCount, ghostWpmArg, ghostLabelArg, ghostCharsArg);
                }
            }
        }
    </script>
