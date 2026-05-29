<div
    class="min-h-screen bg-[#323437] text-[#646669] font-mono selection:bg-yellow-500 selection:text-black outline-none">
    <div wire:key="typing-app-{{ str()->random(10) }}" x-data="{
        currentMain: @entangle('mainMode'),
        currentSub: @entangle('subMode'),
        ...typingGame(@js($textToType))
    }"
        @keydown.window="
            if($event.key === 'Tab') {
                $event.preventDefault();
                document.getElementById('restartButton').focus(); 
            } else if (document.activeElement.tagName !== 'BUTTON') {
                handleInput($event);
            }
        ">

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

            <!-- Kontainer 3 Baris -->
            <div class="relative overflow-hidden text-3xl leading-relaxed tracking-tight select-none outline-none"
                style="max-height: 4.875em;">
                <div x-ref="textContainer"
                    class="relative flex flex-wrap content-start gap-x-[0.5em] transition-transform duration-200 ease-in-out"
                    :style="`transform: translateY(-${scrollOffset}px)`">

                    <!-- SINGLE SMOOTH CURSOR -->
                    <div x-show="!isFinished"
                        class="absolute top-0 left-0 w-[2.5px] h-[1.5em] bg-yellow-500 transition-all duration-100 ease-out z-20 rounded"
                        :style="`transform: translate(${cursorLeft}px, ${cursorTop}px);`"
                        :class="isTyping ? '' : 'animate-[pulse_0.8s_infinite]'">
                    </div>

                    @php
                        $words = explode(' ', $textToType);
                        $charPointer = 0;
                    @endphp

                    @foreach ($words as $word)
                        <div class="flex" wire:key="word-{{ $loop->index }}-{{ $textToType }}">
                            @foreach (str_split($word) as $char)
                                <span id="char-{{ $charPointer }}" class="char-element relative transition-colors duration-100 inline-block"
                                    :class="{
                                        'text-[#d1d0c5]': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === true,
                                        'text-[#ca4754] border-b-2 border-[#ca4754]': {{ $charPointer }} < currentIndex && (inputResults[{{ $charPointer }}] === false || inputResults[{{ $charPointer }}] === 'skipped'),
                                        'text-[#646669]': {{ $charPointer }} >= currentIndex
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
                                        class="char-element relative transition-colors duration-100 inline-block text-[#ca4754] tracking-tight opacity-90">
                                        <span x-text="extra"></span>
                                    </span>
                                </template>
                            </template>

                            @if (!$loop->last)
                                <span id="char-{{ $charPointer }}"
                                    class="char-element relative w-[0.5em] inline-block"
                                    :class="inputResults[{{ $charPointer }}] === false ? 'bg-[#ca4754]/30' : ''">
                                    &nbsp;
                                </span>
                                @php $charPointer++; @endphp
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-20 flex justify-center">
                <button id="restartButton" @click.prevent="$wire.restart(); $el.blur()"
                    class="text-gray-600 hover:text-[#d1d0c5] focus:text-yellow-500 focus:scale-110 transition-all transform hover:scale-110 outline-none p-2 rounded-xl">
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
                scrollOffset: 0,
                lineHeight: 0,
                currentWordIndex: 0,
                wordBounds: [],
                extraChars: {},
                cursorLeft: 0,
                cursorTop: 0,
                isTyping: false,
                typingTimeout: null,
                totalKeystrokes: 0,
                correctKeystrokes: 0,
                wpmHistory: [],
                rawHistory: [],
                missedChars: {},

                init() {
                    this.timer = (this.currentMain === 'time') ? parseInt(this.currentSub) : 0;
                    
                    this.wordBounds = [];
                    this.extraChars = {};
                    this.currentWordIndex = 0;
                    this.totalKeystrokes = 0;
                    this.correctKeystrokes = 0;
                    this.wpmHistory = [];
                    this.rawHistory = [];
                    this.missedChars = {};
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

                    this.$nextTick(() => {
                        this.updatePosition();
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

                destroy() {
                    clearInterval(this.timerInterval);
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

                    let left = activeEl.offsetLeft;
                    let top = activeEl.offsetTop;

                    // Update Posisi Kursor Tunggal
                    this.cursorLeft = isEnd ? left + activeEl.offsetWidth : left;
                    this.cursorTop = top;

                    // Update Scroll
                    const chars = this.$refs.textContainer.querySelectorAll('.char-element');
                    if (chars.length > 0 && !this.lineHeight) {
                        for (let i = 1; i < chars.length; i++) {
                            if (chars[i].offsetTop > chars[0].offsetTop) {
                                this.lineHeight = chars[i].offsetTop - chars[0].offsetTop;
                                break;
                            }
                        }
                    }

                    const containerTop = chars.length > 0 ? chars[0].offsetTop : 0;
                    const currentTop = top - containerTop;
                    const lh = this.lineHeight || 48;

                    if (currentTop >= lh * 2) {
                        this.scrollOffset = currentTop - lh;
                    } else {
                        this.scrollOffset = 0;
                    }
                },

                handleInput(e) {
                    if (this.isFinished) return;
                    if (e.key === ' ') e.preventDefault();
                    if (e.key.length > 1 && e.key !== 'Backspace') return;

                    // Efek kursor berhenti berkedip saat mengetik
                    this.isTyping = true;
                    clearTimeout(this.typingTimeout);
                    this.typingTimeout = setTimeout(() => {
                        this.isTyping = false;
                    }, 500);

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
                        if (this.currentIndex > 0) {
                            // Jika sedang di spasi, dan ada extra chars, hapus satu ekstra hurufnya
                            if (this.extraChars[this.currentWordIndex] && this.extraChars[this.currentWordIndex].length >
                                0) {
                                this.extraChars[this.currentWordIndex].pop();
                                this.$nextTick(() => this.updatePosition());
                                return;
                            }

                            // Jika kursor berada di awal kata saat ini
                            if (this.currentIndex === bounds.start) {
                                // Boleh mundur ke kata sebelumnya JIKA ada error di kata tersebut
                                if (this.currentWordIndex > 0) {
                                    let prevWordIdx = this.currentWordIndex - 1;
                                    if (this.wordHasError(prevWordIdx)) {
                                        this.currentWordIndex--;
                                        
                                        let prevBounds = this.wordBounds[this.currentWordIndex];
                                        let jumpIndex = prevBounds.space;
                                        
                                        // Hapus status pada spasi
                                        this.inputResults[jumpIndex] = null;
                                        
                                        // Bersihkan status 'skipped' dan lompat mundur melewati huruf-huruf yang tidak pernah diketik
                                        while(jumpIndex > prevBounds.start && this.inputResults[jumpIndex - 1] === 'skipped') {
                                            jumpIndex--;
                                            this.inputResults[jumpIndex] = null;
                                        }
                                        
                                        this.currentIndex = jumpIndex;
                                        this.$nextTick(() => this.updatePosition());
                                    }
                                }
                                return;
                            }

                            // Backspace normal di dalam kata
                            this.currentIndex--;
                            this.inputResults[this.currentIndex] = null;
                            this.$nextTick(() => this.updatePosition());
                        }
                        return;
                    }

                    // Mulai dari titik ini, berarti user menekan tuts karakter/spasi (bukan backspace)
                    this.totalKeystrokes++;

                    // Jika kursor sedang di posisi spasi pembatas antar kata
                    if (this.currentIndex === bounds.space) {
                        if (e.key !== ' ') {
                            // OVERTYPING: Tambahkan ke ekstra karakter
                            if (!this.extraChars[this.currentWordIndex]) this.extraChars[this.currentWordIndex] = [];
                            if (this.extraChars[this.currentWordIndex].length < 15) {
                                this.extraChars[this.currentWordIndex].push(e.key);
                            }
                            this.calculateStats();
                            this.$nextTick(() => this.updatePosition());
                            return;
                        } else {
                            // SPASI DITEKAN: Pindah ke kata selanjutnya
                            this.correctKeystrokes++; // Spasi di akhir kata adalah tuts benar
                            this.inputResults[this.currentIndex] = true;
                            this.currentIndex++;
                            this.currentWordIndex++;
                            if (this.currentIndex === this.targetArray.length) this.finish();
                            this.calculateStats();
                            this.$nextTick(() => this.updatePosition());
                            return;
                        }
                    }

                    // Jika user menekan spasi di tengah kata (belum selesai)
                    if (e.key === ' ') {
                        for (let i = this.currentIndex; i <= bounds.end; i++) {
                            this.inputResults[i] = 'skipped'; // Tandai terlewat
                            
                            // Track missed character
                            const expectedChar = this.targetArray[i].toLowerCase();
                            if (expectedChar !== ' ' && expectedChar.length === 1) {
                                this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                            }
                        }
                        if (bounds.space !== null) {
                            this.inputResults[bounds.space] = 'skipped'; // Jangan berikan WPM gratis untuk spasi yang di-skip
                            this.currentIndex = bounds.space + 1;
                            this.currentWordIndex++;
                        } else {
                            this.currentIndex = this.targetArray.length;
                            this.finish();
                        }
                        this.calculateStats();
                        this.$nextTick(() => this.updatePosition());
                        return;
                    }

                    // Pengetikan normal
                    const isCorrect = (e.key === this.targetArray[this.currentIndex]);
                    if (isCorrect) {
                        this.correctKeystrokes++;
                    } else {
                        // Track missed character
                        const expectedChar = this.targetArray[this.currentIndex].toLowerCase();
                        if (expectedChar !== ' ' && expectedChar.length === 1) {
                            this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                        }
                    }
                    
                    this.inputResults[this.currentIndex] = isCorrect;
                    this.currentIndex++;

                    if (this.currentIndex === this.targetArray.length) this.finish();
                    this.calculateStats();
                    this.$nextTick(() => this.updatePosition());
                },

                calculateStats() {
                    if (!this.startTime) return;
                    
                    const elapsedMs = Date.now() - this.startTime;
                    
                    // Pencegahan WPM meledak (infinite/ribuan) di awal ketikan
                    // Kita asumsikan minimal waktu berlalu adalah 1 detik untuk kalkulasi live
                    const effectiveMs = (elapsedMs < 1000 && !this.isFinished) ? 1000 : elapsedMs;
                    const timeElapsed = effectiveMs / 60000;
                    
                    if (timeElapsed <= 0) return;

                    // 1. WPM Calculation (Berdasarkan jumlah tuts benar dibagi 5)
                    // Pada mode Monkeytype default, setiap ketikan benar akan menyumbang ke WPM,
                    // dan kita telah memastikan spasi 'skip' tidak lagi terhitung sebagai tuts benar.
                    const correctChars = this.inputResults.filter(r => r === true).length;
                    this.wpm = Math.round((correctChars / 5) / timeElapsed) || 0;
                    
                    // 2. Accuracy Calculation (Monkeytype style: based on physical keystrokes)
                    if (this.totalKeystrokes > 0) {
                        this.accuracy = Math.round((this.correctKeystrokes / this.totalKeystrokes) * 100);
                    } else {
                        this.accuracy = 0;
                    }
                },

                finish() {
                    this.isFinished = true;
                    clearInterval(this.timerInterval);

                    let timeSpent = this.timer;
                    if (this.currentMain === 'time') {
                        timeSpent = parseInt(this.currentSub) - this.timer;
                    }

                    let correct = this.correctKeystrokes || this.inputResults.filter(r => r === true).length;
                    let total = this.totalKeystrokes || this.currentIndex;

                    this.$wire.saveResult(this.wpm, this.accuracy, timeSpent, total, correct, this.wpmHistory, this.rawHistory, this.missedChars);
                }
            }
        }
    </script>
