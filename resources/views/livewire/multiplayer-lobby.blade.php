<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8 text-typing-text">
    @if (session()->has('error'))
        <div class="p-4 mb-6 text-sm text-red-400 bg-red-950/40 border border-red-900 rounded-2xl">
            {{ session('error') }}
        </div>
    @endif

    <!-- ===== 1. HALAMAN PILIH: CREATE OR JOIN ROOM ===== -->
    @if ($this->step === 'choose')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-12 md:gap-24 items-stretch mt-10 relative w-full">
            <div
                class="flex flex-col items-center justify-center p-8 border bg-typing-surface/50 border-white/5 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full">
                <div class="w-20 h-20 mb-6 flex items-center justify-center text-4xl transition duration-300">
                    <img src="/icon/uetype_mascot.png" alt="{{ __('multiplayer.create_room') }}">
                </div>
                <h3 class="text-xl font-mono font-bold tracking-wider text-typing-text mb-2 uppercase">{{ __('multiplayer.create_room') }}</h3>
                <p class="text-sm text-typing-muted max-w-xs mb-8">{{ __('multiplayer.create_room_desc') }}</p>
                <button wire:click="createRoom"
                    class="px-6 py-3 bg-[#cbb38a] hover:bg-[#bfa57a] text-black font-mono font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                    {{ __('multiplayer.create_room') }}
                </button>
            </div>

            <div
                class="hidden md:flex absolute inset-y-0 left-1/2 -translate-x-1/2 items-center justify-center pointer-events-none">
                <div class="w-[1px] h-full bg-white/10 relative flex items-center justify-center">
                    <div
                        class="absolute w-12 h-12 rounded-full border-2 border-[#cbb38a] bg-typing-bg flex items-center justify-center font-mono text-xs font-bold tracking-wider text-typing-text shadow-xl">
                        {{ __('multiplayer.or') }}
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col items-center justify-center p-8 border bg-typing-surface/50 border-white/5 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full"
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
                <h3 class="text-xl font-mono font-bold tracking-wider text-typing-text mb-2 uppercase">{{ __('multiplayer.join_room') }}</h3>
                <p class="text-sm text-typing-muted max-w-xs mb-6">{{ __('multiplayer.join_room_desc') }}</p>

                <div class="flex gap-2 mb-6" x-ref="codeBoxes">
                    @foreach (range(0, 5) as $index)
                        <input type="text" maxlength="1" wire:key="join-box-{{ $index }}"
                            class="w-12 h-14 text-center font-mono text-xl font-bold uppercase bg-typing-bg border border-white/10 rounded-xl focus:border-typing-accent focus:ring-0 text-typing-text"
                            x-on:paste="distribute($event)"
                            x-on:input="$el.value = $el.value.toUpperCase()"
                            x-on:keydown.backspace="backspace($event)"
                            x-on:keyup="if($event.key !== 'Backspace' && $el.value.length == 1 && {{ $index }} < 5) { $el.nextElementSibling.focus() }" />
                    @endforeach
                </div>

                <button type="button" x-on:click="syncBoxes(); $wire.joinRoom()"
                    class="px-8 py-3 border border-white/10 text-typing-text hover:bg-white/5 font-mono font-semibold uppercase tracking-wider rounded-xl transition duration-200">
                    {{ __('multiplayer.join_room') }}
                </button>
            </div>
        </div>

        <!-- HOW IT WORKS PANEL -->
        <div
            class="mt-20 pt-10 border-t border-white/5 flex flex-col md:flex-row items-center justify-center gap-6 md:gap-8 select-none">
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-[#cbb38a] bg-[#1a2333]/80 flex items-center justify-center font-mono text-xs font-bold text-white shadow-inner">
                    1</div>
                <span class="font-mono text-sm text-typing-muted tracking-wide">{{ __('multiplayer.how_1') }}</span>
            </div>
            <span class="text-white/20 font-mono text-sm hidden md:block">→</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-[#cbb38a] bg-[#1a2333]/80 flex items-center justify-center font-mono text-xs font-bold text-white shadow-inner">
                    2</div>
                <span class="font-mono text-sm text-typing-muted tracking-wide">{{ __('multiplayer.how_2') }}</span>
            </div>
            <span class="text-white/20 font-mono text-sm hidden md:block">→</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-[#cbb38a] bg-[#1a2333]/80 flex items-center justify-center font-mono text-xs font-bold text-white shadow-inner">
                    3</div>
                <span class="font-mono text-sm text-typing-muted tracking-wide">{{ __('multiplayer.how_3') }}</span>
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
                class="p-6 border bg-typing-surface/40 border-white/5 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-mono tracking-widest text-typing-muted uppercase">{{ __('multiplayer.room_code_share') }}</span>
                    <h2 class="text-fluid-title font-mono font-black tracking-[0.3em] text-white mt-1">
                        {{ $this->roomData->code }}</h2>
                </div>
                <button type="button"
                    x-on:click.prevent="copyRoomCode()"
                    x-bind:disabled="copied"
                    x-bind:class="copied ? 'bg-active text-background border-active/35 cursor-default' : 'bg-white/5 border-white/10 hover:bg-white/10'"
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
                    <h3 class="text-xs uppercase tracking-widest text-typing-muted font-mono font-bold">{{ __('multiplayer.players') }}</h3>
                    <span class="text-xs font-mono text-[#cbb38a] font-bold">{{ __('multiplayer.joined', ['count' => $this->roomData->members->count()]) }}</span>
                </div>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                    @foreach (range(0, 4) as $i)
                        @php
                            $member = $this->roomData->members->values()->get($i);
                        @endphp
                        @if ($member)
                            <div
                                class="p-5 border flex flex-col items-center justify-center text-center rounded-2xl relative transition duration-300 {{ $member->user_id === Auth::id() ? 'bg-[#1a2333]/60 border-typing-accent' : 'bg-typing-surface/40 border-white/5' }}">
                                <div
                                    class="w-14 h-14 rounded-xl overflow-hidden bg-white/5 mb-3 flex items-center justify-center text-xl">
                                    @if ($member->user->avatar)
                                        <img src="{{ $member->user->avatar }}" referrerpolicy="no-referrer"
                                            class="w-full h-full object-cover">
                                    @else
                                        👨‍💻
                                    @endif
                                </div>
                                <span
                                    class="font-mono text-sm font-bold truncate max-w-[100px]">{{ $member->user->username }}</span>
                                <div class="mt-3 w-full">
                                    @if ($member->user_id === $this->roomData->host_id)
                                        <span
                                            class="inline-block w-full px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider bg-[#cbb38a] text-black rounded-md">{{ __('multiplayer.host') }}</span>
                                    @else
                                        <span
                                            class="inline-block w-full px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider rounded-md {{ $member->is_ready ? 'bg-active/15 text-active border border-active/35' : 'bg-zinc-800 text-zinc-400' }}">
                                            {{ $member->is_ready ? __('multiplayer.ready') : __('multiplayer.not_ready') }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <div
                                class="p-5 border border-dashed border-white/10 flex flex-col items-center justify-center text-center rounded-2xl opacity-40">
                                <div
                                    class="w-12 h-12 rounded-full border border-dashed border-white/20 mb-2 flex items-center justify-center font-mono text-sm">
                                    ?</div>
                                <span class="text-xs font-mono text-typing-muted">{{ __('multiplayer.empty_slot') }}</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <div class="space-y-1">
                <div class="h-2 w-full bg-zinc-900 rounded-full overflow-hidden border border-white/5">
                    <div class="h-full bg-[#cbb38a] transition-all duration-300"
                        style="width: {{ ($this->roomData->members->count() / 5) * 100 }}%"></div>
                </div>
            </div>

            <div class="pt-6 border-t border-white/5 flex flex-wrap gap-3 sm:gap-4">
                @if ($this->isHost)
                    <button wire:click="startRace" @disabled(!$this->allReady)
                        class="px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->allReady ? 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black shadow-md' : 'bg-zinc-800 text-zinc-500 cursor-not-allowed border border-white/5' }}">
                        {{ __('multiplayer.start_race') }}
                    </button>
                    @if (!$this->allReady)
                        <span class="text-xs font-mono text-typing-muted self-center">{{ __('multiplayer.waiting_ready') }}</span>
                    @endif
                @else
                    <button wire:click="toggleReady"
                        class="px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? 'bg-active text-background hover:bg-active-5' : 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black' }}">
                        {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? __('multiplayer.im_not_ready') : __('multiplayer.im_ready') }}
                    </button>
                @endif

                <button wire:click="leaveRoom"
                    class="px-6 py-3 bg-transparent border border-white/10 text-typing-muted hover:text-typing-text hover:bg-white/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    {{ __('multiplayer.leave_room') }}
                </button>
            </div>
        </div>
    @endif

    <!-- ===== 3. HALAMAN ARENA PERTANDINGAN: BATTLE STAGE (TYPERACER MECHANICS) ===== -->
    @if ($this->step === 'racing' && $this->roomData && !$showResultModal)
        {{-- wire:key stabil: menjaga elemen (& state Alpine x-data) tetap sama lintas re-render/poll, supaya raceStarted/countdown/progress tak reset. --}}
        {{-- Logika Alpine ada di komponen terdaftar 'raceArena' (lihat @script) — bukan langsung di x-data, karena JS besar berisi //, {}, <, > bisa merusak parsing atribut HTML. x-data di sini hanya memanggil fungsi + kirim data server via @js(). --}}
        {{-- Sudden death disinkron via WebSocket .race.sudden_death + clock lokal, bukan wire:poll; saat clock lokal capai 0, lockRace() memanggil checkSuddenDeath() sekali (idempoten). --}}
        <div wire:key="race-arena-{{ $this->roomCode }}" class="space-y-8"
            x-data="raceArena({
                myId: @js(Auth::id()),
                textToType: @js($this->roomData->text_to_type),
                raceStartsAt: @js($this->raceStartsAt),
                suddenDeathActive: @js($this->suddenDeathActive),
                suddenDeathRemaining: @js($this->suddenDeathRemaining),
            })">

            <!-- BANNER SUDDEN DEATH TIMER -->
            @if ($this->suddenDeathActive)
                <div
                    x-init="syncSuddenDeath(@js($this->suddenDeathRemaining))"
                    class="p-3 bg-amber-950/40 border border-amber-700/50 rounded-2xl text-center animate-pulse flex items-center justify-center gap-2">
                    <span class="text-amber-400 font-mono text-sm uppercase tracking-wider font-bold">{{ __('multiplayer.sudden_death') }}</span>
                    <span
                        class="text-xl font-mono font-black text-white bg-amber-600 px-3 py-0.5 rounded-lg"
                        x-text="suddenDeathRemaining + 's'"></span>
                </div>
            @endif

            <!-- OVERLAY COUNTDOWN SCREEN: hanya untuk start race; guard !suddenDeathActive agar tak muncul lagi saat countdown sudden death -->
            <template x-if="!raceStarted && !suddenDeathActive">
                <div class="fixed inset-0 bg-typing-bg/95 flex flex-col items-center justify-center z-50 select-none">
                    <span class="font-mono text-xs uppercase tracking-[0.4em] text-typing-muted mb-4">{{ __('multiplayer.race_starting') }}</span>
                    <div class="text-fluid-hero font-mono font-black tracking-wider text-[#cbb38a] scale-110 transition-all duration-300"
                        x-text="countdown"></div>
                </div>
            </template>

            <!-- VISUALISASI ARENA BALAPAN MASKOT UETYPE -->
            <div class="p-6 border bg-typing-surface/50 border-white/5 rounded-3xl space-y-4 shadow-xl">
                <span class="text-xs font-mono uppercase tracking-widest text-typing-muted block mb-2">{{ __('multiplayer.mascot_track') }}</span>

                <div class="space-y-3 bg-black/30 p-4 rounded-2xl border border-white/[0.02] divide-y divide-white/5">
                    @foreach ($this->roomData->members as $player)
                        @php
                            $isSelf = $player->user_id === Auth::id();
                        @endphp
                        {{-- Semua lane (termasuk diri sendiri) baca $store.race.opponents[id] secara seragam — diisi langsung dari payload WebSocket .race.progress, tanpa re-render Livewire. raceArena mem-publish progress lokal ke store yang sama (publishLocal()) agar tak bergantung pada `this` komponen induk. Nilai Blade = seed awal/fallback. --}}
                        <div class="pt-3 first:pt-0"
                            x-data="{
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
                            }">
                            <div class="flex justify-between items-center mb-1 text-xs font-mono">
                                <span
                                    class="{{ $isSelf ? 'text-[#cbb38a] font-bold' : 'text-typing-muted' }}">
                                    {{ $player->user->username }}
                                    @if ($player->user_id === $this->roomData->host_id)
                                        <span class="text-[10px] text-zinc-500">[{{ __('multiplayer.host') }}]</span>
                                    @endif
                                    <span class="text-active font-bold ml-1" x-show="liveFinished" x-cloak>{{ __('multiplayer.finished') }}</span>
                                </span>
                                <span class="font-mono text-[#cbb38a] font-bold"><span x-text="liveWpmValue"></span> WPM</span>
                            </div>

                            <div
                                class="h-10 w-full bg-typing-bg/80 rounded-xl relative border border-white/5 overflow-hidden flex items-center">
                                <div
                                    class="absolute right-0 top-0 bottom-0 w-8 bg-zinc-900 border-l border-dashed border-white/20 flex items-center justify-center font-mono text-[10px] text-zinc-600 select-none">
                                    {{ __('multiplayer.finish') }}</div>

                                <div class="h-full bg-white/[0.02] transition-all duration-300 flex items-center justify-end relative"
                                    :style="`width: calc(10% + ${liveProgress}% * 0.85);`">
                                    <div
                                        class="w-8 h-8 flex items-center justify-center animate-bounce transition-all duration-200">
                                        <img src="/icon/uetype_mascot.png" alt="Player Maskot"
                                            class="w-full h-full object-contain">
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- CONTAINER UTAMA TEKS (VISUAL HIGH-RESPONSIVE TYPERACER STYLE) -->
            <div class="p-8 border bg-typing-surface/40 border-white/5 rounded-3xl space-y-6 shadow-xl">
                <div class="flex justify-between items-center border-b border-white/5 pb-4">
                    <span class="text-xs font-mono uppercase tracking-widest text-typing-muted">{{ __('multiplayer.arena_mode') }}</span>
                    <span class="text-xs font-mono text-[#cbb38a]">{{ __('multiplayer.room_code') }} <strong
                            class="text-white">{{ $this->roomCode }}</strong></span>
                </div>

                <!-- BLOK DRAF PARAGRAF DENGAN INDIKATOR WARNA TYPERACER -->
                <div
                    class="font-mono text-xl leading-relaxed tracking-wide select-none p-5 bg-black/20 rounded-xl border border-white/[0.02] flex flex-wrap gap-x-2 gap-y-1">
                    <template x-for="(word, wIdx) in words" :key="wIdx">
                        <span
                            :class="{
                                'text-active': wIdx < currentWordIndex && !wordHadError[wIdx],
                                'text-amber-500/80 underline underline-offset-4 decoration-2 decoration-amber-500/50': wIdx <
                                    currentWordIndex && wordHadError[wIdx],
                                'text-red-400 bg-red-950/40 ring-1 ring-red-500/30 px-1 rounded underline underline-offset-4 decoration-2': wIdx ===
                                    currentWordIndex && hasError,
                                'text-white font-bold ring-1 ring-white/10 bg-white/5 px-1 rounded': wIdx ===
                                    currentWordIndex && !hasError,
                                'text-zinc-500': wIdx > currentWordIndex
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
                            'border-red-500/60 focus:ring-red-500 focus:border-red-500 bg-red-950/10 text-red-200': hasError,
                            'focus:ring-1 focus:ring-[#cbb38a] focus:border-[#cbb38a] border-white/10 text-white': !
                                hasError
                        }"
                        class="w-full px-5 py-4 bg-typing-bg border rounded-xl font-mono text-base transition-all duration-200 placeholder-zinc-600 disabled:opacity-40 disabled:cursor-not-allowed" />
                </div>

                <div class="pt-4 flex justify-end">
                    <button wire:click="leaveRoom"
                        class="px-5 py-2.5 bg-red-950/20 border border-red-900/30 text-red-400 hover:bg-red-950/40 font-mono text-xs font-bold uppercase tracking-wider rounded-xl transition">
                        {{ __('multiplayer.give_up') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ===== 4. HALAMAN: MATCH RESULT ===== -->
    @if ($showResultModal && $this->roomData)
        <div class="space-y-12 animate-fade-in py-4 select-none">

            <!-- HEADER MATCH RESULT -->
            <div class="flex flex-col space-y-1">
                <h1 class="text-fluid-title font-mono font-black text-[#cbb38a] tracking-wider uppercase">{{ __('multiplayer.match_result') }}</h1>
                @php
                    $myRank = $this->leaderboardData->search(fn($m) => $m->user_id === Auth::id()) + 1;
                    $suffix = match ($myRank) {
                        1 => 'st',
                        2 => 'nd',
                        3 => 'rd',
                        default => 'th',
                    };
                @endphp
                <div class="flex items-center gap-2 text-xs font-mono text-typing-muted uppercase tracking-widest">
                    <span>{{ __('multiplayer.room', ['code' => $this->roomCode]) }}</span>
                    <span class="text-white/20">•</span>
                    <span>{!! __('multiplayer.you_placed', ['rank' => '<strong class="text-white font-bold">'.$myRank.(app()->getLocale() === 'en' ? $suffix : '').'</strong>']) !!}</span>
                </div>
            </div>

            <!-- VISUAL PODIUM 3 TERATAS -->
            @php
                $rank1 = $this->leaderboardData->get(0);
                $rank2 = $this->leaderboardData->get(1);
                $rank3 = $this->leaderboardData->get(2);
            @endphp
            <div class="grid grid-cols-3 gap-4 items-end max-w-2xl mx-auto pt-16 pb-6 relative">

                <!-- PODIUM 2 (KIRI) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank2)
                        <div class="text-center font-mono text-xs">
                            <span class="text-typing-muted block text-[10px]">{{ $rank2->wpm }} wpm</span>
                            <span
                                class="text-white font-bold block truncate max-w-[100px]">{{ $rank2->user->username }}</span>
                            @if ($rank2->user_id === Auth::id())
                                <span
                                    class="inline-block bg-[#cbb38a] text-black text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <div class="w-10 h-10 animate-bounce">
                            <img src="/icon/uetype_mascot.png" class="w-full h-full object-contain">
                        </div>
                    @endif
                    <div
                        class="w-full h-20 bg-transparent border-2 border-white/10 rounded-2xl flex items-center justify-center font-mono font-black text-3xl text-white/30">
                        2
                    </div>
                </div>

                <!-- PODIUM 1 (TENGAH) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank1)
                        <div class="text-center font-mono text-xs">
                            <span class="text-typing-muted block text-[10px]">{{ $rank1->wpm }} wpm</span>
                            <span
                                class="text-white font-bold block truncate max-w-[120px]">{{ $rank1->user->username }}</span>
                            @if ($rank1->user_id === Auth::id())
                                <span
                                    class="inline-block bg-black text-[#cbb38a] text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <div class="w-12 h-12 animate-bounce" style="animation-duration: 2.2s;">
                            <img src="/icon/uetype_mascot.png" class="w-full h-full object-contain">
                        </div>
                    @endif
                    <div
                        class="w-full h-32 bg-[#cbb38a] rounded-2xl flex items-center justify-center font-mono font-black text-3xl sm:text-5xl text-black shadow-lg">
                        1
                    </div>
                </div>

                <!-- PODIUM 3 (KANAN) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank3)
                        <div class="text-center font-mono text-xs">
                            <span class="text-typing-muted block text-[10px]">{{ $rank3->wpm }} wpm</span>
                            <span
                                class="text-white font-bold block truncate max-w-[100px]">{{ $rank3->user->username }}</span>
                            @if ($rank3->user_id === Auth::id())
                                <span
                                    class="inline-block bg-[#cbb38a] text-black text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <div class="w-10 h-10 animate-bounce" style="animation-duration: 1.8s;">
                            <img src="/icon/uetype_mascot.png" class="w-full h-full object-contain">
                        </div>
                    @endif
                    <div
                        class="w-full h-16 bg-transparent border-2 border-[#cbb38a]/20 rounded-2xl flex items-center justify-center font-mono font-black text-2xl text-[#cbb38a]/30">
                        3
                    </div>
                </div>

            </div>

            <!-- TABEL FULL RESULTS -->
            <div class="space-y-3">
                <span class="text-[11px] font-mono uppercase tracking-[0.25em] text-typing-muted block mb-1">{{ __('multiplayer.full_results') }}</span>
                <div
                    class="w-full border border-white/5 rounded-2xl overflow-x-auto bg-typing-surface/10 backdrop-blur-sm">
                    <table class="w-full text-left font-mono text-sm border-collapse">
                        <thead>
                            <tr
                                class="border-b border-white/5 bg-black/20 text-xs text-typing-muted uppercase tracking-wider">
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_place') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_player') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_wpm') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_accuracy') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_time') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.03]">
                            @foreach ($this->leaderboardData as $index => $rank)
                                @php
                                    $pos = $index + 1;
                                    $suffix = match ($pos) {
                                        1 => 'st',
                                        2 => 'nd',
                                        3 => 'rd',
                                        default => 'th',
                                    };
                                    $isMe = $rank->user_id === Auth::id();
                                @endphp
                                <tr
                                    class="transition duration-150 {{ $isMe ? 'bg-blue-950/40 text-white font-bold' : 'text-typing-muted hover:bg-white/[0.01]' }}">
                                    <td class="py-4 px-3 sm:px-5 font-bold text-white">{{ $pos }}{{ $suffix }}
                                    </td>
                                    <td class="py-4 px-5">
                                        <div class="flex items-center gap-2">
                                            <span>{{ $rank->user->username }}</span>
                                            @if ($isMe)
                                                <span
                                                    class="bg-blue-500 text-white text-[9px] font-black px-1 py-0.1 rounded uppercase tracking-wide">{{ __('multiplayer.you') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-4 px-3 sm:px-5 text-[#cbb38a] font-bold">{{ $rank->wpm }} wpm</td>
                                    <td class="py-4 px-5">{{ $rank->accuracy ?? 97.0 }}%</td>
                                    <td class="py-4 px-5">
                                        @if ($rank->finished_time_seconds && $rank->finished_time_seconds != 999)
                                            {{ sprintf('%02d:%02d', floor($rank->finished_time_seconds / 60), $rank->finished_time_seconds % 60) }}
                                        @else
                                            {{-- DNF: jangan tampilkan waktu palsu. --}}
                                            <span class="text-red-400/70 text-xs">DNF</span>
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
                <div class="p-5 border border-white/5 bg-typing-surface/30 rounded-2xl space-y-2">
                    <div class="flex justify-between items-center text-xs font-mono">
                        <div class="flex flex-col">
                            <span class="text-typing-muted text-[10px] uppercase tracking-wide">{{ __('multiplayer.xp_earned') }}</span>
                            <span class="text-2xl font-black text-[#cbb38a] mt-0.5">+{{ number_format($xp['earned']) }} XP</span>
                        </div>
                        <div class="text-right flex flex-col items-end">
                            <span class="text-white font-bold text-xs">{{ number_format($xpProgress) }} / {{ number_format($xpNeeded) }} XP</span>
                            <span class="text-typing-muted text-[10px] mt-0.5">Level {{ $lvl['level'] }} <span
                                    class="text-white/30">→</span> {{ $lvl['next_level'] }}</span>
                        </div>
                    </div>
                    <div class="h-1.5 w-full bg-black/40 rounded-full overflow-hidden border border-white/[0.03]">
                        <div class="h-full bg-blue-600 rounded-full transition-all duration-500"
                            style="width: {{ $xpBarWidth }}%"></div>
                    </div>
                </div>
            @endif

            <!-- AKSI BUTTON MENU BAWAH -->
            <div class="pt-2 flex flex-wrap gap-3 sm:gap-4">
                @if ($this->isHost)
                    <button wire:click="playAgain"
                        class="px-6 py-3 bg-[#cbb38a] hover:bg-[#bfa57a] text-black font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                        {{ __('multiplayer.play_again') }}
                    </button>
                @endif
                <button wire:click="leaveRoom"
                    class="px-6 py-3 bg-transparent border border-white/10 text-typing-muted hover:text-typing-text hover:bg-white/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    {{ __('multiplayer.leave_room') }}
                </button>
            </div>

        </div>
    @endif

    {{-- @assets (bukan @script): @script membungkus <script> jadi atribut wire:effects, dan kode dengan banyak '<'/'>' (mis. `wIdx < currentWordIndex`) merusak parsing DOM Livewire. @assets menyuntik script apa adanya ke <head> sekali saja sebelum Alpine init, aman untuk kode besar. --}}
    @assets
        <script>
            // Komponen Alpine 'raceArena': semua logika typing + sudden death di sini, bukan di x-data, agar JS tak bocor ke HTML.
            // registerRaceArena() idempoten (flag global); didaftarkan baik saat Alpine sudah booting maupun via event 'alpine:init'.
            const registerRaceArena = (Alpine) => {
                if (window.__raceArenaRegistered) return;
                window.__raceArenaRegistered = true;

                // Store global 'race': posisi maskot lawan, diisi dari payload WebSocket .race.progress.
                // Store (bukan state komponen) agar bertahan lintas Livewire morph. Bentuk: opponents = { [userId]: { progress, wpm, finished } }.
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
                    // Waktu absolut (ms epoch) race mulai, dari server — countdown dihitung mundur ke titik ini agar sinkron antar layar.
                    raceStartsAtMs: config.raceStartsAt ? new Date(config.raceStartsAt).getTime() : null,
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
                    // true kalau kata itu dilewati salah/belum lengkap (space tanpa exact match) — untuk highlight visual riwayat error per kata.
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

                        // Bersihkan posisi lawan dari race sebelumnya (mis. Play Again). Kalau sudden death aktif ini re-init di tengah race yang sama -> jangan hapus.
                        if (!this.suddenDeathActive && this.$store.race) {
                            this.$store.race.reset();
                        }

                        // Sudden death sudah aktif saat (re)init berarti race sudah berjalan -> skip overlay countdown, langsung anggap race berjalan.
                        if (this.suddenDeathActive) {
                            this.raceStarted = true;
                            this.countdown = 'GO!';
                            this.startTime = Date.now();
                            this.startSuddenDeathClock();
                            this.$nextTick(() => {
                                if (this.$refs.typeInput) this.$refs.typeInput.focus();
                            });
                        } else {
                            // Overlay hitung mundur ke race_starts_at (server) — waktu absolut sama di semua layar, tanpa delay antar host & peserta.
                            this.startSyncedCountdown();
                        }

                        // Sinyal server saat room ditutup paksa -> kunci total.
                        this.$wire.on('force-finish', () => this.lockRace());

                        // Bridge event window .race.sudden_death -> sinkronkan hitung mundur komponen ini. Disimpan agar bisa di-remove saat destroy.
                        this._onSuddenDeath = (ev) => this.syncSuddenDeath(ev.detail.remaining);
                        window.addEventListener('race-sudden-death', this._onSuddenDeath);
                    },

                    // Hitung mundur berbasis waktu absolut server (race_starts_at), bukan interval lokal, agar semua layar sinkron.
                    startSyncedCountdown() {
                        const tick = () => {
                            // Fallback jika server tidak mengirim raceStartsAt.
                            if (!this.raceStartsAtMs) {
                                this.beginRace();
                                return;
                            }

                            const remainingMs = this.raceStartsAtMs - Date.now();

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

                    // startTime dipatok ke race_starts_at server (kalau ada) agar perhitungan WPM antar pemain pakai titik awal yang sama.
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

                    // Sinkronkan sisa waktu dari server & pastikan clock lokal jalan. Dipanggil dari x-init banner dan event .race.sudden_death.
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

                        // Minta server memfinalisasi sekali; checkSuddenDeath() idempoten jadi aman dipanggil beberapa klien bersamaan.
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

                    // Publikasikan posisi lokal ke store dengan key userId sendiri, agar lane sendiri baca sumber yang sama dengan lawan -> gerak seragam.
                    publishLocal(progress, wpm, finished) {
                        if (this.myId == null) return;
                        this.$store.race.apply(this.myId, {
                            progress_percent: progress,
                            wpm: wpm,
                            finished: finished,
                        });
                    },

                    // Kirim progress ke server dengan throttle ~120ms (trailing-edge flush menjaga posisi terakhir tak hilang); `force` selalu segera.
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

                    // Permisif seperti Solo: spasi selalu pindah ke kata berikutnya (tak pernah mengunci di kata salah); sisa huruf salah/belum diketik dicatat sebagai mistake tapi progres tetap jalan.
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
                            // Huruf ekor yang belum diketik dihitung sbg mistake tambahan; huruf yang sudah diketik sudah dihitung di checkInput(), tak dobel di sini.
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
