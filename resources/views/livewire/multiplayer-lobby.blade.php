<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8 text-typing-text">
    @if (session()->has('error'))
        <div class="p-4 mb-6 text-sm text-red-400 bg-red-950/40 border border-red-900 rounded-2xl">
            {{ session('error') }}
        </div>
    @endif

    <!-- ===================================================================== -->
    <!-- 1. HALAMAN PILIH: CREATE OR JOIN ROOM -->
    <!-- ===================================================================== -->
    @if ($this->step === 'choose')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-12 md:gap-24 items-stretch mt-10 relative w-full">
            <div
                class="flex flex-col items-center justify-center p-8 border bg-typing-surface/50 border-white/5 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full">
                <div class="w-20 h-20 mb-6 flex items-center justify-center text-4xl transition duration-300">
                    <img src="/icon/uetype_mascot.png" alt="Create Room">
                </div>
                <h3 class="text-xl font-sans font-bold tracking-wider text-typing-text mb-2 uppercase">Create Room</h3>
                <p class="text-sm text-typing-muted max-w-xs mb-8">Start a new room and get a shareable code for your
                    friends</p>
                <button wire:click="createRoom"
                    class="px-6 py-3 bg-[#cbb38a] hover:bg-[#bfa57a] text-black font-sans font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                    Create Room
                </button>
            </div>

            <div
                class="hidden md:flex absolute inset-y-0 left-1/2 -translate-x-1/2 items-center justify-center pointer-events-none">
                <div class="w-[1px] h-full bg-white/10 relative flex items-center justify-center">
                    <div
                        class="absolute w-12 h-12 rounded-full border-2 border-[#cbb38a] bg-typing-bg flex items-center justify-center font-sans text-xs font-bold tracking-wider text-typing-text shadow-xl">
                        OR
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col items-center justify-center p-8 border bg-typing-surface/50 border-white/5 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full">
                <div class="w-20 h-20 mb-6 flex items-center justify-center text-4xl transition duration-300">
                    <img src="/icon/uetype_mascot.png" alt="Join Room">
                </div>
                <h3 class="text-xl font-sans font-bold tracking-wider text-typing-text mb-2 uppercase">Join Room</h3>
                <p class="text-sm text-typing-muted max-w-xs mb-6">Enter the code your friend shared with you</p>

                <div class="flex gap-2 mb-6">
                    @foreach (range(0, 5) as $index)
                        <input type="text" wire:model="joinCodeInput.{{ $index }}" maxlength="1"
                            class="w-12 h-14 text-center font-mono text-xl font-bold uppercase bg-typing-bg border border-white/10 rounded-xl focus:border-typing-accent focus:ring-0 text-typing-text"
                            x-on:keyup="if($el.value.length == 1 && {{ $index }} < 5) { $el.nextElementSibling.focus() } else if($el.value.length == 0 && {{ $index }} > 0) { $el.previousElementSibling.focus() }" />
                    @endforeach
                </div>

                <button wire:click="joinRoom"
                    class="px-8 py-3 border border-white/10 text-typing-text hover:bg-white/5 font-sans font-semibold uppercase tracking-wider rounded-xl transition duration-200">
                    Join Room
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
                <span class="font-mono text-sm text-typing-muted tracking-wide">Create or join a room</span>
            </div>
            <span class="text-white/20 font-mono text-sm hidden md:block">→</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-[#cbb38a] bg-[#1a2333]/80 flex items-center justify-center font-mono text-xs font-bold text-white shadow-inner">
                    2</div>
                <span class="font-mono text-sm text-typing-muted tracking-wide">Share the code with friends</span>
            </div>
            <span class="text-white/20 font-mono text-sm hidden md:block">→</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-[#cbb38a] bg-[#1a2333]/80 flex items-center justify-center font-mono text-xs font-bold text-white shadow-inner">
                    3</div>
                <span class="font-mono text-sm text-typing-muted tracking-wide">Race together in real time</span>
            </div>
        </div>
    @endif

    <!-- ===================================================================== -->
    <!-- 2. HALAMAN RUANG TUNGGU: WAITING ROOM -->
    <!-- ===================================================================== -->
    @if ($this->step === 'waiting' && $this->roomData && !$showResultModal)
        {{-- wire:poll dihapus: update lobby (join/ready/leave) sudah didorong lewat
             WebSocket .room.updated -> Livewire.dispatch('room-updated'). Polling 2s
             hanya menambah latensi & beban tanpa manfaat. --}}
        <div class="space-y-8">
            <div
                class="p-6 border bg-typing-surface/40 border-white/5 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-mono tracking-widest text-typing-muted uppercase">Room Code - Share with
                        friends</span>
                    <h2 class="text-4xl font-mono font-black tracking-[0.3em] text-white mt-1">
                        {{ $this->roomData->code }}</h2>
                </div>
                <button
                    onclick="navigator.clipboard.writeText('{{ $this->roomData->code }}'); alert('Kode kamar disalin!')"
                    class="px-5 py-2.5 bg-white/5 border border-white/10 hover:bg-white/10 font-sans text-xs font-bold uppercase tracking-wider rounded-xl transition">
                    Copy Code
                </button>
            </div>

            <div>
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xs uppercase tracking-widest text-typing-muted font-mono font-bold">Players</h3>
                    <span class="text-xs font-mono text-[#cbb38a] font-bold">{{ $this->roomData->members->count() }} / 5
                        Joined</span>
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
                                    class="font-sans text-sm font-bold truncate max-w-[100px]">{{ $member->user->username }}</span>
                                <div class="mt-3 w-full">
                                    @if ($member->user_id === $this->roomData->host_id)
                                        <span
                                            class="inline-block w-full px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider bg-[#cbb38a] text-black rounded-md">HOST</span>
                                    @else
                                        <span
                                            class="inline-block w-full px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider rounded-md {{ $member->is_ready ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-zinc-800 text-zinc-400' }}">
                                            {{ $member->is_ready ? 'READY' : 'NOT READY' }}
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
                                <span class="text-xs font-mono text-typing-muted">Empty Slot</span>
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

            <div class="pt-6 border-t border-white/5 flex gap-4">
                @if ($this->isHost)
                    <button wire:click="startRace" @disabled(!$this->allReady)
                        class="px-6 py-3 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->allReady ? 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black shadow-md' : 'bg-zinc-800 text-zinc-500 cursor-not-allowed border border-white/5' }}">
                        Start Race
                    </button>
                    @if (!$this->allReady)
                        <span class="text-xs font-mono text-typing-muted self-center">Waiting for all participants to be
                            ready...</span>
                    @endif
                @else
                    <button wire:click="toggleReady"
                        class="px-6 py-3 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? 'bg-emerald-600 text-white hover:bg-emerald-500' : 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black' }}">
                        {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? "I'm Not Ready" : "I'm Ready" }}
                    </button>
                @endif

                <button wire:click="leaveRoom"
                    class="px-6 py-3 bg-transparent border border-white/10 text-typing-muted hover:text-typing-text hover:bg-white/5 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    Leave Room
                </button>
            </div>
        </div>
    @endif

    <!-- ===================================================================== -->
    <!-- 3. HALAMAN ARENA PERTANDINGAN: BATTLE STAGE (TYPERACER MECHANICS) -->
    <!-- ===================================================================== -->
    @if ($this->step === 'racing' && $this->roomData && !$showResultModal)
        {{-- wire:key stabil: memberi tahu Livewire agar mempertahankan elemen
             (beserta seluruh state Alpine di x-data) lintas re-render/poll.
             Tanpa ini, morph bisa mengganti node -> x-data ter-reinisialisasi
             -> raceStarted & countdown balik ke awal (overlay 3 detik nongol
             lagi) sekaligus progress pengetikan hilang. --}}
        {{-- Seluruh logika Alpine dipindah ke komponen terdaftar 'raceArena'
             (lihat @script di bawah). Menaruh blok JS besar langsung di atribut
             x-data rawan: komentar //, em-dash, dan karakter { } bisa merusak
             parsing atribut HTML sehingga kode-nya bocor tampil sebagai teks di
             halaman. Di sini x-data hanya memanggil fungsi + mengoper data server
             lewat @js() yang sudah aman ter-escape. --}}
        {{-- wire:poll.1s="checkSuddenDeath" dihapus: sudden death kini disinkron via
             WebSocket .race.sudden_death (timestamp akhir dari server) + clock lokal.
             Saat clock lokal capai 0, lockRace() memanggil checkSuddenDeath() SEKALI
             sebagai gerbang final server (idempoten). Tidak perlu poll tiap detik. --}}
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
                    <span class="text-amber-400 font-mono text-sm uppercase tracking-wider font-bold">Sudden Death
                        Activated! Room Closes In:</span>
                    <span
                        class="text-xl font-mono font-black text-white bg-amber-600 px-3 py-0.5 rounded-lg"
                        x-text="suddenDeathRemaining + 's'"></span>
                </div>
            @endif

            <!-- OVERLAY COUNTDOWN SCREEN -->
            <!-- Hanya untuk permulaan race. Ditambah guard !suddenDeathActive
                 supaya overlay ini tidak pernah nongol lagi ketika hitung mundur
                 15 detik (sudden death) sedang berlangsung di tengah game. -->
            <template x-if="!raceStarted && !suddenDeathActive">
                <div class="fixed inset-0 bg-typing-bg/95 flex flex-col items-center justify-center z-50 select-none">
                    <span class="font-mono text-xs uppercase tracking-[0.4em] text-typing-muted mb-4">The Race is
                        Starting</span>
                    <div class="text-8xl font-sans font-black tracking-wider text-[#cbb38a] scale-110 transition-all duration-300"
                        x-text="countdown"></div>
                </div>
            </template>

            <!-- VISUALISASI ARENA BALAPAN MASKOT UETYPE -->
            <div class="p-6 border bg-typing-surface/50 border-white/5 rounded-3xl space-y-4 shadow-xl">
                <span class="text-xs font-mono uppercase tracking-widest text-typing-muted block mb-2">Mascot Race
                    Track</span>

                <div class="space-y-3 bg-black/30 p-4 rounded-2xl border border-white/[0.02] divide-y divide-white/5">
                    @foreach ($this->roomData->members as $player)
                        @php
                            $isSelf = $player->user_id === Auth::id();
                        @endphp
                        {{-- x-data lane: setiap lane menghitung progress/wpm/finished-nya sendiri
                             secara reaktif. Pemain SENDIRI membaca state lokal raceArena (gerak
                             instan dari ketikan). Pemain LAWAN membaca $store.race.opponents[id]
                             yang diisi langsung dari payload WebSocket (.race.progress) — TANPA
                             re-render Livewire. Nilai Blade dipakai sebagai seed awal / fallback. --}}
                        {{-- Semua lane (termasuk diri sendiri) membaca dari $store.race
                             secara SERAGAM: raceArena mem-publish progress pemain lokal ke
                             store dengan key userId-nya sendiri (lihat publishLocal()).
                             Ini menghindari ketergantungan rapuh pada `this` komponen induk
                             di dalam getter Alpine nested. Nilai Blade = seed awal/fallback. --}}
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
                                        <span class="text-[10px] text-zinc-500">[Host]</span>
                                    @endif
                                    <span class="text-emerald-400 font-bold ml-1" x-show="liveFinished" x-cloak>[FINISHED]</span>
                                </span>
                                <span class="font-mono text-[#cbb38a] font-bold"><span x-text="liveWpmValue"></span> WPM</span>
                            </div>

                            <div
                                class="h-10 w-full bg-typing-bg/80 rounded-xl relative border border-white/5 overflow-hidden flex items-center">
                                <div
                                    class="absolute right-0 top-0 bottom-0 w-8 bg-zinc-900 border-l border-dashed border-white/20 flex items-center justify-center font-mono text-[10px] text-zinc-600 select-none">
                                    FINISH</div>

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
                    <span class="text-xs font-mono uppercase tracking-widest text-typing-muted">Arena - Fast Typing
                        Mode</span>
                    <span class="text-xs font-mono text-[#cbb38a]">Room Code: <strong
                            class="text-white">{{ $this->roomCode }}</strong></span>
                </div>

                <!-- BLOK DRAF PARAGRAF DENGAN INDIKATOR WARNA TYPERACER -->
                <div
                    class="font-mono text-xl leading-relaxed tracking-wide select-none p-5 bg-black/20 rounded-xl border border-white/[0.02] flex flex-wrap gap-x-2 gap-y-1">
                    <template x-for="(word, wIdx) in words" :key="wIdx">
                        <span
                            :class="{
                                'text-emerald-400': wIdx < currentWordIndex,
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
                        :placeholder="lockedByTimeout ? 'Times up! Race locked.' : (isFinished ? 'You finished the race!' : (raceStarted ? 'Type the current word here...' :
                            'Wait for countdown...'))"
                        :class="{
                            'border-red-500/60 focus:ring-red-500 focus:border-red-500 bg-red-950/10 text-red-200': hasError,
                            'focus:ring-1 focus:ring-[#cbb38a] focus:border-[#cbb38a] border-white/10 text-white': !
                                hasError
                        }"
                        class="w-full px-5 py-4 bg-typing-bg border rounded-xl font-mono text-base transition-all duration-200 placeholder-zinc-600 disabled:opacity-40 disabled:cursor-not-allowed" />
                </div>

                <div class="pt-4 flex justify-end">
                    <button wire:click="leaveRoom"
                        class="px-5 py-2.5 bg-red-950/20 border border-red-900/30 text-red-400 hover:bg-red-950/40 font-sans text-xs font-bold uppercase tracking-wider rounded-xl transition">
                        Give Up & Leave
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- ===================================================================== -->
    <!-- 4. HALAMAN BARU: MATCH RESULT -->
    <!-- ===================================================================== -->
    @if ($showResultModal && $this->roomData)
        <div class="space-y-12 animate-fade-in py-4 select-none">

            <!-- HEADER MATCH RESULT -->
            <div class="flex flex-col space-y-1">
                <h1 class="text-4xl font-mono font-black text-[#cbb38a] tracking-wider uppercase">Match Result</h1>
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
                    <span>Room {{ $this->roomCode }}</span>
                    <span class="text-white/20">•</span>
                    <span>You placed <strong
                            class="text-white font-bold">{{ $myRank }}{{ $suffix }}</strong></span>
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
                                    class="inline-block bg-[#cbb38a] text-black text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">YOU</span>
                            @endif
                        </div>
                        <div class="w-10 h-10 animate-bounce">
                            <img src="/icon/uetype_mascot.png" class="w-full h-full object-contain">
                        </div>
                    @endif
                    <div
                        class="w-full h-20 bg-transparent border-2 border-white/10 rounded-2xl flex items-center justify-center font-sans font-black text-3xl text-white/30">
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
                                    class="inline-block bg-black text-[#cbb38a] text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">YOU</span>
                            @endif
                        </div>
                        <div class="w-12 h-12 animate-bounce" style="animation-duration: 2.2s;">
                            <img src="/icon/uetype_mascot.png" class="w-full h-full object-contain">
                        </div>
                    @endif
                    <div
                        class="w-full h-32 bg-[#cbb38a] rounded-2xl flex items-center justify-center font-sans font-black text-5xl text-black shadow-lg">
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
                                    class="inline-block bg-[#cbb38a] text-black text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">YOU</span>
                            @endif
                        </div>
                        <div class="w-10 h-10 animate-bounce" style="animation-duration: 1.8s;">
                            <img src="/icon/uetype_mascot.png" class="w-full h-full object-contain">
                        </div>
                    @endif
                    <div
                        class="w-full h-16 bg-transparent border-2 border-[#cbb38a]/20 rounded-2xl flex items-center justify-center font-sans font-black text-2xl text-[#cbb38a]/30">
                        3
                    </div>
                </div>

            </div>

            <!-- TABEL FULL RESULTS -->
            <div class="space-y-3">
                <span class="text-[11px] font-mono uppercase tracking-[0.25em] text-typing-muted block mb-1">Full
                    Results</span>
                <div
                    class="w-full border border-white/5 rounded-2xl overflow-hidden bg-typing-surface/10 backdrop-blur-sm">
                    <table class="w-full text-left font-mono text-sm border-collapse">
                        <thead>
                            <tr
                                class="border-b border-white/5 bg-black/20 text-xs text-typing-muted uppercase tracking-wider">
                                <th class="py-3.5 px-5 font-medium">Place</th>
                                <th class="py-3.5 px-5 font-medium">Player</th>
                                <th class="py-3.5 px-5 font-medium">Net WPM</th>
                                <th class="py-3.5 px-5 font-medium">Accuracy</th>
                                <th class="py-3.5 px-5 font-medium">Time</th>
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
                                    <td class="py-4 px-5 font-bold text-white">{{ $pos }}{{ $suffix }}
                                    </td>
                                    <td class="py-4 px-5">
                                        <div class="flex items-center gap-2">
                                            <span>{{ $rank->user->username }}</span>
                                            @if ($isMe)
                                                <span
                                                    class="bg-blue-500 text-white text-[9px] font-black px-1 py-0.1 rounded uppercase tracking-wide">YOU</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-4 px-5 text-[#cbb38a] font-bold">{{ $rank->wpm }} wpm</td>
                                    <td class="py-4 px-5">{{ $rank->accuracy ?? 97.0 }}%</td>
                                    <td class="py-4 px-5">
                                        @if ($rank->finished_time_seconds && $rank->finished_time_seconds != 999)
                                            {{ sprintf('%02d:%02d', floor($rank->finished_time_seconds / 60), $rank->finished_time_seconds % 60) }}
                                        @else
                                            <span class="text-red-400/70 text-xs">01:24</span>
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
                            <span class="text-typing-muted text-[10px] uppercase tracking-wide">XP Earned</span>
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
            <div class="pt-2 flex gap-4">
                @if ($this->isHost)
                    <button wire:click="playAgain"
                        class="px-6 py-3 bg-[#cbb38a] hover:bg-[#bfa57a] text-black font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                        Play Again
                    </button>
                @endif
                <button wire:click="leaveRoom"
                    class="px-6 py-3 bg-transparent border border-white/10 text-typing-muted hover:text-typing-text hover:bg-white/5 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    Leave Room
                </button>
            </div>

        </div>
    @endif

    {{-- @assets (BUKAN @script): definisi komponen Alpine diletakkan di sini.
         @script membungkus isi <script> menjadi nilai atribut wire:effects pada
         elemen pembungkus; kode yang mengandung banyak karakter '<' dan '>'
         (mis. `a <= b`, `wIdx < currentWordIndex`) merusak parsing atribut/DOM
         Livewire -> DOMDocument gagal -> <body> null -> "Attempt to read
         property childNodes on null". @assets menyuntik script apa adanya ke
         <head> sekali saja (sebelum Alpine init), jadi aman untuk kode besar. --}}
    @assets
        <script>
            // === KOMPONEN ALPINE: raceArena ===
            // Semua logika typing + sudden death ada di sini (bukan di atribut
            // x-data) supaya tidak ada JS yang bocor ke HTML.
            //
            // Pendaftaran tahan dua kondisi timing: (1) skrip jalan SEBELUM
            // Alpine init -> daftar via event 'alpine:init'; (2) skrip jalan
            // SETELAH Alpine sudah booting -> window.Alpine sudah ada, daftar
            // langsung. registerRaceArena() idempoten via flag global.
            const registerRaceArena = (Alpine) => {
                if (window.__raceArenaRegistered) return;
                window.__raceArenaRegistered = true;

                // STORE GLOBAL 'race': menyimpan posisi maskot LAWAN, diisi langsung
                // dari payload WebSocket (.race.progress). Sengaja store (bukan state
                // komponen) supaya bertahan lintas Livewire morph — kalau raceArena
                // ter-reinisialisasi saat re-render, posisi lawan tidak hilang.
                // Bentuk: opponents = { [userId]: { progress, wpm, finished } }.
                if (!Alpine.store('race')) {
                    Alpine.store('race', {
                        opponents: {},
                        apply(userId, data) {
                            // reassign object supaya reaktivitas Alpine ter-trigger
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
                    // Waktu absolut (ms epoch) kapan race resmi mulai, dari server.
                    // Countdown 3-2-1 dihitung mundur ke titik ini -> sinkron antar layar.
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

                    // Progress & WPM pemain LOKAL, reaktif -> dibaca oleh lane maskot
                    // sendiri (self) di view. Diperbarui tiap checkInput().
                    progressPercent: 0,
                    liveWpm: 0,

                    // Throttle emit progress ke server (Area 1.4). Visual lokal tetap
                    // instan; pengiriman jaringan dibatasi ~120ms + trailing flush.
                    _lastEmit: 0,
                    _emitTimer: null,

                    // Sudden death (client-side authoritative timer). Server tetap
                    // sumber kebenaran final via checkSuddenDeath(), tapi input
                    // dikunci di klien tepat saat hitung mundur menyentuh 0.
                    suddenDeathActive: !!config.suddenDeathActive,
                    suddenDeathRemaining: config.suddenDeathRemaining ?? 15,
                    lockedByTimeout: false,
                    _sdInterval: null,

                    init() {
                        this.words = this.textToType.split(' ');

                        // Bersihkan posisi lawan dari race SEBELUMNYA saat memulai race
                        // baru (mis. Play Again). Kalau sudden death sedang aktif berarti
                        // ini re-init di TENGAH race yang sama -> jangan hapus posisi.
                        if (!this.suddenDeathActive && this.$store.race) {
                            this.$store.race.reset();
                        }

                        // Kalau sudden death SUDAH aktif saat komponen ini
                        // (re)inisialisasi, race sudah lama berjalan -> overlay
                        // countdown 3 detik tidak boleh muncul lagi. Langsung
                        // anggap race berjalan.
                        if (this.suddenDeathActive) {
                            this.raceStarted = true;
                            this.countdown = 'GO!';
                            this.startTime = Date.now();
                            this.startSuddenDeathClock();
                            this.$nextTick(() => {
                                if (this.$refs.typeInput) this.$refs.typeInput.focus();
                            });
                        } else {
                            // Alur normal: overlay hitung mundur ke race_starts_at (server).
                            // Karena dihitung dari waktu absolut yang sama, kedua layar
                            // menampilkan angka yang sama pada saat yang sama -> tidak ada
                            // lagi delay ~1 detik antar host & peserta.
                            this.startSyncedCountdown();
                        }

                        // Sinyal server saat room resmi ditutup paksa -> kunci total.
                        this.$wire.on('force-finish', () => this.lockRace());

                        // Sudden death dimulai (broadcast .race.sudden_death via WebSocket).
                        // Bridge event window -> sinkronkan hitung mundur di komponen ini.
                        // Disimpan supaya bisa di-remove saat komponen dibongkar.
                        this._onSuddenDeath = (ev) => this.syncSuddenDeath(ev.detail.remaining);
                        window.addEventListener('race-sudden-death', this._onSuddenDeath);
                    },

                    // Hitung mundur awal race berbasis WAKTU ABSOLUT server (race_starts_at),
                    // bukan interval lokal yang dimulai saat render. Setiap ~100ms hitung
                    // sisa waktu; tampilkan 3/2/1 lalu 'GO!' dan mulai race saat menyentuh 0.
                    startSyncedCountdown() {
                        const tick = () => {
                            // Fallback aman jika server tidak mengirim raceStartsAt.
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
                                // Jeda singkat menampilkan "GO!" lalu buka input.
                                setTimeout(() => this.beginRace(), 400);
                            } else {
                                // ceil supaya 2001ms..3000ms => "3", dst. Minimal tampil "1".
                                this.countdown = Math.max(1, Math.ceil(remainingMs / 1000));
                            }
                        };

                        tick(); // render langsung tanpa menunggu interval pertama
                        this._countdownInterval = setInterval(tick, 100);
                    },

                    // Mulai race: buka input & catat startTime. startTime dipatok ke
                    // race_starts_at server (kalau ada) supaya perhitungan WPM antar
                    // pemain memakai titik awal yang sama.
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

                    // Menyinkronkan sisa waktu dari server & memastikan clock lokal jalan.
                    // Dipanggil dari x-init banner DAN dari event .race.sudden_death.
                    syncSuddenDeath(remainingFromServer) {
                        this.suddenDeathActive = true;
                        // Ambil yang paling konservatif kalau clock sudah jalan (hindari mundur naik).
                        if (this._sdInterval) {
                            this.suddenDeathRemaining = Math.min(this.suddenDeathRemaining, remainingFromServer);
                        } else {
                            this.suddenDeathRemaining = remainingFromServer;
                        }
                        this.startSuddenDeathClock();
                        if (this.suddenDeathRemaining <= 0) this.lockRace();
                    },

                    startSuddenDeathClock() {
                        if (this._sdInterval) return; // sudah berjalan
                        this._sdInterval = setInterval(() => {
                            this.suddenDeathRemaining--;
                            if (this.suddenDeathRemaining <= 0) {
                                this.suddenDeathRemaining = 0;
                                this.lockRace();
                            }
                        }, 1000);
                    },

                    // Kunci paksa: hentikan input & pengiriman progress. Idempoten.
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

                        // Gantikan peran poll 1 detik: saat hitung mundur lokal menyentuh
                        // 0, minta server memfinalisasi SEKALI. checkSuddenDeath() idempoten
                        // (guard `>= SUDDEN_DEATH_SECONDS`), jadi aman meski beberapa klien
                        // memanggilnya bersamaan.
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

                        // Update state reaktif LOKAL -> maskot sendiri gerak INSTAN (tak
                        // menunggu jaringan). Ini yang bikin pemain lokal zero delay.
                        this.liveWpm = liveWpm;

                        // Kata terakhir: selesai otomatis saat huruf terakhir benar.
                        if (this.currentWordIndex === this.words.length - 1 && this.typedText === targetWord) {
                            this.isFinished = true;
                            this.progressPercent = 100;
                            this.publishLocal(100, liveWpm, true);
                            // Finish WAJIB dikirim sinkron & segera (tak boleh di-throttle).
                            this.emitProgress(100, liveWpm, accuracyPercent, true);
                            return;
                        }

                        this.progressPercent = progressPercent;
                        this.publishLocal(progressPercent, liveWpm, false);
                        this.emitProgress(progressPercent, liveWpm, accuracyPercent, false);
                    },

                    // Publikasikan posisi pemain LOKAL ke store dengan key userId sendiri,
                    // supaya lane maskot diri sendiri membaca dari sumber yang SAMA dengan
                    // lawan ($store.race.opponents[playerId]) -> gerak instan & seragam.
                    publishLocal(progress, wpm, finished) {
                        if (this.myId == null) return;
                        this.$store.race.apply(this.myId, {
                            progress_percent: progress,
                            wpm: wpm,
                            finished: finished,
                        });
                    },

                    // Kirim progress ke server dengan throttle. Keystroke di-throttle
                    // ~120ms (trailing-edge flush menjaga posisi terakhir tak hilang),
                    // tapi `force` (finish / pindah kata) selalu dikirim segera.
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

                        // Terlalu cepat: jadwalkan pengiriman terakhir (trailing edge)
                        // memakai nilai TERBARU saat timer menyala.
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

                    handleSpace(e) {
                        if (this.lockedByTimeout || this.isFinished || !this.raceStarted) return;

                        let targetWord = this.words[this.currentWordIndex];

                        // Lock on error: hanya pindah kata jika cocok persis.
                        if (this.typedText === targetWord) {
                            e.preventDefault();
                            this.correctCharsFromPastWords += targetWord.length + 1;
                            this.currentWordIndex++;
                            this.typedText = '';
                            this.hasError = false;
                            this.prevTypedLength = 0;
                            this.checkInput();
                        } else {
                            e.preventDefault();
                        }
                    },
                }));
            };

            // Kondisi 1: Alpine sudah tersedia (skrip jalan setelah Alpine boot).
            if (window.Alpine) {
                registerRaceArena(window.Alpine);
            }
            // Kondisi 2: Alpine belum init -> daftar saat event alpine:init.
            document.addEventListener('alpine:init', () => registerRaceArena(window.Alpine));
        </script>
    @endassets

    {{-- Kode Echo/broadcast tetap di @script (butuh Livewire.on). Aman di sini
         karena tidak mengandung karakter '<'/'>' yang bisa merusak parsing. --}}
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
                // Bersihkan posisi lawan supaya room berikutnya tidak kebawa state basi.
                if (window.Alpine && window.Alpine.store('race')) {
                    window.Alpine.store('race').reset();
                }
            };

            Livewire.on('subscribe-room', (event) => {
                const room = event.room;

                leaveChannels();

                // Channel LIFECYCLE (low-frequency): join/ready/leave/start/finish.
                // Tetap re-render Livewire penuh karena kondisi room memang berubah.
                currentRoomChannel = `room.${room}`;
                window.Echo
                    .channel(currentRoomChannel)
                    .listen('.room.updated', () => {
                        Livewire.dispatch('room-updated');
                    });

                // Channel RACE (high-frequency): posisi maskot lawan. Diterapkan
                // LANGSUNG ke Alpine store TANPA round-trip Livewire -> zero delay.
                currentRaceChannel = `race.${room}`;
                window.Echo
                    .channel(currentRaceChannel)
                    .listen('.race.progress', (e) => {
                        if (window.Alpine && window.Alpine.store('race')) {
                            window.Alpine.store('race').apply(e.userId, e.progressData || {});
                        }
                    })
                    .listen('.race.sudden_death', (e) => {
                        // Semua klien menghitung sisa waktu dari timestamp akhir yang
                        // SAMA (server) -> hitung mundur sudden death tersinkron.
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
