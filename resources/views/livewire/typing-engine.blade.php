<div class="min-h-screen bg-[#323437] text-[#646669] font-mono selection:bg-yellow-500 selection:text-black outline-none"
    wire:key="typing-container-{{ $mainMode }}-{{ $subMode }}-{{ $textToType }}" x-data="{
        currentMain: @entangle('mainMode').live,
        currentSub: @entangle('subMode').live,
        ...typingGame(@js($textToType))
    }"
    @mode-changed.window="resetWithNewText($event.detail)" {{-- PERBAIKAN KRUSIAL: Gunakan $event, bukan e --}}
    @keydown.window="if($event.key === 'Tab') { $event.preventDefault(); $wire.restart(); } else { handleInput($event) }">

    <div class="max-w-5xl mx-auto pt-20 px-4">

        <div class="flex justify-center mb-10 transition-all duration-500"
            :class="isStarted ? 'opacity-0 -translate-y-10 pointer-events-none' : 'opacity-100'">
            <div
                class="flex items-center gap-6 bg-[#2c2e31] px-6 py-2 rounded-xl text-sm shadow-xl border border-gray-800">
                <div class="flex items-center gap-4 border-r border-gray-700 pr-6 text-gray-500 font-bold">
                    <button
                        @click.prevent="currentMain = 'time'; currentSub = '30'; $wire.setMode('time', '30'); $el.blur()"
                        class="flex items-center gap-2 transition-colors duration-200 py-1 px-2 rounded-md outline-none"
                        :class="currentMain === 'time' ? 'text-yellow-500' : 'hover:text-gray-200'">time</button>

                    <button
                        @click.prevent="currentMain = 'words'; currentSub = '50'; $wire.setMode('words', '50'); $el.blur()"
                        class="flex items-center gap-2 transition-colors duration-200 py-1 px-2 rounded-md outline-none"
                        :class="currentMain === 'words' ? 'text-yellow-500' : 'hover:text-gray-200'">words</button>

                    <button
                        @click.prevent="currentMain = 'quote'; currentSub = 'medium'; $wire.setMode('quote', 'medium'); $el.blur()"
                        class="flex items-center gap-2 transition-colors duration-200 py-1 px-2 rounded-md outline-none"
                        :class="currentMain === 'quote' ? 'text-yellow-500' : 'hover:text-gray-200'">quote</button>
                </div>

                <div class="flex items-center gap-2">
                    <template x-if="currentMain === 'time'">
                        <div class="flex gap-2">
                            @foreach (['15', '30', '60', '120'] as $t)
                                <button
                                    @click.prevent="currentSub = '{{ $t }}'; $wire.setMode('time', '{{ $t }}'); $el.blur()"
                                    class="px-2 py-0.5 rounded transition-all duration-200 outline-none"
                                    :class="currentSub == '{{ $t }}' ?
                                        'text-yellow-500 outline outline-2 outline-yellow-500/50' :
                                        'hover:text-gray-200'">{{ $t }}</button>
                            @endforeach
                        </div>
                    </template>
                    <template x-if="currentMain === 'words'">
                        <div class="flex gap-2">
                            @foreach (['10', '25', '50', '100'] as $w)
                                <button
                                    @click.prevent="currentSub = '{{ $w }}'; $wire.setMode('words', '{{ $w }}'); $el.blur()"
                                    class="px-2 py-0.5 rounded transition-all duration-200 outline-none"
                                    :class="currentSub == '{{ $w }}' ?
                                        'text-yellow-500 outline outline-2 outline-yellow-500/50' :
                                        'hover:text-gray-200'">{{ $w }}</button>
                            @endforeach
                        </div>
                    </template>
                </div>
            </div>
        </div>

        <div class="flex gap-10 mb-6 text-3xl transition-opacity duration-300"
            :class="isStarted ? 'opacity-100' : 'opacity-0'">
            <div><span class="text-xs block text-gray-500 font-bold uppercase tracking-widest">wpm</span> <span
                    class="text-yellow-500 font-bold" x-text="wpm">0</span></div>
            <div><span class="text-xs block text-gray-500 font-bold uppercase tracking-widest">acc</span> <span
                    class="text-yellow-500 font-bold" x-text="accuracy">0</span>%</div>
            <div>
                <span class="text-xs block text-gray-500 font-bold uppercase tracking-widest">time</span>
                <span class="transition-colors duration-300 font-bold"
                    :class="(currentMain === 'time' && timer < 5 && isStarted) ? 'text-[#ca4754]' : 'text-yellow-500'"
                    x-text="timer">0</span>
            </div>
        </div>

        <div class="relative min-h-[150px] text-3xl leading-relaxed tracking-tight select-none outline-none"
            wire:ignore.self>
            <div class="flex flex-wrap content-start gap-x-[0.5em]">
                @php
                    $words = explode(' ', $textToType);
                    $charPointer = 0;
                @endphp

                @foreach ($words as $word)
                    <div class="flex" wire:key="word-{{ $loop->index }}-{{ $textToType }}">
                        @foreach (str_split($word) as $char)
                            <span class="relative transition-colors duration-100 inline-block"
                                :class="{
                                    'text-[#d1d0c5]': {{ $charPointer }} < currentIndex && inputResults[
                                        {{ $charPointer }}] === true,
                                    'text-[#ca4754] border-b-2 border-[#ca4754]': {{ $charPointer }} < currentIndex &&
                                        inputResults[{{ $charPointer }}] === false,
                                    'text-[#646669]': {{ $charPointer }} > currentIndex,
                                    'text-[#d1d0c5]': {{ $charPointer }} === currentIndex
                                }">
                                {{ $char }}
                                <div x-show="{{ $charPointer }} === currentIndex && !isFinished"
                                    class="absolute -left-[2px] top-[10%] w-[2.5px] h-[80%] bg-yellow-500 animate-[pulse_0.8s_infinite] z-10 transition-all duration-75">
                                </div>
                            </span>
                            @php $charPointer++; @endphp
                        @endforeach

                        @if (!$loop->last)
                            <span class="relative w-[0.5em] inline-block"
                                :class="inputResults[{{ $charPointer }}] === false ? 'bg-[#ca4754]/30' : ''">
                                &nbsp;
                                <div x-show="{{ $charPointer }} === currentIndex && !isFinished"
                                    class="absolute -left-[2px] top-[10%] w-[2.5px] h-[80%] bg-yellow-500 animate-[pulse_0.8s_infinite] z-10 transition-all duration-75">
                                </div>
                            </span>
                            @php $charPointer++; @endphp
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="mt-20 flex justify-center">
            <button @click.prevent="$wire.restart(); $el.blur()"
                class="text-gray-600 hover:text-[#d1d0c5] transition-all transform hover:scale-110 outline-none">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </button>
        </div>
    </div>
</div>

<script>
    function typingGame(initialText) {
        return {
            targetArray: initialText.split(''),
            currentIndex: 0,
            inputResults: [],
            startTime: null,
            timer: 0,
            wpm: 0,
            accuracy: 0,
            isStarted: false,
            isFinished: false,
            timerInterval: null,

            resetWithNewText(detail) {
                clearInterval(this.timerInterval);
                this.targetArray = detail.text.split('');
                this.currentIndex = 0;
                this.inputResults = [];
                this.startTime = null;
                this.isStarted = false;
                this.isFinished = false;
                this.wpm = 0;
                this.accuracy = 0;
                this.timer = (detail.main === 'time') ? parseInt(detail.sub) : 0;
            },

            handleInput(e) {
                if (this.isFinished) return;

                // Mencegah spasi membuat halaman scroll
                if (e.key === ' ') e.preventDefault();

                // Abaikan tombol fungsi (Shift, Alt, dll) tapi biarkan Backspace
                if (e.key.length > 1 && e.key !== 'Backspace') return;

                if (!this.isStarted) {
                    this.isStarted = true;
                    this.startTime = Date.now();
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
                    }, 1000);
                }

                if (e.key === 'Backspace') {
                    if (this.currentIndex > 0) {
                        this.currentIndex--;
                        this.inputResults.pop();
                    }
                    return;
                }

                // Simpan hasil input (benar/salah)
                this.inputResults[this.currentIndex] = (e.key === this.targetArray[this.currentIndex]);
                this.currentIndex++;

                if (this.currentIndex === this.targetArray.length) this.finish();
                this.calculateStats();
            },

            calculateStats() {
                if (!this.startTime) return;
                const timeElapsed = (Date.now() - this.startTime) / 60000;
                if (timeElapsed <= 0) return;
                const correctChars = this.inputResults.filter(r => r === true).length;
                this.wpm = Math.round((correctChars / 5) / timeElapsed) || 0;
                this.accuracy = Math.round((correctChars / this.currentIndex) * 100) || 0;
            },

            finish() {
                this.isFinished = true;
                clearInterval(this.timerInterval);
            }
        }
    }
</script>
