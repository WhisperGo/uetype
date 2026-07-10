<div class="max-w-5xl px-4 mx-auto py-6 sm:px-6 lg:px-8 text-foreground">
    @if (session()->has('error'))
        <div class="p-4 mb-6 text-sm text-danger bg-danger/10 border border-danger/40 rounded-2xl">
            {{ session('error') }}
        </div>
    @endif

    <!-- ===== 1. HALAMAN PILIH: CREATE OR JOIN ROOM ===== -->
    @if ($this->step === 'choose')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-12 md:gap-24 items-stretch mt-10 relative w-full">
            <div
                class="flex flex-col items-center justify-center p-8 border bg-surface/50 border-border/40 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full">
                <div class="w-20 h-20 mb-6 flex items-center justify-center text-4xl transition duration-300">
                    <img src="/icon/uetype_mascot.png" alt="{{ __('multiplayer.create_room') }}">
                </div>
                <h3 class="text-xl font-mono font-bold tracking-wider text-foreground mb-2 uppercase">{{ __('multiplayer.create_room') }}</h3>
                <p class="text-sm text-muted max-w-xs mb-8">{{ __('multiplayer.create_room_desc') }}</p>
                <button wire:click="createRoom"
                    class="px-6 py-3 bg-gold hover:bg-secondary-7 text-background font-mono font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                    {{ __('multiplayer.create_room') }}
                </button>
            </div>

            <div
                class="hidden md:flex absolute inset-y-0 left-1/2 -translate-x-1/2 items-center justify-center pointer-events-none">
                <div class="w-[1px] h-full bg-border/50 relative flex items-center justify-center">
                    <div
                        class="absolute w-12 h-12 rounded-full border-2 border-gold bg-background flex items-center justify-center font-mono text-xs font-bold tracking-wider text-foreground shadow-xl">
                        {{ __('multiplayer.or') }}
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col items-center justify-center p-8 border bg-surface/50 border-border/40 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full"
                x-data="{
                    syncBoxes() {
                        const boxes = [...$refs.codeBoxes.querySelectorAll('input')];
                        $wire.set('joinCodeInput', boxes.map(box => box.value));
                    },
                    distribute(event) {
                        event.preventDefault();
                        const raw = (event.clipboardData || window.clipboardData).getData('text');
                        const chars = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6).split('');
                        const code = Array.from({ length: 6 }, (_, i) => chars[i] ?? '');
                        const boxes = [...$refs.codeBoxes.querySelectorAll('input')];
                        boxes.forEach((box, i) => { box.value = code[i]; });
                        $wire.set('joinCodeInput', code);
                        const lastFilled = Math.min(chars.length, 6) - 1;
                        (boxes[lastFilled] ?? boxes[0])?.focus();
                    },
                    backspace(event) {
                        if (event.target.value.length !== 0) { return; }
                        const prev = event.target.previousElementSibling;
                        if (!prev) { return; }
                        event.preventDefault();
                        prev.value = '';
                        prev.focus();
                        this.syncBoxes();
                    }
                }">
                <div class="w-20 h-20 mb-6 flex items-center justify-center text-4xl transition duration-300">
                    <img src="/icon/uetype_mascot.png" alt="{{ __('multiplayer.join_room') }}">
                </div>
                <h3 class="text-xl font-mono font-bold tracking-wider text-foreground mb-2 uppercase">{{ __('multiplayer.join_room') }}</h3>
                <p class="text-sm text-muted max-w-xs mb-6">{{ __('multiplayer.join_room_desc') }}</p>

                <div class="flex gap-2 mb-6" x-ref="codeBoxes">
                    @foreach (range(0, 5) as $index)
                        <input type="text" maxlength="1" wire:key="join-box-{{ $index }}"
                            class="w-12 h-14 text-center font-mono text-xl font-bold uppercase bg-background border border-border/40 rounded-xl focus:border-brand-bright focus:ring-0 text-foreground"
                            x-on:paste="distribute($event)"
                            x-on:input="$el.value = $el.value.toUpperCase()"
                            x-on:keydown.backspace="backspace($event)"
                            x-on:keyup="if($event.key !== 'Backspace' && $el.value.length == 1 && {{ $index }} < 5) { $el.nextElementSibling.focus() }" />
                    @endforeach
                </div>

                <button type="button" x-on:click="syncBoxes(); $wire.joinRoom()"
                    class="px-8 py-3 border border-border/40 text-foreground hover:bg-foreground/5 font-mono font-semibold uppercase tracking-wider rounded-xl transition duration-200">
                    {{ __('multiplayer.join_room') }}
                </button>
            </div>
        </div>

        <!-- HOW IT WORKS PANEL -->
        <div
            class="mt-20 pt-10 border-t border-border/30 flex flex-col md:flex-row items-center justify-center gap-6 md:gap-8 select-none">
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-gold bg-elevated/80 flex items-center justify-center font-mono text-xs font-bold text-foreground shadow-inner">
                    1</div>
                <span class="font-mono text-sm text-muted tracking-wide">{{ __('multiplayer.how_1') }}</span>
            </div>
            <span class="text-muted/40 font-mono text-sm hidden md:block">-</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-gold bg-elevated/80 flex items-center justify-center font-mono text-xs font-bold text-foreground shadow-inner">
                    2</div>
                <span class="font-mono text-sm text-muted tracking-wide">{{ __('multiplayer.how_2') }}</span>
            </div>
            <span class="text-muted/40 font-mono text-sm hidden md:block">-</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-gold bg-elevated/80 flex items-center justify-center font-mono text-xs font-bold text-foreground shadow-inner">
                    3</div>
                <span class="font-mono text-sm text-muted tracking-wide">{{ __('multiplayer.how_3') }}</span>
            </div>
        </div>
    @endif

    <!-- ===== 2. HALAMAN RUANG TUNGGU: WAITING ROOM ===== -->
    @if ($this->step === 'waiting' && $this->roomData && !$showResultModal)
        {{-- Update lobby (join/ready/leave) didorong via WebSocket .room.updated -> Livewire.dispatch('room-updated'), bukan polling. --}}
        <div class="space-y-8">
            <div
                x-data="{
                    copied: false,
                    copyTimer: null,
                    async copyRoomCode() {
                        if (this.copied) { return; }

                        await navigator.clipboard.writeText(@js($this->roomData->code));

                        this.copied = true;
                        clearTimeout(this.copyTimer);
                        this.copyTimer = setTimeout(() => { this.copied = false; }, 4000);
                    }
                }"
                class="p-6 border bg-surface/40 border-border/40 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-mono tracking-widest text-muted uppercase">{{ __('multiplayer.room_code_share') }}</span>
                    <h2 class="text-fluid-title font-mono font-black tracking-[0.3em] text-foreground mt-1">
                        {{ $this->roomData->code }}</h2>
                </div>
                <button type="button"
                    x-on:click.prevent="copyRoomCode()"
                    x-bind:disabled="copied"
                    x-bind:class="copied ? 'bg-active text-background border-active/35 cursor-default' : 'bg-foreground/5 border-border/40 hover:bg-foreground/10'"
                    class="inline-grid appearance-none place-items-center px-5 py-2.5 border font-mono text-xs font-bold uppercase tracking-wider rounded-xl transition-colors duration-200 disabled:pointer-events-none disabled:opacity-100"
                    aria-live="polite">
                    <span class="[grid-area:1/1] translate-y-[0.5px] font-mono text-xs font-bold uppercase leading-[1.1] tracking-wider text-current"
                        x-bind:class="copied ? 'invisible' : 'visible'">{{ __('multiplayer.copy_code') }}</span>
                    <span class="[grid-area:1/1] translate-y-[0.5px] font-mono text-xs font-bold uppercase leading-[1.1] tracking-wider text-current"
                        x-bind:class="copied ? 'visible' : 'invisible'">{{ __('multiplayer.copied') }}</span>
                </button>
            </div>

            <div>
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xs uppercase tracking-widest text-muted font-mono font-bold">{{ __('multiplayer.players') }}</h3>
                    <span class="text-xs font-mono text-gold font-bold">{{ __('multiplayer.joined', ['count' => $this->roomData->members->count()]) }}</span>
                </div>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                    @foreach (range(0, 4) as $i)
                        @php
                            $member = $this->roomData->members->values()->get($i);
                        @endphp
                        @if ($member)
                            <div
                                class="p-5 border flex flex-col items-center justify-center text-center rounded-2xl relative transition duration-300 {{ $member->user_id === Auth::id() ? 'bg-elevated/60 border-brand-bright' : 'bg-surface/40 border-border/40' }}">
                                <div
                                    class="w-14 h-14 rounded-xl overflow-hidden bg-foreground/5 mb-3 flex items-center justify-center text-xl">
                                    @if ($member->user->avatar)
                                        <img src="{{ $member->user->avatar }}" referrerpolicy="no-referrer"
                                            class="w-full h-full object-cover">
                                    @else
                                        <img src="/icon/uetype_mascot.png" alt="{{ $member->user->username }}" class="w-4/5 h-4/5 object-contain">
                                    @endif
                                </div>
                                <span
                                    class="font-mono text-sm font-bold truncate max-w-[100px] text-foreground">{{ $member->user->username }}</span>
                                <div class="mt-2.5 flex items-center gap-1.5">
                                    @if ($member->user_id === $this->roomData->host_id)
                                        <span class="w-1.5 h-1.5 rounded-full bg-gold"></span>
                                        <span class="text-[10px] font-mono font-bold uppercase tracking-wider text-gold">{{ __('multiplayer.host') }}</span>
                                    @else
                                        <span class="w-1.5 h-1.5 rounded-full {{ $member->is_ready ? 'bg-active' : 'bg-muted' }}"></span>
                                        <span class="text-[10px] font-mono font-bold uppercase tracking-wider {{ $member->is_ready ? 'text-active' : 'text-muted' }}">{{ $member->is_ready ? __('multiplayer.ready') : __('multiplayer.not_ready') }}</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div
                                class="p-5 border border-dashed border-border/50 flex flex-col items-center justify-center text-center rounded-2xl opacity-40">
                                <div
                                    class="w-12 h-12 rounded-full border border-dashed border-border/60 mb-2 flex items-center justify-center font-mono text-sm text-muted">
                                    ?</div>
                                <span class="text-xs font-mono text-muted">{{ __('multiplayer.empty_slot') }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="space-y-1">
                <div class="h-2 w-full bg-background rounded-full overflow-hidden border border-border/30">
                    <div class="h-full bg-gold transition-all duration-300"
                        style="width: {{ ($this->roomData->members->count() / 5) * 100 }}%"></div>
                </div>
            </div>

            <div class="pt-6 border-t border-border/30 flex flex-wrap gap-3 sm:gap-4">
                @if ($this->isHost)
                    <button wire:click="startRace" @disabled(!$this->allReady)
                        class="px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->allReady ? 'bg-gold hover:bg-secondary-7 text-background shadow-md' : 'bg-elevated text-muted cursor-not-allowed border border-border/30' }}">
                        {{ __('multiplayer.start_race') }}
                    </button>
                    @if (!$this->allReady)
                        <span class="text-xs font-mono text-muted self-center">{{ __('multiplayer.waiting_ready') }}</span>
                    @endif
                @else
                    <button wire:click="toggleReady"
                        class="px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? 'bg-active text-background hover:bg-active-5' : 'bg-gold hover:bg-secondary-7 text-background' }}">
                        {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? __('multiplayer.im_not_ready') : __('multiplayer.im_ready') }}
                    </button>
                @endif

                <button wire:click="leaveRoom"
                    class="px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    {{ __('multiplayer.leave_room') }}
                </button>
            </div>
        </div>
    @endif

    <!-- ===== 3. HALAMAN ARENA PERTANDINGAN: BATTLE STAGE (TYPERACER MECHANICS) ===== -->
    @if ($this->step === 'racing' && $this->roomData && !$showResultModal)
        {{-- wire:key stabil: state Alpine (raceStarted/countdown/progress) tak reset lintas re-render. --}}
        {{-- Logika Alpine ada di komponen 'raceArena' (lihat @assets), bukan inline di x-data. --}}
        {{-- Sudden death disinkron via WebSocket + clock lokal; saat 0, lockRace() panggil checkSuddenDeath() sekali. --}}
        @php $arenaDense = $this->roomData->members->count() >= 4; @endphp
        <div wire:key="race-arena-{{ $this->roomCode }}" class="{{ $arenaDense ? 'space-y-4' : 'space-y-6' }}"
            x-data="raceArena({
                myId: @js(Auth::id()),
                textToType: @js($this->roomData->text_to_type),
                raceStartsAt: @js($this->raceStartsAt),
                serverNow: @js($this->serverNow),
                suddenDeathActive: @js($this->suddenDeathActive),
                suddenDeathRemaining: @js($this->suddenDeathRemaining),
            })"
            @keydown.tab.prevent="if (raceStarted && !isFinished && !lockedByTimeout) $refs.typeInput?.focus()">

            <!-- HEADER TIPIS: ROOM CODE (KANAN) + SUDDEN DEATH INLINE (KIRI SAAT AKTIF) -->
            <div class="flex items-center justify-between gap-4">
                @if ($this->suddenDeathActive)
                    <span x-init="syncSuddenDeath(@js($this->suddenDeathRemaining))"
                        class="flex items-center gap-2 font-mono text-xs uppercase tracking-wider text-danger font-bold">
                        <span class="w-1.5 h-1.5 rounded-full bg-danger animate-pulse"></span>
                        {{ __('multiplayer.sudden_death') }} <span class="text-foreground">- <span x-text="suddenDeathRemaining"></span>s</span>
                    </span>
                @else
                    <span></span>
                @endif
                <span class="font-mono text-[11px] uppercase tracking-widest text-muted shrink-0">
                    {{ __('multiplayer.room_label') }} <span class="text-gold font-bold">- {{ $this->roomCode }}</span>
                </span>
            </div>

            <!-- OVERLAY COUNTDOWN SCREEN: hanya untuk start race; guard !suddenDeathActive agar tak muncul lagi saat countdown sudden death -->
            <template x-if="!raceStarted && !suddenDeathActive">
                <div class="fixed inset-0 bg-background/95 flex flex-col items-center justify-center z-50 select-none">
                    <span class="font-mono text-xs uppercase tracking-[0.4em] text-muted mb-4">{{ __('multiplayer.race_starting') }}</span>
                    <div class="text-fluid-hero font-mono font-black tracking-wider text-gold scale-110 transition-all duration-300"
                        x-text="countdown"></div>
                </div>
            </template>

            @php
                $laneAccents = [
                    ['ring' => 'border-brand-bright', 'text' => 'text-brand-bright', 'bar' => 'bg-brand-bright/15', 'edge' => 'border-brand-bright', 'chip' => 'bg-brand-bright/20', 'dot' => 'bg-brand-bright'],
                    ['ring' => 'border-gold', 'text' => 'text-gold', 'bar' => 'bg-gold/15', 'edge' => 'border-gold', 'chip' => 'bg-gold/20', 'dot' => 'bg-gold'],
                    ['ring' => 'border-active', 'text' => 'text-active', 'bar' => 'bg-active/15', 'edge' => 'border-active', 'chip' => 'bg-active/20', 'dot' => 'bg-active'],
                    ['ring' => 'border-secondary-4', 'text' => 'text-secondary-4', 'bar' => 'bg-secondary-4/15', 'edge' => 'border-secondary-4', 'chip' => 'bg-secondary-4/20', 'dot' => 'bg-secondary-4'],
                    ['ring' => 'border-primary-3', 'text' => 'text-primary-3', 'bar' => 'bg-primary-3/15', 'edge' => 'border-primary-3', 'chip' => 'bg-primary-3/20', 'dot' => 'bg-primary-3'],
                ];
                $playerCount = $this->roomData->members->count();
                $dense = $playerCount >= 4;
            @endphp

            <!-- KLASEMEN LANGSUNG: LANE PER PEMAIN -->
            <div class="border bg-surface/50 border-border/40 rounded-3xl shadow-xl {{ $dense ? 'p-4 space-y-2' : 'p-6 space-y-3' }}">
                <span class="text-xs font-mono uppercase tracking-widest text-muted block">{{ __('multiplayer.live_standings') }}</span>

                <div class="bg-background/40 rounded-2xl border border-border/20 {{ $dense ? 'p-3 space-y-2' : 'p-4 space-y-3' }}">
                    @foreach ($this->roomData->members as $player)
                        @php
                            $isSelf = $player->user_id === Auth::id();
                            $accent = $laneAccents[$loop->index % count($laneAccents)];
                            $isHostPlayer = $player->user_id === $this->roomData->host_id;
                        @endphp
                        {{-- Semua lane baca $store.race.opponents[id] seragam (termasuk diri sendiri lewat publishLocal),
                             diisi dari payload WebSocket tanpa re-render Livewire. Nilai Blade hanya seed awal. --}}
                        <div x-data="{
                                playerId: @js($player->user_id),
                                seedProgress: @js((int) ($player->progress_percent ?? 0)),
                                seedWpm: @js((int) ($player->wpm ?? 0)),
                                seedFinished: @js((bool) $player->finished_time_seconds),
                                get liveProgress() {
                                    return $store.race.opponents[this.playerId]?.progress ?? this.seedProgress;
                                },
                                get liveWpmValue() {
                                    return $store.race.opponents[this.playerId]?.wpm ?? this.seedWpm;
                                },
                                get liveFinished() {
                                    return $store.race.opponents[this.playerId]?.finished ?? this.seedFinished;
                                },
                                get isLeader() {
                                    return String($store.race.leaderId()) === String(this.playerId) && this.liveProgress > 0;
                                },
                                get runnerTilt() {
                                    const w = Math.min(this.liveWpmValue, 120);
                                    return `rotate(${(w / 120) * -8}deg) scale(${1 + (w / 120) * 0.12})`;
                                },
                            }">
                            <div class="flex justify-between items-center mb-1 text-xs font-mono">
                                <span class="flex items-center gap-1.5 min-w-0 {{ $isSelf ? $accent['text'].' font-bold' : 'text-muted' }}">
                                    <span class="w-1.5 h-1.5 rounded-full shrink-0 {{ $accent['dot'] }}"></span>
                                    <span class="truncate">{{ $player->user->username }}</span>
                                    @if ($isSelf)
                                        <span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded {{ $accent['chip'] }} {{ $accent['text'] }} shrink-0">{{ __('multiplayer.you') }}</span>
                                    @endif
                                    @if ($isHostPlayer)
                                        <span class="text-[10px] text-muted/70 shrink-0">[{{ __('multiplayer.host') }}]</span>
                                    @endif
                                    <span class="text-gold font-bold text-[10px] uppercase tracking-wider shrink-0 flex items-center gap-1" x-show="isLeader && !liveFinished" x-cloak><span class="text-xs leading-none">👑</span>{{ __('multiplayer.leader') }}</span>
                                    <span class="text-active font-bold ml-1 shrink-0" x-show="liveFinished" x-cloak>{{ __('multiplayer.finished') }}</span>
                                </span>
                                <span class="font-mono font-bold shrink-0 {{ $accent['text'] }}"><span x-text="liveWpmValue"></span> WPM</span>
                            </div>

                            <div class="w-full bg-background rounded-lg relative border overflow-hidden flex items-center transition-all duration-300 {{ $dense ? 'h-7' : 'h-9' }}"
                                :class="{
                                    'border-gold/60': isLeader && !liveFinished,
                                    'border-active/60': liveFinished,
                                    'border-border/30': !isLeader && !liveFinished,
                                }">
                                <div class="absolute right-0 top-0 bottom-0 w-7 bg-elevated/50 border-l border-dashed border-border/50 flex items-center justify-center font-mono text-[9px] text-muted/50 select-none">
                                    {{ __('multiplayer.finish') }}</div>

                                <div class="h-full transition-all duration-300 flex items-center justify-end relative border-r-2 {{ $accent['bar'] }} {{ $accent['edge'] }}"
                                    :class="{ 'opacity-100': liveProgress > 0, 'opacity-70': liveProgress === 0 }"
                                    :style="`width: calc(8% + ${liveProgress}% * 0.86);`">
                                    <div class="race-runner rounded-md overflow-hidden border-2 bg-surface flex items-center justify-center -mr-1 {{ $accent['ring'] }} {{ $dense ? 'w-6 h-6' : 'w-7 h-7' }}"
                                        :style="`transform: ${runnerTilt}`">
                                        @if ($player->user->avatar)
                                            <img src="{{ $player->user->avatar }}" alt="{{ $player->user->username }}" referrerpolicy="no-referrer" class="w-full h-full object-cover">
                                        @else
                                            <img src="/icon/uetype_mascot.png" alt="{{ $player->user->username }}" class="w-4/5 h-4/5 object-contain">
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if (! $hasGivenUp && ! $hasFinished)
                <!-- CONTAINER UTAMA TEKS (VISUAL HIGH-RESPONSIVE TYPERACER STYLE) -->
                <div class="border bg-surface/40 border-border/40 rounded-3xl shadow-xl {{ $dense ? 'p-5 space-y-4' : 'p-8 space-y-6' }}"
                    :class="{ 'race-typo': hasError }">
                    <!-- BLOK DRAF PARAGRAF DENGAN INDIKATOR WARNA TYPERACER -->
                    <div
                        class="font-mono text-xl leading-relaxed tracking-wide select-none p-5 bg-background/30 rounded-xl border border-border/20 flex flex-wrap gap-x-2 gap-y-1">
                        <template x-for="(word, wIdx) in words" :key="wIdx">
                            <span
                                :class="{
                                    'text-active': wIdx < currentWordIndex && !wordHadError[wIdx],
                                    'text-gold/80 underline underline-offset-4 decoration-2 decoration-gold/50': wIdx <
                                        currentWordIndex && wordHadError[wIdx],
                                    'text-danger bg-danger/15 ring-1 ring-danger/40 px-1 rounded underline underline-offset-4 decoration-2': wIdx ===
                                        currentWordIndex && hasError,
                                    'text-foreground font-bold ring-1 ring-border/50 bg-foreground/5 px-1 rounded': wIdx ===
                                        currentWordIndex && !hasError,
                                    'text-muted': wIdx > currentWordIndex
                                }"
                                x-text="word"></span>
                        </template>
                    </div>

                    <!-- FIELD INPUT KATA TUNGGAL DENGAN HIGHLIGHT ERROR DYNAMIC -->
                    <div class="relative">
                        <input type="text" x-ref="typeInput" x-model="typedText" @input="checkInput()"
                            @keydown.space="handleSpace($event)" :disabled="!raceStarted || isFinished || lockedByTimeout"
                            :placeholder="lockedByTimeout ? @js(__('multiplayer.input_locked')) : (isFinished ? @js(__('multiplayer.input_finished')) : (raceStarted ? @js(__('multiplayer.input_type')) :
                                @js(__('multiplayer.input_wait'))))"
                            :class="{
                                'border-danger/60 focus:ring-danger focus:border-danger bg-danger/10 text-danger': hasError,
                                'focus:ring-1 focus:ring-gold focus:border-gold border-border/40 text-foreground': !
                                    hasError
                            }"
                            class="w-full px-5 py-4 bg-background border rounded-xl font-mono text-base transition-all duration-200 placeholder-muted/60 disabled:opacity-40 disabled:cursor-not-allowed" />
                    </div>

                    <div class="pt-4 flex justify-end">
                        <button type="button" tabindex="-1" wire:click="giveUp"
                            class="px-5 py-2.5 bg-transparent border border-border/40 text-muted hover:text-danger hover:border-danger/40 font-mono text-xs font-bold uppercase tracking-wider rounded-xl transition">
                            {{ __('multiplayer.give_up') }}
                        </button>
                    </div>
                </div>
            @else
                <!-- LAYAR TUNGGU: PEMAIN SUDAH SELESAI ATAU MENYERAH, MENONTON SISA BALAPAN -->
                <div class="border bg-surface/40 border-border/40 rounded-3xl shadow-xl p-8 flex flex-col items-center text-center gap-4">
                    <span class="text-fluid-title font-mono font-black uppercase tracking-wider {{ $hasGivenUp ? 'text-danger' : 'text-active' }}">
                        {{ $hasGivenUp ? __('multiplayer.gave_up_title') : __('multiplayer.finished_title') }}
                    </span>
                    <p class="text-sm font-mono text-muted max-w-sm">
                        {{ $hasGivenUp ? __('multiplayer.gave_up_waiting') : __('multiplayer.finished_waiting') }}
                    </p>
                    <button wire:click="leaveRoom"
                        class="mt-2 px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                        {{ __('multiplayer.leave_room') }}
                    </button>
                </div>
            @endif
        </div>
    @endif

    <!-- ===== 4. HALAMAN: MATCH RESULT ===== -->
    @if ($showResultModal && !empty($this->resultSnapshot))
        @php
            $results = collect($this->resultSnapshot)->map(fn ($row) => (object) $row);
            $stillIn = $this->stillInRoomUserIds;
        @endphp
        <div class="space-y-12 animate-fade-in py-4 select-none">

            <!-- HEADER MATCH RESULT -->
            <div class="flex flex-col space-y-1">
                <h1 class="text-fluid-title font-mono font-black text-gold tracking-wider uppercase">{{ __('multiplayer.match_result') }}</h1>
                @php
                    $myRank = $results->search(fn($m) => $m->user_id === Auth::id()) + 1;
                    $suffix = match ($myRank) {
                        1 => 'st',
                        2 => 'nd',
                        3 => 'rd',
                        default => 'th',
                    };
                @endphp
                <div class="flex items-center gap-2 text-xs font-mono text-muted uppercase tracking-widest">
                    <span>{{ __('multiplayer.room', ['code' => $this->roomCode]) }}</span>
                    <span class="text-muted/40">-</span>
                    <span>{!! __('multiplayer.you_placed', ['rank' => '<strong class="text-foreground font-bold">'.$myRank.(app()->getLocale() === 'en' ? $suffix : '').'</strong>']) !!}</span>
                </div>
            </div>

            <!-- VISUAL PODIUM 3 TERATAS -->
            @php
                $rank1 = $results->get(0);
                $rank2 = $results->get(1);
                $rank3 = $results->get(2);
            @endphp
            <div class="grid grid-cols-3 gap-4 items-end max-w-2xl mx-auto pt-16 pb-6 relative">

                <!-- PODIUM 2 (KIRI) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank2)
                        <div class="text-center font-mono text-xs">
                            <span class="text-muted block text-[10px]">{{ $rank2->wpm }} wpm</span>
                            <span
                                class="text-foreground font-bold block truncate max-w-[100px]">{{ $rank2->username }}</span>
                            @if ($rank2->user_id === Auth::id())
                                <span
                                    class="inline-block bg-gold text-background text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <x-friend-avatar :user="$rank2" size="w-10 h-10" ring="border-muted/60" fallback-size="w-4/5 h-4/5" class="race-surge" />
                    @endif
                    <div
                        class="w-full h-20 bg-transparent border-2 border-border/50 rounded-2xl flex items-center justify-center font-mono font-black text-3xl text-muted/40">
                        2
                    </div>
                </div>

                <!-- PODIUM 1 (TENGAH) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank1)
                        <div class="text-center font-mono text-xs">
                            <span class="text-muted block text-[10px]">{{ $rank1->wpm }} wpm</span>
                            <span
                                class="text-foreground font-bold block truncate max-w-[120px]">{{ $rank1->username }}</span>
                            @if ($rank1->user_id === Auth::id())
                                <span
                                    class="inline-block bg-background text-gold text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <x-friend-avatar :user="$rank1" size="w-12 h-12" ring="border-gold" fallback-size="w-4/5 h-4/5" class="race-surge shadow-lg" />
                    @endif
                    <div
                        class="w-full h-32 bg-gold rounded-2xl flex items-center justify-center font-mono font-black text-3xl sm:text-5xl text-background shadow-lg">
                        1
                    </div>
                </div>

                <!-- PODIUM 3 (KANAN) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank3)
                        <div class="text-center font-mono text-xs">
                            <span class="text-muted block text-[10px]">{{ $rank3->wpm }} wpm</span>
                            <span
                                class="text-foreground font-bold block truncate max-w-[100px]">{{ $rank3->username }}</span>
                            @if ($rank3->user_id === Auth::id())
                                <span
                                    class="inline-block bg-gold text-background text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <x-friend-avatar :user="$rank3" size="w-10 h-10" ring="border-gold/50" fallback-size="w-4/5 h-4/5" class="race-surge" />
                    @endif
                    <div
                        class="w-full h-16 bg-transparent border-2 border-gold/20 rounded-2xl flex items-center justify-center font-mono font-black text-2xl text-gold/30">
                        3
                    </div>
                </div>

            </div>

            <!-- TABEL FULL RESULTS -->
            <div class="space-y-3">
                <span class="text-[11px] font-mono uppercase tracking-[0.25em] text-muted block mb-1">{{ __('multiplayer.full_results') }}</span>
                <div
                    class="w-full border border-border/40 rounded-2xl overflow-x-auto bg-surface/10 backdrop-blur-sm">
                    <table class="w-full text-left font-mono text-sm border-collapse">
                        <thead>
                            <tr
                                class="border-b border-border/30 bg-background/20 text-xs text-muted uppercase tracking-wider">
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_place') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_player') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_wpm') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_accuracy') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_time') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/20">
                            @foreach ($results as $index => $rank)
                                @php
                                    $pos = $index + 1;
                                    $suffix = match ($pos) {
                                        1 => 'st',
                                        2 => 'nd',
                                        3 => 'rd',
                                        default => 'th',
                                    };
                                    $isMe = $rank->user_id === Auth::id();
                                    $hasLeft = ! in_array($rank->user_id, $stillIn);
                                @endphp
                                <tr
                                    class="transition duration-150 {{ $isMe ? 'bg-brand/25 text-foreground font-bold' : 'text-muted hover:bg-foreground/[0.02]' }} {{ $hasLeft ? 'opacity-50' : '' }}">
                                    <td class="py-4 px-3 sm:px-5 font-bold text-foreground">{{ $pos }}{{ $suffix }}
                                    </td>
                                    <td class="py-4 px-5">
                                        <div class="flex items-center gap-2">
                                            <span title="{{ $hasLeft ? __('multiplayer.left_room') : '' }}">{{ $rank->username }}</span>
                                            @if ($isMe)
                                                <span
                                                    class="bg-brand-bright text-background text-[9px] font-black px-1 py-0.1 rounded uppercase tracking-wide">{{ __('multiplayer.you') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-4 px-3 sm:px-5 text-gold font-bold">{{ $rank->wpm }} wpm</td>
                                    <td class="py-4 px-5">{{ $rank->accuracy ?? 97.0 }}%</td>
                                    <td class="py-4 px-5">
                                        @if ($rank->finished_time_seconds && $rank->finished_time_seconds != 999)
                                            {{ sprintf('%02d:%02d', floor($rank->finished_time_seconds / 60), $rank->finished_time_seconds % 60) }}
                                        @else
                                            {{-- DNF: jangan tampilkan waktu palsu. --}}
                                            <span class="text-danger/70 text-xs">{{ __('multiplayer.dnf') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- PANEL PROGRESS REPORT XP (data nyata dari getMyXpResultProperty) -->
            @php
                $xp = $this->myXpResult;
                $lvl = $xp['level'] ?? null;
                $xpProgress = $lvl['progress'] ?? 0;
                $xpNeeded = $lvl['needed'] ?? 0;
                $xpBarWidth = $xpNeeded > 0 ? min(100, ($xpProgress / $xpNeeded) * 100) : 0;
            @endphp
            @if ($xp)
                <div class="p-5 border border-border/40 bg-surface/30 rounded-2xl space-y-2">
                    <div class="flex justify-between items-center text-xs font-mono">
                        <div class="flex flex-col">
                            <span class="text-muted text-[10px] uppercase tracking-wide">{{ __('multiplayer.xp_earned') }}</span>
                            <span class="text-2xl font-black text-gold mt-0.5">+{{ number_format($xp['earned']) }} XP</span>
                        </div>
                        <div class="text-right flex flex-col items-end">
                            <span class="text-foreground font-bold text-xs">{{ number_format($xpProgress) }} / {{ number_format($xpNeeded) }} XP</span>
                            <span class="text-muted text-[10px] mt-0.5">Level {{ $lvl['level'] }} <span
                                    class="text-muted/50">-</span> {{ $lvl['next_level'] }}</span>
                        </div>
                    </div>
                    <div class="h-1.5 w-full bg-background/40 rounded-full overflow-hidden border border-border/20">
                        <div class="h-full bg-brand rounded-full transition-all duration-500"
                            style="width: {{ $xpBarWidth }}%"></div>
                    </div>
                </div>
            @endif

            <!-- AKSI BUTTON MENU BAWAH -->
            <div class="pt-2 flex flex-wrap gap-3 sm:gap-4">
                @if ($this->isHost)
                    <button wire:click="playAgain"
                        class="px-6 py-3 bg-gold hover:bg-secondary-7 text-background font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                        {{ __('multiplayer.play_again') }}
                    </button>
                @endif
                <button wire:click="leaveRoom"
                    class="px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    {{ __('multiplayer.leave_room') }}
                </button>
            </div>

        </div>
    @endif

    {{-- Wajib @assets, bukan @script: @script membungkus script jadi atribut wire:effects, dan
         kode dengan banyak '<'/'>' merusak parsing DOM Livewire. --}}
    @assets
        <script>
            // Komponen Alpine 'raceArena': logika typing + sudden death.
            // registerRaceArena() idempoten (flag global).
            const registerRaceArena = (Alpine) => {
                if (window.__raceArenaRegistered) return;
                window.__raceArenaRegistered = true;

                // Store global 'race': posisi maskot lawan dari payload WebSocket. Store (bukan state
                // komponen) agar bertahan lintas Livewire morph. opponents = { [userId]: {progress, wpm, finished} }.
                if (!Alpine.store('race')) {
                    Alpine.store('race', {
                        opponents: {},
                        apply(userId, data) {
                            // Reassign object agar reaktivitas Alpine ter-trigger.
                            this.opponents = {
                                ...this.opponents,
                                [userId]: {
                                    progress: data.progress_percent ?? 0,
                                    wpm: data.wpm ?? 0,
                                    finished: !!data.finished,
                                },
                            };
                        },
                        leaderId() {
                            let bestId = null;
                            let bestProgress = 0;
                            for (const [id, o] of Object.entries(this.opponents)) {
                                const p = o.progress ?? 0;
                                if (p > bestProgress) {
                                    bestProgress = p;
                                    bestId = id;
                                }
                            }
                            return bestId;
                        },
                        reset() {
                            this.opponents = {};
                        },
                    });
                }

                Alpine.data('raceArena', (config = {}) => ({
                    countdown: 3,
                    raceStarted: false,
                    myId: config.myId,
                    textToType: config.textToType || '',
                    // Waktu absolut (ms epoch) race mulai, dari server - countdown dihitung mundur ke titik ini agar sinkron antar layar.
                    raceStartsAtMs: config.raceStartsAt ? new Date(config.raceStartsAt).getTime() : null,
                    // offset = jam server saat render - jam lokal; menyelaraskan Date.now() ke jam server agar countdown sinkron antar layar.
                    clockOffsetMs: config.serverNow ? new Date(config.serverNow).getTime() - Date.now() : 0,
                    _countdownInterval: null,
                    words: [],
                    currentWordIndex: 0,
                    typedText: '',
                    startTime: null,
                    isFinished: false,
                    hasError: false,
                    correctCharsFromPastWords: 0,
                    totalKeystrokes: 0,
                    totalMistakes: 0,
                    prevTypedLength: 0,
                    // true kalau kata itu dilewati salah/belum lengkap (space tanpa exact match) - untuk highlight visual riwayat error per kata.
                    wordHadError: [],

                    // Progress & WPM pemain lokal, reaktif, dibaca lane maskot sendiri di view. Diperbarui tiap checkInput().
                    progressPercent: 0,
                    liveWpm: 0,

                    // Throttle emit progress ke server: visual lokal instan, jaringan dibatasi ~120ms + trailing flush.
                    _lastEmit: 0,
                    _emitTimer: null,

                    // Sudden death: timer client-side, tapi checkSuddenDeath() di server tetap sumber kebenaran final.
                    suddenDeathActive: !!config.suddenDeathActive,
                    suddenDeathRemaining: config.suddenDeathRemaining ?? 15,
                    lockedByTimeout: false,
                    _sdInterval: null,

                    init() {
                        this.words = this.textToType.split(' ');

                        // Bersihkan posisi lawan dari race sebelumnya; jangan hapus kalau sudden death aktif (re-init di tengah race).
                        if (!this.suddenDeathActive && this.$store.race) {
                            this.$store.race.reset();
                        }

                        // Sudden death aktif saat (re)init = race sudah berjalan -> skip overlay countdown.
                        if (this.suddenDeathActive) {
                            this.raceStarted = true;
                            this.countdown = 'GO!';
                            this.startTime = Date.now();
                            this.startSuddenDeathClock();
                            this.$nextTick(() => {
                                if (this.$refs.typeInput) this.$refs.typeInput.focus();
                            });
                        } else {
                            // Hitung mundur ke race_starts_at server: waktu absolut sama di semua layar.
                            this.startSyncedCountdown();
                        }

                        // Sinyal server saat room ditutup paksa -> kunci total.
                        this.$wire.on('force-finish', () => this.lockRace());

                        // Bridge event .race.sudden_death -> hitung mundur komponen ini. Disimpan agar bisa di-remove saat destroy.
                        this._onSuddenDeath = (ev) => this.syncSuddenDeath(ev.detail.remaining);
                        window.addEventListener('race-sudden-death', this._onSuddenDeath);
                    },

                    // Waktu lokal yang sudah diselaraskan ke jam server (Date.now() + offset).
                    serverNow() {
                        return Date.now() + this.clockOffsetMs;
                    },

                    // Berbasis waktu absolut server, bukan interval lokal, agar semua layar sinkron.
                    startSyncedCountdown() {
                        const tick = () => {
                            // Fallback jika server tidak mengirim raceStartsAt.
                            if (!this.raceStartsAtMs) {
                                this.beginRace();
                                return;
                            }

                            const remainingMs = this.raceStartsAtMs - this.serverNow();

                            if (remainingMs <= 0) {
                                this.countdown = 'GO!';
                                if (this._countdownInterval) {
                                    clearInterval(this._countdownInterval);
                                    this._countdownInterval = null;
                                }
                                setTimeout(() => this.beginRace(), 400);
                            } else {
                                // ceil supaya 2001ms..3000ms => "3", dst. Minimal tampil "1".
                                this.countdown = Math.max(1, Math.ceil(remainingMs / 1000));
                            }
                        };

                        tick();
                        this._countdownInterval = setInterval(tick, 100);
                    },

                    // startTime dipatok ke race_starts_at server agar WPM antar pemain pakai titik awal sama.
                    beginRace() {
                        if (this.raceStarted) return;
                        this.raceStarted = true;
                        this.startTime = this.raceStartsAtMs ?? Date.now();
                        this.$nextTick(() => {
                            if (this.$refs.typeInput) this.$refs.typeInput.focus();
                        });
                    },

                    destroy() {
                        if (this._onSuddenDeath) {
                            window.removeEventListener('race-sudden-death', this._onSuddenDeath);
                            this._onSuddenDeath = null;
                        }
                        if (this._countdownInterval) {
                            clearInterval(this._countdownInterval);
                            this._countdownInterval = null;
                        }
                        if (this._sdInterval) {
                            clearInterval(this._sdInterval);
                            this._sdInterval = null;
                        }
                        if (this._emitTimer) {
                            clearTimeout(this._emitTimer);
                            this._emitTimer = null;
                        }
                    },

                    // Sinkronkan sisa waktu dari server & pastikan clock lokal jalan.
                    syncSuddenDeath(remainingFromServer) {
                        this.suddenDeathActive = true;
                        // Ambil nilai paling konservatif kalau clock sudah jalan (hindari mundur naik).
                        if (this._sdInterval) {
                            this.suddenDeathRemaining = Math.min(this.suddenDeathRemaining, remainingFromServer);
                        } else {
                            this.suddenDeathRemaining = remainingFromServer;
                        }
                        this.startSuddenDeathClock();
                        if (this.suddenDeathRemaining <= 0) this.lockRace();
                    },

                    startSuddenDeathClock() {
                        if (this._sdInterval) return;
                        this._sdInterval = setInterval(() => {
                            this.suddenDeathRemaining--;
                            if (this.suddenDeathRemaining <= 0) {
                                this.suddenDeathRemaining = 0;
                                this.lockRace();
                            }
                        }, 1000);
                    },

                    // Kunci paksa input & pengiriman progress. Idempoten.
                    lockRace() {
                        if (this.lockedByTimeout) return;
                        this.lockedByTimeout = true;
                        this.isFinished = true;
                        if (this._sdInterval) {
                            clearInterval(this._sdInterval);
                            this._sdInterval = null;
                        }
                        if (this._emitTimer) {
                            clearTimeout(this._emitTimer);
                            this._emitTimer = null;
                        }
                        if (this.$refs.typeInput) this.$refs.typeInput.blur();

                        // checkSuddenDeath() idempoten: aman dipanggil beberapa klien bersamaan.
                        if (this.$wire) this.$wire.checkSuddenDeath();
                    },

                    checkInput() {
                        if (this.lockedByTimeout || this.isFinished || !this.raceStarted) return;

                        let targetWord = this.words[this.currentWordIndex];

                        if (this.typedText.length > 0) {
                            this.hasError = !targetWord.startsWith(this.typedText);
                        } else {
                            this.hasError = false;
                        }

                        if (this.typedText.length > this.prevTypedLength) {
                            this.totalKeystrokes++;
                            if (this.hasError) this.totalMistakes++;
                        }
                        this.prevTypedLength = this.typedText.length;

                        let correctInCurrent = 0;
                        if (!this.hasError) {
                            correctInCurrent = this.typedText.length;
                        } else {
                            for (let i = 0; i < this.typedText.length; i++) {
                                if (this.typedText[i] === targetWord[i]) {
                                    correctInCurrent++;
                                } else {
                                    break;
                                }
                            }
                        }

                        let totalCorrectChars = this.correctCharsFromPastWords + correctInCurrent;
                        let progressPercent = Math.floor((totalCorrectChars / this.textToType.length) * 100);

                        let accuracyPercent = this.totalKeystrokes > 0 ?
                            Math.round(((this.totalKeystrokes - this.totalMistakes) / this.totalKeystrokes) * 100) :
                            100;

                        let timePassedMinutes = (Date.now() - this.startTime) / 60000;
                        let liveWpm = timePassedMinutes > 0 ? Math.floor((totalCorrectChars / 5) / timePassedMinutes) : 0;

                        // State reaktif lokal diperbarui langsung -> maskot sendiri gerak instan, tak menunggu jaringan.
                        this.liveWpm = liveWpm;

                        // Kata terakhir selesai otomatis saat huruf terakhir benar.
                        if (this.currentWordIndex === this.words.length - 1 && this.typedText === targetWord) {
                            this.isFinished = true;
                            this.progressPercent = 100;
                            this.publishLocal(100, liveWpm, true);
                            // force=true: finish wajib dikirim segera, tak di-throttle.
                            this.emitProgress(100, liveWpm, accuracyPercent, true);
                            return;
                        }

                        this.progressPercent = progressPercent;
                        this.publishLocal(progressPercent, liveWpm, false);
                        this.emitProgress(progressPercent, liveWpm, accuracyPercent, false);
                    },

                    // Publikasikan posisi lokal ke store (key userId sendiri) agar lane sendiri & lawan seragam.
                    publishLocal(progress, wpm, finished) {
                        if (this.myId == null) return;
                        this.$store.race.apply(this.myId, {
                            progress_percent: progress,
                            wpm: wpm,
                            finished: finished,
                        });
                    },

                    // Throttle ~120ms + trailing-edge flush agar posisi terakhir tak hilang; `force` selalu segera.
                    emitProgress(progress, wpm, accuracy, force) {
                        const now = Date.now();
                        const MIN_INTERVAL = 120;

                        if (this._emitTimer) {
                            clearTimeout(this._emitTimer);
                            this._emitTimer = null;
                        }

                        if (force || (now - this._lastEmit) >= MIN_INTERVAL) {
                            this._lastEmit = now;
                            this.$wire.updateRaceProgress(progress, wpm, accuracy);
                            return;
                        }

                        // Terlalu cepat: jadwalkan trailing-edge flush dengan nilai terbaru saat timer menyala.
                        const delay = MIN_INTERVAL - (now - this._lastEmit);
                        this._emitTimer = setTimeout(() => {
                            this._emitTimer = null;
                            this._lastEmit = Date.now();
                            this.$wire.updateRaceProgress(
                                this.progressPercent,
                                this.liveWpm,
                                accuracy,
                            );
                        }, delay);
                    },

                    // Permisif seperti Solo: spasi selalu pindah kata (tak pernah mengunci); huruf salah/terlewat
                    // dicatat sebagai mistake tapi progres tetap jalan.
                    handleSpace(e) {
                        if (this.lockedByTimeout || this.isFinished || !this.raceStarted) return;

                        let targetWord = this.words[this.currentWordIndex];

                        // Cegah spam spasi di kata kosong (tak boleh "melompat" kata tanpa mengetik apa pun).
                        if (this.typedText.length === 0) {
                            e.preventDefault();
                            return;
                        }

                        e.preventDefault();

                        const isExactMatch = this.typedText === targetWord;
                        this.wordHadError[this.currentWordIndex] = !isExactMatch;

                        if (!isExactMatch) {
                            // Hanya huruf ekor yang belum diketik; yang sudah diketik dihitung di checkInput().
                            const missingCount = Math.max(0, targetWord.length - this.typedText.length);
                            this.totalKeystrokes += missingCount;
                            this.totalMistakes += missingCount;
                        }

                        this.correctCharsFromPastWords += targetWord.length + 1;
                        this.currentWordIndex++;
                        this.typedText = '';
                        this.hasError = false;
                        this.prevTypedLength = 0;

                        // Kata terakhir juga bisa finish lewat spasi (bukan hanya lewat exact match di checkInput()).
                        if (this.currentWordIndex >= this.words.length) {
                            this.isFinished = true;
                            this.progressPercent = 100;

                            const accuracyPercent = this.totalKeystrokes > 0
                                ? Math.round(((this.totalKeystrokes - this.totalMistakes) / this.totalKeystrokes) * 100)
                                : 100;
                            const timePassedMinutes = (Date.now() - this.startTime) / 60000;
                            const liveWpm = timePassedMinutes > 0
                                ? Math.floor((this.correctCharsFromPastWords / 5) / timePassedMinutes)
                                : 0;
                            this.liveWpm = liveWpm;

                            this.publishLocal(100, liveWpm, true);
                            this.emitProgress(100, liveWpm, accuracyPercent, true);
                            return;
                        }

                        this.checkInput();
                    },
                }));
            };

            // Alpine mungkin sudah booting (daftar langsung) atau belum (daftar saat alpine:init).
            if (window.Alpine) {
                registerRaceArena(window.Alpine);
            }
            document.addEventListener('alpine:init', () => registerRaceArena(window.Alpine));
        </script>
    @endassets

    {{-- Kode Echo/broadcast tetap di @script (butuh Livewire.on); aman karena tak mengandung '<'/'>' yang merusak parsing. --}}
    @script
        <script>
            let currentRoomChannel = null;
            let currentRaceChannel = null;

            const leaveChannels = () => {
                if (currentRoomChannel) {
                    window.Echo.leave(currentRoomChannel);
                    currentRoomChannel = null;
                }
                if (currentRaceChannel) {
                    window.Echo.leave(currentRaceChannel);
                    currentRaceChannel = null;
                }
                // Bersihkan posisi lawan agar room berikutnya tak kebawa state basi.
                if (window.Alpine && window.Alpine.store('race')) {
                    window.Alpine.store('race').reset();
                }
            };

            Livewire.on('subscribe-room', (event) => {
                const room = event.room;

                leaveChannels();

                // Channel lifecycle (low-frequency): join/ready/leave/start/finish -> tetap re-render Livewire penuh.
                currentRoomChannel = `room.${room}`;
                window.Echo
                    .channel(currentRoomChannel)
                    .listen('.room.updated', () => {
                        Livewire.dispatch('room-updated');
                    });

                // Channel race (high-frequency): posisi maskot lawan diterapkan langsung ke Alpine store, tanpa round-trip Livewire.
                currentRaceChannel = `race.${room}`;
                window.Echo
                    .channel(currentRaceChannel)
                    .listen('.race.progress', (e) => {
                        if (window.Alpine && window.Alpine.store('race')) {
                            window.Alpine.store('race').apply(e.userId, e.progressData || {});
                        }
                    })
                    .listen('.race.sudden_death', (e) => {
                        // Semua klien hitung sisa waktu dari timestamp akhir server yang sama -> tersinkron.
                        const endMs = new Date(e.endTimeIso).getTime();
                        const remaining = Math.max(0, Math.ceil((endMs - Date.now()) / 1000));
                        window.dispatchEvent(new CustomEvent('race-sudden-death', {
                            detail: { remaining }
                        }));
                    });
            });

            Livewire.on('leave-room', () => {
                leaveChannels();
            });
        </script>
    @endscript
</div>
