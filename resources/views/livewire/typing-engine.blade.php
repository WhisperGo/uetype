<div
    class="flex flex-col flex-1 min-h-full text-muted font-mono selection:bg-brand selection:text-foreground outline-none">
    {{-- wire:key is STABLE across a restart (no $typingSessionKey): restart() only reroLLs the
         text, and that is applied in-place via the 'mode-changed' event -> resetForNewText(),
         NOT by tearing down and rebuilding this whole subtree. Bumping the key on every restart
         forced a full Alpine remount (destroy/init, caret drawWhenReady retry, replacing ~600
         spans) -- the bulk of the restart delay, worst on long texts. The key still changes on a
         real MODE switch ({mainMode}-{subMode}) where a clean remount is wanted. See
         docs/review-performance-2026-07-27.md (restart delay, Tier 1). --}}
    <div wire:key="typing-app-{{ $mainMode }}-{{ $subMode }}" class="flex flex-col flex-1 justify-center min-h-0" x-data="{
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
                // Don't capture typing while another field is focused (e.g. the chat overlay
                // input) — let it go there instead, not leak into the typing area behind it.
                handleInput($event);
            }
        "
        @keyup.window="syncCapsLock($event)"
        x-on:ghost-selected.window="if (ghostEligible()) { window.__uetypeGhostSelection = { active: true, wpm: $event.detail.wpm, label: $event.detail.label }; ghostActive = true; ghostWpm = $event.detail.wpm; ghostLabel = $event.detail.label; ghostCharIndex = 0; ghostFinished = false; ghostFinishTime = null; $nextTick(() => { const pos = getCharPosition(0); if (pos) { ghostCursorLeft = pos.left; ghostCursorTop = pos.top; } if (isStarted) startGhostAnimationLoop(); }) }"
        x-on:ghost-cleared.window="window.__uetypeGhostSelection = null; ghostActive = false; ghostWpm = 0; ghostLabel = ''; stopGhostAnimationLoop();">

        {{-- GhostPicker: komponen Livewire terpisah; wire:key men-scope ulang daftarnya per mode.
             Hanya di-mount untuk user login -- ghost dikunci untuk guest (leaderboard ditutup). --}}
        @auth
            <livewire:ghost-picker :main-mode="$mainMode" :sub-mode="$subMode"
                wire:key="ghost-picker-{{ $mainMode }}-{{ $subMode }}" />
        @endauth

        {{-- Survival "danger vignette". Gated with x-show so it is display:none (out of the
             paint tree entirely) in time/words mode -- a fullscreen radial-gradient is an
             expensive paint, especially under software rendering, and there is no reason to keep
             it composited when not in survival. Within survival its opacity is still driven
             reactively below. See docs/review-performance-2026-07-27.md (Temuan 8). --}}
        <div x-cloak aria-hidden="true" x-show="currentMain === 'survival'"
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
                {{-- WAR-LOCK: the mode is locked by a Clan War claim; mode controls are hidden (the server also rejects setMode). --}}
                <div class="flex flex-col items-center gap-2 mb-2 transition-opacity duration-500"
                    :class="isStarted ? 'opacity-0 pointer-events-none' : 'opacity-100'">
                    <div class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-gold/10 border border-gold/40">
                        <svg class="w-4 h-4 text-gold shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <span class="text-small font-mono font-bold text-gold">
                            {{ __('typing.war_lock_label') }} ·
                            @if ($warLock['mode'] === 'survival')
                                Survival {{ ucfirst($warLock['config']) }}
                            @elseif ($warLock['mode'] === 'time')
                                Time {{ $warLock['config'] }}s
                            @else
                                Words {{ $warLock['config'] }}
                            @endif
                        </span>
                    </div>
                    {{-- WITHOUT wire:navigate: the only SPA exit from /typing while war-locked;
                         Back from it would restore a broken typing-engine snapshot. A full load is safe. --}}
                    <a href="{{ route('clan-war.index') }}" class="text-x-small font-mono text-muted hover:text-foreground transition">
                        ← {{ __('typing.war_lock_cancel') }}
                    </a>
                </div>
            @else
            <!-- MODE CONTROL BAR: Standard/Survival/Ghost → config → content language.
                 While typing it only fades (space stays reserved) so there's no layout shift.
                 "Standard" is just a visual group; the backend mainMode is still time/words. -->
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

                    @auth
                        <button type="button" aria-label="{{ __('typing.aria.mode_survival') }}"
                            :aria-pressed="currentMain === 'survival'"
                            @click.prevent="currentMain='survival'; currentSub='medium'; $wire.setMode('survival','medium'); $el.blur()"
                            class="px-3 sm:px-[18px] py-[7px] rounded-md text-small font-mono font-bold transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand"
                            :class="currentMain === 'survival' ? 'bg-brand text-foreground' : 'text-muted hover:text-foreground'">{{ __('typing.survival') }}</button>
                    @endauth
                    @guest
                        {{-- Guest: Survival is locked. Still shown (so they know the mode exists) but points to login.
                             Deliberately WITHOUT wire:navigate: SPA nav + @entangle('mainMode') leaves currentMain
                             undefined on Back (the config/ghost x-if rows collapse). A full load is safe. --}}
                        <a href="{{ route('login') }}"
                            aria-label="{{ __('typing.aria.mode_survival') }}" title="{{ __('typing.survival_login') }}"
                            class="inline-flex items-center gap-1.5 px-3 sm:px-[18px] py-[7px] rounded-md text-small font-mono font-bold text-muted hover:text-foreground transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand">
                            <svg class="w-3.5 h-3.5 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                            {{ __('typing.survival') }}</a>
                    @endguest
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

                            {{-- Divider between the type toggle and its durations. Hidden below
                                 `sm`: on a phone the row wraps, leaving this bar dangling at the
                                 end of the first line (annoying). Desktop keeps it -- there the
                                 row is one line and the divider earns its place. --}}
                            <span class="hidden sm:block w-px h-4 bg-border mx-1" aria-hidden="true"></span>

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

                <!-- Ghost row: an extra opponent only for Standard (time/words); gone in Survival. -->
                <template x-if="['time','words'].includes(currentMain)">
                    <div class="flex items-center justify-center gap-2 text-small font-mono" role="group"
                        aria-label="{{ __('typing.aria.mode_ghost') }}">
                        @guest
                            {{-- Guest: ghost is locked. The CTA points to login, not the picker.
                                 WITHOUT wire:navigate (see the Survival button note: avoid currentMain undefined on Back). --}}
                            <a href="{{ route('login') }}"
                                class="px-3 py-[6px] rounded-md border border-border text-muted hover:text-foreground transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand">
                                {{ __('typing.ghost_login') }}</a>
                        @endguest
                        @auth
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
                                {{-- Explicit clear: wire:click to the server REMOVES the selection from
                                     the session (so it won't reappear on the next test); the Alpine dispatch
                                     hides the cursor immediately without waiting for a round-trip. --}}
                                <button type="button"
                                    wire:click="clearGhost"
                                    @click.prevent="$dispatch('ghost-cleared'); $el.blur()"
                                    class="px-2.5 py-[5px] rounded-md border border-border text-muted hover:text-danger transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-danger">
                                    {{ __('typing.ghost_clear') }}</button>
                            </div>
                        </template>
                        @endauth
                    </div>
                </template>

                <!-- Row 3: Language switch (EN/ID) — picks the CONTENT language being typed (not the UI language) -->
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

            {{-- Live stats dim to 60% while typing so they do not pull the eye off the words,
                 and come back on hover. `hover:` is the whole recovery path, so on a touch
                 screen -- where there is no hover -- they would simply stay dim forever. The
                 `pointer: coarse` branch keeps them at full opacity instead: on a phone the
                 stats are already smaller and further from the text, so the dimming solves a
                 problem that does not exist there.

                 This has to land WITH `hoverOnlyWhenSupported` (see tailwind.config.js), not
                 after it: that flag stops `hover:` from applying on touch at all, which would
                 turn a temporary dimming into a permanent one. --}}
            <div x-cloak class="group mb-6 transition-opacity duration-500"
                :class="!isStarted ? 'opacity-0' : (isFinished ? 'opacity-100' : 'opacity-60 hover:opacity-100 [@media(pointer:coarse)]:opacity-100')">
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

                {{-- Bar stamina, HANYA untuk mode survival -- tidak ada elemen pengganti di
                     mode lain, sehingga statistik menempel dekat ke area ketik. Ruang ini dulu
                     dicadangkan setinggi 28px untuk semua mode karena sparkline WPM live
                     mengisinya; setelah sparkline dihapus yang tersisa hanya celah kosong.

                     Berpindah mode kini menggeser area ketik setinggi blok ini. Itu disengaja:
                     yang harus bebas-geser adalah alur MULAI mengetik (lihat pemilih mode yang
                     mencadangkan ruangnya sendiri), bukan perpindahan mode -- aksi sadar user,
                     dan survival memang punya elemen yang mode lain tak punya. --}}
                <template x-if="currentMain === 'survival'">
                    <div class="h-[28px] mt-2 flex items-center gap-3">
                        <span class="font-display text-[0.55rem] uppercase tracking-[0.15em] text-muted shrink-0">{{ __('typing.stamina') }}</span>
                        {{-- PERF (Temuan 6, docs/review-performance-2026-07-27.md): the bar used to be
                             16 cells rendered via x-for, each with its OWN reactive `:style` reading
                             staminaPct -> 16 style re-evaluations on every drain tick/keystroke. Now
                             it is ONE fill element: a single reactive `:style` sets its width and
                             colour, and a static repeating-linear-gradient overlay draws the segment
                             gaps so the 16-segment look is preserved. The width snaps to the same 16
                             discrete steps as before (Math.ceil over staminaCells.length), so a lit
                             segment reads identically -- one binding instead of sixteen. --}}
                        <div class="relative flex-1 h-[20px] p-[3px] bg-surface/80 border border-border/60"
                            :class="(staminaPct < 25 && isStarted && !isFinished) ? 'animate-[pulse_0.7s_ease-in-out_infinite]' : ''">
                            <div class="relative w-full h-full overflow-hidden">
                                {{-- empty track --}}
                                <div class="absolute inset-0" style="background-color: rgb(var(--color-border) / 0.35);"></div>
                                {{-- single fill: width snapped to 16 steps + threshold colour (the one reactive binding) --}}
                                <div class="absolute inset-y-0 left-0 transition-all duration-150"
                                    :style="`width: ${Math.ceil(staminaPct / 100 * staminaCells.length) / staminaCells.length * 100}%; background-color: ${staminaPct > 50 ? 'rgb(var(--color-brand))' : (staminaPct > 25 ? 'rgb(var(--color-gold))' : 'rgb(var(--color-danger))')};`"></div>
                                {{-- 16-segment gaps drawn over the fill in the track's surface colour --}}
                                <div class="absolute inset-0 pointer-events-none"
                                    style="background: repeating-linear-gradient(to right, transparent 0 calc(6.25% - 3px), rgb(var(--color-surface) / 0.8) calc(6.25% - 3px) 6.25%);"></div>
                            </div>
                            <div class="absolute inset-0 pointer-events-none transition-opacity duration-150 bg-danger/70"
                                :class="drainFlash ? 'opacity-100' : 'opacity-0'"></div>
                        </div>
                        <span class="font-display text-[0.55rem] uppercase tracking-[0.15em] text-muted shrink-0"
                            x-text="currentSub"></span>
                    </div>
                </template>
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

            {{-- ===== SOFT-KEYBOARD INPUT (perangkat sentuh) =====

                 Mesin ketik membaca tuts fisik dari `@keydown.window` di atas dan sengaja tak
                 memegang elemen fokusable apa pun (typing-engine.md §3.7). Di HP itu berarti
                 tak ada yang bisa disentuh untuk memunculkan keyboard layar -- tesnya benar-benar
                 tak bisa dimainkan, bukan cuma sulit.

                 Input ini yang memunculkan keyboard, dan ia DIBENTANGKAN MENUTUPI area teks
                 supaya menjadi target sentuhnya sendiri. Itu keputusan yang menentukan: sentuhan
                 mendarat langsung di sebuah <input>, jadi browser membuka keyboard secara NATIVE
                 -- tak bergantung pada JavaScript sama sekali. Versi pertama memakai
                 `pointer-events-none` dan menyerahkan tap ke kontainer teks yang memanggil
                 focusTypingInput(); itu membuat keyboard bergantung pada satu panggilan JS, dan
                 diam total kalau bundle-nya gagal/basi. Sekaligus menghapus urusan "focus() harus
                 di dalam gestur" milik iOS, karena tak ada focus() terprogram yang terlibat.

                 TRANSPARAN, bukan `opacity-0`. Elemen ber-opacity 0 dianggap tak terlihat oleh
                 sebagian browser mobile, yang lalu menolak membuka keyboard untuknya. Dengan
                 `text-transparent` + `bg-transparent` + `caret-transparent` elemennya benar-benar
                 terrender dan sah difokus, tapi tak menampilkan apa pun. (Nilainya selalu
                 dikuras, jadi tak ada teks yang bisa muncul di atas paragraf.) Yang jelas TIDAK
                 boleh: `hidden`/`display:none` -- itu tak bisa difokus sama sekali.

                 `text-base` (16px) menahan iOS Safari MEMPERBESAR halaman saat input difokus;
                 zoom itu akan menggeser seluruh area teks beserta caretnya.

                 Empat atribut koreksi teks dimatikan: autocorrect/kapitalisasi/ejaan akan
                 menyunting apa yang diketik pemain, dan di tes mengetik itu berarti mengubah
                 hasil yang sedang diukur.

                 tabindex="-1" menjauhkannya dari urutan Tab: Tab sudah punya arti sendiri di
                 halaman ini (fokus ke tombol restart), dan pemain desktop tak pernah butuh
                 input ini. --}}
            <input x-ref="typingInput" type="text" tabindex="-1"
                autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                inputmode="text" enterkeyhint="done"
                aria-label="{{ __('typing.input_aria') }}"
                @keydown="onTypingInputKeydown($event)"
                @beforeinput="onTypingBeforeInput($event)"
                @input="onTypingInput($event)"
                class="absolute inset-0 z-30 w-full h-full p-0 m-0 text-base bg-transparent text-transparent caret-transparent border-0 appearance-none rounded-none cursor-text focus:outline-none focus:ring-0">

            <!-- Kontainer 3 Baris -->
            {{-- @click memegang tap-nya (bukan inputnya, yang pointer-events-none) supaya
                 focusTypingInput() berjalan di dalam gestur. Tak berefek di desktop: tak ada
                 yang perlu diklik di sini, dan begitu input ini difokus, penjaga `editing` pada
                 @keydown.window otomatis menyerahkan kendali ke jalur input -- jadi tak ada
                 keystroke yang terhitung dua kali. --}}
            <div class="relative overflow-hidden text-fluid-type tracking-tight select-none outline-none"
                @click="focusTypingInput()"
                style="max-height: 4.875em;">

                <!-- SINGLE SMOOTH CURSOR -->
                <div x-ref="caret" x-show="!isFinished" wire:ignore
                    class="absolute top-0 left-0 w-[0.1em] h-[1.2em] z-20 rounded [transform-origin:top_left]"
                    {{-- The caret position is bound REACTIVELY via transform (not imperative el.style.transform).
                         Why: moveCaret() used to set transform directly on the DOM. Each time Livewire re-rendered
                         then morphed, morphdom STRIPPED that inline style (the server HTML has no transform) ->
                         the caret snapped back to (0,0) = top of the line, and nothing reapplied it because
                         wire:key was unchanged (not a remount). This happened when switching to Survival:
                         setMode() dispatches 'ghost-cleared', the component hears it via #[On('ghost-cleared')]
                         -> a SECOND round-trip -> morph -> the caret transform is wiped.
                         (Standard goes through applyGhostRestore, which usually doesn't dispatch, so it's safe.)
                         Two-layer fix: (1) wire:ignore -> Livewire never touches this element during morph;
                         (2) reactive transform -> Alpine always sets it from cursorLeft/cursorTop, never "lost". --}}
                    :style="{
                        backgroundColor: (currentMain === 'survival' && isStarted && !isFinished)
                            ? (staminaPct > 50 ? 'rgb(var(--color-brand-bright))' : (staminaPct > 25 ? 'rgb(var(--color-gold))' : 'rgb(var(--color-danger))'))
                            : 'rgb(var(--color-brand-bright))',
                        transform: `translate(${cursorLeft}px, ${cursorTop - scrollOffset}px)`
                    }"
                    {{-- Transform transition ONLY while typing: the cursor glides smoothly between characters
                         only when the user is actively typing (isTyping=true). Outside that (initial mount,
                         reset, mode/language change) there is NO transition -> reactive transform changes are
                         INSTANT, with no "gliding up" when switching Standard -> Survival. resetProgress sets
                         isTyping=false, so every reset is guaranteed instant. --}}
                    {{-- `will-change:transform` promotes the caret to its own compositing layer.
                         Applied ONLY while typing (when the transform actually animates) instead
                         of permanently: a permanent promotion adds memory/compositing overhead on
                         Linux without GPU accel for no benefit while the caret is idle. See
                         docs/review-performance-2026-07-27.md (Temuan 7). --}}
                    :class="isTyping
                        ? '[transition:transform_100ms_linear,background-color_150ms_ease-out] [will-change:transform]'
                        : 'animate-[caret-flash-smooth_1s_infinite]'">
                </div>

                <div x-ref="textContainer"
                    class="relative flex flex-wrap content-start gap-x-0 transition-transform duration-[85ms] ease-out"
                    :style="`transform: translateY(-${scrollOffset}px)`">

                    <!-- GHOST CURSOR: a second cursor on the same text track (z-10, below the real cursor).
                         Doesn't control scroll. Deliberately no transition on transform — the position is
                         already smoothed per-frame by updateGhostPosition(); the transition is for opacity only. -->
                    <div x-show="ghostActive && !isFinished" x-cloak
                        class="absolute top-0 left-0 w-[2.5px] h-[1.5em] transition-opacity duration-150 z-10 rounded opacity-40"
                        :style="`transform: translate(${ghostCursorLeft}px, ${ghostCursorTop}px); background-color: rgb(var(--color-muted));`">
                    </div>

                    {{-- PERF (restart delay Tier 2 + Temuan 1/F-4, docs/review-performance-2026-07-27.md):
                         the text spans are rendered CLIENT-SIDE via Alpine x-for over `renderWords`
                         (built in typing-game.js resetProgress), NOT a Blade @foreach. That is what
                         lets a restart swap the text IN-PLACE -- the server no longer re-renders &
                         morphs ~600 spans over the wire, which was the bulk of the restart delay
                         (worst on long texts). setMode/setContentLang benefit too (their render no
                         longer carries spans); restart() additionally skipRender()s entirely.

                         Per-character `:class` is bound ONLY to `inputResults[ch.i]`, deliberately
                         NOT to `currentIndex`. currentIndex changes every keystroke, so any span
                         reading it would re-evaluate on every keystroke -> O(n) recalc + paint over
                         the whole paragraph per key (the typing lag). Dropping it is provably
                         equivalent: the engine only writes inputResults at currentIndex then
                         advances, so a true/false char can never sit at index >= currentIndex.
                         DO NOT reintroduce a currentIndex read here.

                         Every letter AND word-separator space keeps id="char-{absoluteIndex}", which
                         updatePosition/getCharPosition/buildCharPositionCache/scroll all depend on. --}}
                    <template x-for="(word, wIdx) in renderWords" :key="wIdx">
                        <div class="flex">
                            <template x-for="ch in word.chars" :key="ch.i">
                                <span :id="'char-' + ch.i" class="char-element relative inline-block"
                                    :class="{
                                        'text-foreground': inputResults[ch.i] === true,
                                        'text-danger': inputResults[ch.i] === false,
                                        'text-muted': inputResults[ch.i] !== true && inputResults[ch.i] !== false,
                                        'border-b-2 border-danger': inputResults[ch.i] === 'skipped'
                                    }" x-text="ch.c"></span>
                            </template>

                            <!-- EXTRA CHARACTERS (overtype past the word) -->
                            <template x-if="extraChars[wIdx] && extraChars[wIdx].length > 0">
                                <template x-for="(extra, idx) in extraChars[wIdx]" :key="idx">
                                    <span :id="'extra-' + wIdx + '-' + idx"
                                        class="char-element relative inline-block text-danger tracking-tight opacity-90">
                                        <span x-text="extra"></span>
                                    </span>
                                </template>
                            </template>

                            <!-- WORD-SEPARATOR SPACE (spaceIndex is null on the last word) -->
                            <template x-if="word.spaceIndex !== null">
                                <span :id="'char-' + word.spaceIndex"
                                    class="char-element relative w-[0.5em] inline-block"
                                    :class="inputResults[word.spaceIndex] === false ? 'bg-danger/30' : ''">&nbsp;</span>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Petunjuk tap, HANYA di perangkat sentuh (`.touch-only` memakai
                 `pointer: coarse`, bukan lebar layar -- jendela desktop yang sempit tetap
                 punya keyboard fisik dan tak butuh petunjuk ini).

                 Tanpa ini tak ada apa pun di layar yang memberi tahu bahwa area teks harus
                 disentuh lebih dulu: caret berkedip seperti sudah siap menerima ketikan,
                 padahal keyboardnya belum terbuka.

                 x-show boleh berdampingan dengan .touch-only: saat kondisinya benar Alpine
                 MENGHAPUS `display:none` inline-nya, sehingga media query yang menentukan. --}}
            <div x-show="!isStarted && !isFinished" x-cloak class="touch-only justify-center mt-4">
                {{-- Sebuah <button> asli, bukan div ber-@click: iOS Safari tak selalu memicu
                     `click` pada elemen yang tak interaktif, dan tombol juga sudah benar secara
                     semantik. Ia hanya jalur cadangan -- sentuhan pada paragrafnya sendiri sudah
                     mendarat di input dan membuka keyboard tanpa JS. --}}
                <button type="button" @click="focusTypingInput()"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full border border-brand/40 bg-brand/10 text-small font-mono text-foreground transition hover:bg-brand/15 active:scale-95">
                    <svg class="w-4 h-4 shrink-0 text-brand-bright" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                    </svg>
                    {{ __('typing.tap_to_type') }}
                </button>
            </div>
            </div>

            <div class="mt-8 flex justify-center">
                {{-- War-lock: restart is disabled (one claim = one attempt). The gate is on the server. --}}
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
                        {{-- Keyboard hint: fine-pointer only. On touch there is no Tab/Enter, so
                             `.no-touch` drops the whole cluster (see app.css) rather than showing
                             instructions a phone user can't follow. --}}
                        <span class="no-touch items-center gap-1.5">
                            <kbd class="font-mono text-[0.6rem] px-1.5 py-0.5 rounded bg-surface border border-white/10">tab</kbd>
                            <span class="font-mono text-[0.6rem] text-muted">{{ __('typing.then') }}</span>
                            <kbd class="font-mono text-[0.6rem] px-1.5 py-0.5 rounded bg-surface border border-white/10">enter</kbd>
                        </span>
                    </button>
                @endif
            </div>
        </div>
    </div>

    <script>
        // bfcache guard: when Back serves /typing from the back-forward cache, the DOM is frozen
        // in the "finished" state (isFinished=true, $wire dead) -> the typing engine is stuck. Reload
        // for a clean mount. e.persisted is only true on a bfcache restore, so the initial load is unaffected.
        //
        // Kept inline (not moved to typing-game.js) because this is behavior specific to the /typing
        // page, not the component -- moving it to app.js would reload EVERY page restored from bfcache.
        window.addEventListener('pageshow', (e) => {
            if (e.persisted) {
                window.location.reload();
            }
        });
    </script>

    {{-- The typing engine itself lives in resources/js/typing-game.js, registered as
         window.typingGame via app.js. --}}
</div>
