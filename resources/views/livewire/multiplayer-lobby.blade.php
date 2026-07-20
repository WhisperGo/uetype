<div class="max-w-5xl px-4 mx-auto py-6 sm:px-6 lg:px-8 text-foreground">
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
                            x-on:keydown.enter.prevent="syncBoxes(); $wire.joinRoom()"
                            x-on:keydown.backspace="backspace($event)"
                            x-on:keyup="if($event.key !== 'Backspace' && $el.value.length == 1 && {{ $index }} < 5) { $el.nextElementSibling.focus() }" />
                    @endforeach
                </div>

                <button type="button" x-on:click="syncBoxes(); $wire.joinRoom()"
                    class="px-8 py-3 border border-border/40 text-foreground hover:bg-foreground/5 font-mono font-semibold uppercase tracking-wider rounded-xl transition duration-200">
                    {{ __('multiplayer.join_room') }}
                </button>

                @if (session()->has('error'))
                    <p class="mt-4 flex items-center justify-center gap-1.5 font-mono text-xs text-danger">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                        </svg>
                        <span>{{ session('error') }}</span>
                    </p>
                @endif
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
                <div class="flex justify-between items-center mb-4 gap-3">
                    <h3 class="text-xs uppercase tracking-widest text-muted font-mono font-bold">{{ __('multiplayer.players') }}</h3>
                    <div class="flex items-center gap-3">
                        @if ($this->spectatorCount > 0)
                            <div class="relative" x-data="{ open: false }">
                                <button type="button" x-on:click="open = !open" x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-border/40 bg-foreground/5 hover:bg-foreground/10 transition">
                                    <span class="text-sm leading-none">&#128065;</span>
                                    <span class="text-[11px] font-mono font-bold text-muted">{{ __('multiplayer.spectators_watching', ['count' => $this->spectatorCount]) }}</span>
                                </button>
                                <div x-show="open" x-cloak x-transition.opacity
                                    x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                                    class="absolute right-0 z-30 mt-2 w-56 p-3 rounded-xl border border-border/40 bg-elevated shadow-xl">
                                    <span class="block text-[10px] font-mono uppercase tracking-widest text-muted mb-2">{{ __('multiplayer.spectator_list_title') }}</span>
                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                        @foreach ($this->spectators as $spectator)
                                            <div class="flex items-center gap-2">
                                                <x-friend-avatar :user="$spectator->user" size="w-6 h-6" shape="rounded-md"
                                                    bg="bg-foreground/5" :bordered="false" fallback-size="w-4/5 h-4/5" />
                                                <span class="font-mono text-xs text-foreground/90 truncate">{{ $spectator->user->username }}</span>
                                                @if ($spectator->user_id === $this->roomData->host_id)
                                                    <span class="text-[9px] font-mono font-bold uppercase tracking-wider text-gold shrink-0">{{ __('multiplayer.host') }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                        <span class="text-xs font-mono text-gold font-bold">{{ __('multiplayer.joined', ['count' => $this->orderedMembers->count()]) }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                    @foreach (range(0, 4) as $i)
                        @php
                            $member = $this->orderedMembers->get($i);
                        @endphp
                        @if ($member)
                            <div
                                class="p-5 border flex flex-col items-center justify-center text-center rounded-2xl relative transition duration-300 {{ $member->user_id === Auth::id() ? 'bg-elevated/60 border-brand-bright' : 'bg-surface/40 border-border/40' }}">
                                <x-friend-avatar :user="$member->user" size="w-14 h-14" shape="rounded-xl"
                                    bg="bg-foreground/5" :bordered="false" fallback-size="w-4/5 h-4/5" class="mb-3" />
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
                        style="width: {{ ($this->orderedMembers->count() / \App\Livewire\MultiplayerLobby::MAX_PLAYERS) * 100 }}%"></div>
                </div>
            </div>

            @php
                $playersFull = $this->orderedMembers->count() >= \App\Livewire\MultiplayerLobby::MAX_PLAYERS;
                $spectatorsFull = $this->spectatorCount >= \App\Livewire\MultiplayerLobby::MAX_SPECTATORS;
            @endphp

            <div class="pt-6 border-t border-border/30 space-y-3">
                {{-- Baris tombol: aksi primer di kiri, aksi sekunder didorong ke kanan. --}}
                <div class="flex flex-wrap items-center justify-between gap-3 sm:gap-4">
                    {{-- KIRI: aksi primer sesuai peran (Mulai / Siap / badge Menonton). --}}
                    <div class="flex flex-wrap items-center gap-3 sm:gap-4">
                        @if ($this->isHost)
                            <button wire:click="startRace" @disabled(!$this->allReady)
                                class="px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->allReady ? 'bg-gold hover:bg-secondary-7 text-background shadow-md' : 'bg-elevated text-muted cursor-not-allowed border border-border/30' }}">
                                {{ __('multiplayer.start_race') }}
                            </button>
                        @elseif (!$this->isSpectator)
                            @php $meReady = $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready; @endphp
                            <button wire:click="toggleReady"
                                class="px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $meReady ? 'bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5' : 'bg-gold hover:bg-secondary-7 text-background' }}">
                                {{ $meReady ? __('multiplayer.cancel_ready') : __('multiplayer.im_ready') }}
                            </button>
                        @else
                            <span class="inline-flex items-center gap-2 px-5 py-3 rounded-xl border border-border/40 bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider text-muted">
                                <span class="text-base leading-none">&#128065;</span>{{ __('multiplayer.you_are_spectating') }}
                            </span>
                        @endif
                    </div>

                    {{-- KANAN: aksi sekunder (toggle peran + keluar). Toggle tersedia untuk
                         semua termasuk host, hanya saat waiting. --}}
                    <div class="flex flex-wrap items-center gap-3 sm:gap-4">
                        @if ($this->isSpectator)
                            <button wire:click="toggleSpectator" @disabled($playersFull)
                                class="px-6 py-3 bg-transparent border border-gold/50 text-gold hover:bg-gold/10 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed"
                                @if ($playersFull) title="{{ __('multiplayer.players_full') }}" @endif>
                                {{ __('multiplayer.become_player') }}
                            </button>
                        @else
                            <button wire:click="toggleSpectator" @disabled($spectatorsFull)
                                class="px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed"
                                @if ($spectatorsFull) title="{{ __('multiplayer.spectators_full') }}" @endif>
                                {{ __('multiplayer.become_spectator') }}
                            </button>
                        @endif

                        <button wire:click="leaveRoom"
                            class="px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                            {{ __('multiplayer.leave_room') }}
                        </button>
                    </div>
                </div>

                {{-- Baris hint status: menjelaskan kenapa "Mulai" belum aktif. Titik netral
                     berdenyut agar terbaca sebagai petunjuk, bukan tombol. --}}
                @if ($this->isHost && !$this->allReady)
                    <div class="flex items-center gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-muted animate-pulse"></span>
                        <span class="text-xs font-mono text-muted">
                            {{ $this->orderedMembers->isEmpty() ? __('multiplayer.no_players_to_start') : __('multiplayer.waiting_ready') }}
                        </span>
                    </div>
                @endif
            </div>

            {{-- Chat lobby: player & spectator bisa mengobrol sambil menunggu. wire:key
                 stabil agar state Alpine (daftar pesan) tak reset saat lobby re-render. --}}
            <div wire:key="room-chat-waiting">
                @include('livewire.partials.room-chat', ['currentUserId' => auth()->id()])
            </div>
        </div>
    @endif

    <!-- ===== 3. HALAMAN ARENA PERTANDINGAN: BATTLE STAGE (TYPERACER MECHANICS) ===== -->
    @if ($this->step === 'racing' && $this->roomData && !$showResultModal)
        {{-- wire:key stabil: state Alpine (raceStarted/countdown/progress) tak reset lintas re-render. --}}
        {{-- Logika Alpine ada di komponen 'raceArena' (lihat @assets), bukan inline di x-data. --}}
        {{-- Sudden death disinkron via WebSocket + clock lokal; saat 0, lockRace() panggil checkSuddenDeath() sekali. --}}
        {{-- Penonton ikut render arena (countdown + lane pembalap), tapi tanpa input ketik.
             myId=null memberi tahu raceArena untuk melewati semua jalur emit/publish lokal. --}}
        @php
            $isSpectator = $this->isSpectator;
            $racers = $this->orderedMembers;
        @endphp
        @php $arenaDense = $racers->count() >= 4; @endphp
        {{-- Sembunyikan overlay chat selama arena balapan tampil; kembalikan saat blok ini
             hilang (race selesai / result modal / keluar room). --}}
        <div x-data="{ init() { window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: true } })); }, destroy() { window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } })); } }"></div>
        <div wire:key="race-arena-{{ $this->roomCode }}" class="{{ $arenaDense ? 'space-y-4' : 'space-y-6' }}"
            x-data="raceArena({
                myId: @js($isSpectator ? null : Auth::id()),
                isSpectator: @js($isSpectator),
                roomCode: @js($this->roomCode),
                textToType: @js($this->roomData->text_to_type),
                raceStartsAt: @js($this->raceStartsAt),
                raceStartsInMs: @js($this->raceStartsInMs),
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
                <span class="flex items-center gap-3 shrink-0">
                    @if ($this->spectatorCount > 0)
                        <span class="flex items-center gap-1.5 font-mono text-[11px] text-muted">
                            <span class="text-sm leading-none">&#128065;</span>{{ __('multiplayer.spectators_watching', ['count' => $this->spectatorCount]) }}
                        </span>
                    @endif
                    <span class="font-mono text-[11px] uppercase tracking-widest text-muted">
                        {{ __('multiplayer.room_label') }} <span class="text-gold font-bold">- {{ $this->roomCode }}</span>
                    </span>
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
                $playerCount = $racers->count();
                $dense = $playerCount >= 4;

                // Progres awal tiap pemain, dipakai rankOf() untuk memeringkat pemain
                // yang belum sekali pun mengirim payload WebSocket. Hanya pembalap.
                $laneSeeds = $racers
                    ->mapWithKeys(fn ($m) => [$m->user_id => (int) ($m->progress_percent ?? 0)]);
            @endphp

            <!-- KLASEMEN LANGSUNG: LANE PER PEMAIN -->
            <div class="border bg-surface/50 border-border/40 rounded-3xl shadow-xl {{ $dense ? 'p-4 space-y-2' : 'p-6 space-y-3' }}">
                <span class="text-xs font-mono uppercase tracking-widest text-muted block">{{ __('multiplayer.live_standings') }}</span>

                {{-- laneSeeds/raceStartMs/textLength dideklarasikan sekali di sini, lalu
                     diwarisi tiap x-data lane. Jam bersama dijalankan sekali juga, supaya
                     WPM tiap lane dihitung ulang tiap detik tanpa satu timer per pemain. --}}
                <div x-data="{
                        laneSeeds: @js($laneSeeds),
                        textLength: @js(mb_strlen($this->roomData->text_to_type)),
                        {{-- raceStartsInMs = sisa waktu menurut server saat render (negatif
                             kalau balapan sudah jalan). Ditambahkan ke Date.now() supaya titik
                             mulainya berada di jam KLIEN -- kebal selisih jam server-klien.
                             null = balapan belum dijadwalkan. --}}
                        raceStartsInMs: @js($this->raceStartsInMs),
                        raceStartMs: null,
                    }"
                    x-init="
                        raceStartMs = raceStartsInMs === null ? null : Date.now() + raceStartsInMs;
                        $store.race.startClock();
                    "
                    class="bg-background/40 rounded-2xl border border-border/20 {{ $dense ? 'p-3 space-y-1' : 'p-4 space-y-1.5' }}">
                    @foreach ($racers as $player)
                        @php $isSelf = ! $isSpectator && $player->user_id === Auth::id(); @endphp
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
                                /**
                                 * WPM dihitung SENDIRI oleh tiap penonton, bukan menunggu
                                 * kiriman pemiliknya. Tab lawan yang tidak aktif dibekukan
                                 * browser, jadi lawan yang berhenti mengetik takkan pernah
                                 * menyiarkan WPM-nya yang meluruh -- angkanya akan macet.
                                 *
                                 * WPM = (karakter benar / 5) / menit berlalu, dan karakter
                                 * benar diturunkan dari progres yang memang disiarkan.
                                 * Pemain yang sudah finis dibekukan di angka terakhirnya.
                                 */
                                get liveWpmValue() {
                                    const reported = $store.race.opponents[this.playerId]?.wpm ?? this.seedWpm;

                                    // Pemain yang sudah finis: WPM final dibekukan, tak meluruh lagi.
                                    // raceStartMs null: balapan belum mulai, tak ada waktu berlalu.
                                    if (this.liveFinished || raceStartMs === null) return reported;

                                    // $store.race.now membuat getter ini dihitung ulang tiap detik.
                                    const minutes = ($store.race.now - raceStartMs) / 60000;
                                    if (minutes <= 0) return reported;

                                    // progress_percent bilangan bulat, jadi karakter benar di sini
                                    // dibulatkan ke ~1% teks -- semua lane (termasuk milik sendiri)
                                    // memakai rumus yang sama supaya angkanya konsisten antar layar.
                                    const correctChars = (this.liveProgress / 100) * textLength;

                                    return Math.floor((correctChars / 5) / minutes);
                                },
                                get liveFinished() {
                                    return $store.race.opponents[this.playerId]?.finished ?? this.seedFinished;
                                },
                                get isLeader() {
                                    return String($store.race.leaderId()) === String(this.playerId) && this.liveProgress > 0;
                                },
                                get liveRank() {
                                    return $store.race.rankOf(this.playerId, laneSeeds);
                                },
                                get runnerTilt() {
                                    const w = Math.min(this.liveWpmValue, 120);
                                    return `rotate(${(w / 120) * -8}deg) scale(${1 + (w / 120) * 0.12})`;
                                },
                            }"
                            {{-- Lane pemain sendiri disorot penuh (kartu biru), seperti di desain. --}}
                            class="flex items-center rounded-xl transition-colors duration-300 {{ $dense ? 'gap-3 py-1.5' : 'gap-4 py-2' }} {{ $isSelf ? 'bg-brand/25 border border-gold/70 px-3' : 'border border-transparent px-3' }}">

                            {{-- Peringkat hidup. Pemain yang sudah finis ditandai hijau
                                 (desain ini tak punya badge "FINISHED" terpisah). --}}
                            <div class="shrink-0 rounded-md border flex items-center justify-center font-mono font-bold transition-colors duration-300 {{ $dense ? 'w-6 h-6 text-[10px]' : 'w-7 h-7 text-xs' }}"
                                :class="liveFinished
                                    ? 'border-active/70 bg-active/15 text-active'
                                    : 'border-gold/70 bg-gold/10 text-gold'"
                                x-text="liveRank"></div>

                            {{-- Nama: lebar tetap supaya semua lintasan mulai di x yang sama. --}}
                            <div class="shrink-0 flex items-center gap-2 font-mono {{ $dense ? 'w-32' : 'w-40' }}">
                                <span class="truncate {{ $dense ? 'text-xs' : 'text-sm' }} {{ $isSelf ? 'text-foreground font-bold' : 'text-foreground/90' }}">{{ $player->user->username }}</span>
                                @if ($isSelf)
                                    <span class="shrink-0 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-gold text-background">{{ __('multiplayer.you') }}</span>
                                @endif
                            </div>

                            {{-- Lintasan: garis tipis + maskot yang menungganginya + bendera finis.
                                 Bendera ADA DI DALAM lintasan (absolute, kanan), bukan elemen sebelahnya,
                                 supaya progres 100% benar-benar mendarat di atasnya. --}}
                            @php
                                // Setengah lebar maskot: dipakai sebagai padding kiri-kanan lintasan supaya
                                // maskot (yang di-center pada titik progres) tak terpotong di 0% maupun 100%.
                                $half = $dense ? 12 : 14;
                            @endphp
                            <div class="relative flex-1 min-w-0 flex items-center {{ $dense ? 'h-8' : 'h-10' }}">

                                {{-- Rel: disisipkan $half px di kiri & kanan supaya maskot (yang di-center
                                     pada titik progres) tak terpotong di 0% maupun 100%. --}}
                                <div class="absolute rounded-full bg-elevated {{ $dense ? 'h-1' : 'h-1.5' }}"
                                    style="left: {{ $half }}px; right: {{ $half }}px;"></div>

                                <div class="absolute rounded-full transition-all duration-300 {{ $dense ? 'h-1' : 'h-1.5' }}"
                                    :class="liveFinished ? 'bg-active' : 'bg-gold'"
                                    :style="`left: {{ $half }}px; width: calc((100% - {{ $half * 2 }}px) * ${liveProgress} / 100);`"></div>

                                {{-- Bendera finis: tepat di ujung kanan rel (titik 100%). --}}
                                <div class="race-finish-flag absolute top-0 bottom-0 -translate-x-1/2 rounded-sm {{ $dense ? 'w-2.5' : 'w-3' }}"
                                    style="left: calc(100% - {{ $half }}px);"
                                    role="img" aria-label="{{ __('multiplayer.finish') }}"></div>

                                {{-- Maskot di-center pada titik progres (-translate-x-1/2), jadi di 100%
                                     titik tengahnya persis di atas bendera. z-10 supaya tak tertutup bendera. --}}
                                <div class="absolute top-1/2 z-10 -translate-x-1/2 -translate-y-1/2 transition-all duration-300"
                                    :style="`left: calc({{ $half }}px + (100% - {{ $half * 2 }}px) * ${liveProgress} / 100);`">
                                    {{-- Sengaja TIDAK memakai <x-friend-avatar>: wadah ini membawa binding
                                         Alpine (:style transform, :class) dan tak punya wrapper relative,
                                         sedangkan komponennya selalu membungkus dengan `relative shrink-0` --
                                         itu akan merusak penempatan absolut pelari di lintasan. --}}
                                    <div class="race-runner flex items-center justify-center {{ $dense ? 'w-6 h-6' : 'w-7 h-7' }}"
                                        :style="`transform: ${runnerTilt}`"
                                        :class="{ 'opacity-70': liveProgress === 0 && !liveFinished }">
                                        @if ($player->user->avatar)
                                            <img src="{{ $player->user->avatar }}" alt="{{ $player->user->username }}" referrerpolicy="no-referrer" class="w-full h-full object-cover rounded-md">
                                        @else
                                            <img src="/icon/uetype_mascot.png" alt="{{ $player->user->username }}" class="w-full h-full object-contain">
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- WPM --}}
                            <div class="shrink-0 text-right font-mono {{ $dense ? 'w-14 text-[11px]' : 'w-16 text-xs' }}">
                                <span class="font-bold text-foreground" x-text="liveWpmValue"></span>
                                <span class="text-muted"> wpm</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if (! $isSpectator && ! $hasGivenUp && ! $hasFinished)
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
                <!-- LAYAR TUNGGU: PENONTON, ATAU PEMAIN YANG SUDAH SELESAI / MENYERAH -->
                @php
                    $watchTitle = $isSpectator ? __('multiplayer.spectating_title') : ($hasGivenUp ? __('multiplayer.gave_up_title') : __('multiplayer.finished_title'));
                    $watchDesc = $isSpectator ? __('multiplayer.spectating_desc') : ($hasGivenUp ? __('multiplayer.gave_up_waiting') : __('multiplayer.finished_waiting'));
                    $watchColor = $isSpectator ? 'text-gold' : ($hasGivenUp ? 'text-danger' : 'text-active');
                @endphp
                <div class="border bg-surface/40 border-border/40 rounded-3xl shadow-xl p-8 flex flex-col items-center text-center gap-4">
                    <span class="text-fluid-title font-mono font-black uppercase tracking-wider {{ $watchColor }}">
                        {{ $watchTitle }}
                    </span>
                    <p class="text-sm font-mono text-muted max-w-sm">
                        {{ $watchDesc }}
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
                    @if ($this->isSpectator)
                        <span class="flex items-center gap-1.5">
                            <span class="text-sm leading-none">&#128065;</span>{{ __('multiplayer.you_spectated') }}
                        </span>
                    @else
                        <span>{!! __('multiplayer.you_placed', ['rank' => '<strong class="text-foreground font-bold">'.$myRank.(app()->getLocale() === 'en' ? $suffix : '').'</strong>']) !!}</span>
                    @endif
                </div>

                {{-- Hasil ditolak validasi server: tidak dicatat ke statistik (average WPM tak rusak). --}}
                @php $me = $results->firstWhere('user_id', Auth::id()); @endphp
                @if ($me && $me->result_recorded === false)
                    <div class="mt-2 flex items-center gap-2 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-x-small font-mono text-danger">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                        </svg>
                        <span>{{ __('multiplayer.result_invalid') }}</span>
                    </div>
                @endif
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
                                        @if ($rank->finished_time_seconds && $rank->finished_time_seconds != \App\Models\RoomMember::DNF_SENTINEL_SECONDS)
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
                $xp = $this->isSpectator ? null : $this->myXpResult;
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
                            <span class="text-muted text-[10px] mt-0.5">{{ __('multiplayer.level') }} {{ $lvl['level'] }} <span
                                    class="text-muted/50">-</span> {{ $lvl['next_level'] }}</span>
                        </div>
                    </div>
                    <div class="h-1.5 w-full bg-background/40 rounded-full overflow-hidden border border-border/20">
                        <div class="h-full bg-brand rounded-full transition-all duration-500"
                            style="width: {{ $xpBarWidth }}%"></div>
                    </div>
                </div>
            @endif

            {{-- Chat di layar hasil: dipakai untuk mengajak main lagi. Instance TERPISAH
                 dari chat lobby (wire:key beda) -> ini state Alpine baru, jadi pesan lobby
                 tak terbawa; sesuai sifat broadcast-only yang sesaat. --}}
            <div wire:key="room-chat-result">
                @include('livewire.partials.room-chat', ['currentUserId' => auth()->id()])
            </div>

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

    {{-- Logika arena balapan ada di resources/js/race-arena.js (store Alpine 'race'
         + komponen 'raceArena') dan resources/js/race-echo.js (langganan Echo),
         keduanya di-bundle lewat app.js. Data dari server tetap masuk lewat
         @js(...) di markup di atas, bukan lewat modul. --}}
</div>
