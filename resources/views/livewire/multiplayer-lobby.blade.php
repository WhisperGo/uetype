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
    @if ($this->step === 'waiting' && $this->roomData)
        <div class="space-y-8" wire:poll.5s>
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
                        @php($member = $this->roomData->members->values()->get($i))
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
                                            class="inline-block w-full px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider rounded-md {{ $member->is_ready ? 'bg-[#cbb38a] text-black border border-[#cbb38a]' : 'bg-[#cbb38a] text-black' }}">
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
                    <button wire:click="startRace" @if (!$this->allReady) disabled @endif
                        class="px-6 py-3 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->allReady ? 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black shadow-md' : 'bg-zinc-800 text-zinc-500 cursor-not-allowed border border-white/5' }}">
                        Start Race
                    </button>
                    @if (!$this->allReady)
                        <span class="text-xs font-mono text-typing-muted self-center">Waiting for all participants to be
                            ready...</span>
                    @endif
                @else
                    <button wire:click="toggleReady"
                        class="px-6 py-3 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? 'bg-[#cbb38a] text-black hover:bg-[#bfa57a]' : 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black' }}">
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
    <!-- 3. HALAMAN ARENA PERTANDINGAN: BATTLE STAGE -->
    <!-- ===================================================================== -->
    @if ($this->step === 'racing' && $this->roomData)
        <div class="space-y-8" x-data="{
            countdown: 3,
            raceStarted: false,
            init() {
                let timer = setInterval(() => {
                    if (this.countdown > 1) {
                        this.countdown--;
                    } else {
                        this.countdown = 'GO!';
                        clearInterval(timer);
                        setTimeout(() => {
                            this.raceStarted = true;
                            // Auto fokus ke input area ketikan
                            $nextTick(() => { $refs.typeInput.focus(); });
                        }, 800);
                    }
                }, 1000);
            }
        }">

            <!-- OVERLAY COUNTDOWN SCREEN (3 DETIK PANDUAN RETRO) -->
            <template x-if="!raceStarted">
                <div
                    class="fixed inset-0 bg-typing-bg/95 flex flex-col items-center justify-center z-50 select-none animate-fade-in">
                    <span class="font-mono text-xs uppercase tracking-[0.4em] text-typing-muted mb-4">The Race is
                        Starting</span>
                    <div class="text-8xl font-sans font-black tracking-wider text-[#cbb38a] scale-110 transition-all duration-300"
                        x-text="countdown">
                    </div>
                </div>
            </template>

            <!-- CONTAINER UTAMA ARENA BALAPAN -->
            <div class="grid grid-cols-1 lg:grid-cols-4 gap-8 items-start">

                <!-- KIRI (3 KOLOM): ARENA KETIKAN TEKS -->
                <div
                    class="lg:col-span-3 p-8 border bg-typing-surface/40 border-white/5 rounded-3xl space-y-6 shadow-xl">
                    <div class="flex justify-between items-center border-b border-white/5 pb-4">
                        <span class="text-xs font-mono uppercase tracking-widest text-typing-muted">Arena - Fast Typing
                            Mode</span>
                        <div class="flex gap-4 font-mono text-xs">
                            <span class="text-[#cbb38a]">Room: <strong
                                    class="text-white">{{ $this->roomCode }}</strong></span>
                        </div>
                    </div>

                    <!-- BLOK TEKS BALAPAN -->
                    <div
                        class="font-mono text-xl leading-relaxed text-zinc-500 tracking-wide select-none p-4 bg-black/20 rounded-xl border border-white/[0.02]">
                        {{ $this->roomData->text_to_type }}
                    </div>

                    <!-- FIELD INPUT KETIKAN UTAMA -->
                    <div class="relative">
                        <input type="text" x-ref="typeInput" wire:model.live="typedText" :disabled="!raceStarted"
                            :placeholder="raceStarted ? 'Type the text here...' : 'Wait for countdown...'"
                            class="w-full px-5 py-4 bg-typing-bg border rounded-xl font-mono text-base transition-all duration-200 text-white focus:ring-1 focus:ring-[#cbb38a] focus:border-[#cbb38a] placeholder-zinc-600 disabled:opacity-40 disabled:cursor-not-allowed border-white/10" />
                    </div>
                </div>

                <!-- KANAN (1 KOLOM): LIVE TRACK POSITION PEMAIN -->
                <div class="p-6 border bg-typing-surface/50 border-white/5 rounded-3xl space-y-6 shadow-md">
                    <h3
                        class="text-xs font-mono uppercase tracking-widest text-[#cbb38a] border-b border-white/5 pb-3 font-bold">
                        Live Standings
                    </h3>

                    <!-- TRACK MINI UNTUK SETIAP MEMBER -->
                    <div class="space-y-5" wire:poll.1s>
                        @foreach ($this->roomData->members as $player)
                            <div class="space-y-1">
                                <div class="flex justify-between text-xs font-mono">
                                    <span
                                        class="font-bold max-w-[120px] truncate {{ $player->user_id === Auth::id() ? 'text-[#cbb38a]' : 'text-typing-text' }}">
                                        {{ $player->user->username }}
                                    </span>
                                    <span class="text-typing-muted">0 WPM</span>
                                </div>
                                <!-- Progress Bar Progres Ketikan -->
                                <div
                                    class="h-2 w-full bg-black/40 rounded-full overflow-hidden border border-white/5 relative">
                                    <div class="h-full bg-[#cbb38a] transition-all duration-300" style="width: 10%">
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

            </div>
        </div>
    @endif

    @script
        <script>
            let currentChannel = null;

            Livewire.on('subscribe-room', (event) => {

                const room = event.room;

                if (currentChannel) {
                    window.Echo.leave(currentChannel);
                }

                currentChannel = `room.${room}`;

                console.log("SUBSCRIBE:", currentChannel);

                window.Echo
                    .channel(currentChannel)
                    .listen('.room.updated', (e) => {

                        console.log("EVENT DITERIMA", e);

                        Livewire.dispatch('room-updated');
                    });
            });

            Livewire.on('leave-room', () => {

                if (currentChannel) {
                    window.Echo.leave(currentChannel);
                    currentChannel = null;
                }

            });
        </script>
    @endscript
</div>
