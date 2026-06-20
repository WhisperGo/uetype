<div
    class="min-h-screen bg-typing-bg text-typing-muted font-mono selection:bg-typing-accent selection:text-typing-bg outline-none">
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

        <div class="max-w-5xl mx-auto pt-16 px-4">

            @if (session('result_rejected'))
                <div
                    class="max-w-xl mx-auto mb-8 flex items-center gap-3 px-4 py-3 rounded-xl bg-typing-error/10 border border-typing-error/40 text-typing-error text-sm">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
                    </svg>
                    <span class="font-sans">{{ session('result_rejected') }}</span>
                </div>
            @endif

            <!-- MODE SELECTOR -->
            <div class="flex flex-col items-center gap-3 mb-12 transition-all duration-500"
                :class="isStarted ? 'opacity-0 -translate-y-10 pointer-events-none h-0 !mb-0 overflow-hidden' : 'opacity-100'">
                <span class="font-sans text-xs uppercase tracking-[0.3em] text-typing-muted">pilih mode</span>
                <div
                    class="flex items-center gap-5 bg-typing-surface/80 backdrop-blur px-5 py-2.5 rounded-2xl text-sm font-mono border border-white/10 shadow-glow">
                    <div class="flex items-center gap-1 border-r border-white/10 pr-5 font-semibold">
                        <button
                            @click.prevent="currentMain = 'time'; currentSub = '30'; $wire.setMode('time', '30'); $el.blur()"
                            class="transition-all duration-200 py-1.5 px-3 rounded-lg outline-none"
                            :class="currentMain === 'time' ? 'text-typing-bg bg-typing-accent' : 'text-typing-muted hover:text-typing-text'">time</button>

                        <button
                            @click.prevent="currentMain = 'words'; currentSub = '50'; $wire.setMode('words', '50'); $el.blur()"
                            class="transition-all duration-200 py-1.5 px-3 rounded-lg outline-none"
                            :class="currentMain === 'words' ? 'text-typing-bg bg-typing-accent' : 'text-typing-muted hover:text-typing-text'">words</button>

                        <button
                            @click.prevent="currentMain = 'quote'; currentSub = 'medium'; $wire.setMode('quote', 'medium'); $el.blur()"
                            class="transition-all duration-200 py-1.5 px-3 rounded-lg outline-none"
                            :class="currentMain === 'quote' ? 'text-typing-bg bg-typing-accent' : 'text-typing-muted hover:text-typing-text'">quote</button>

                        <button
                            @click.prevent="currentMain = 'survival'; currentSub = 'classic'; $wire.setMode('survival', 'classic'); $el.blur()"
                            class="transition-all duration-200 py-1.5 px-3 rounded-lg outline-none"
                            :class="currentMain === 'survival' ? 'text-typing-bg bg-typing-accent' : 'text-typing-muted hover:text-typing-text'">survival</button>
                    </div>

                    <div class="flex items-center gap-1.5 text-typing-muted font-semibold">
                        <template x-if="currentMain === 'time'">
                            <div class="flex gap-1.5">
                                @foreach (['15', '30', '60', '120'] as $t)
                                    <button
                                        @click.prevent="currentSub = '{{ $t }}'; $wire.setMode('time', '{{ $t }}'); $el.blur()"
                                        class="px-2.5 py-1 rounded-lg transition-all duration-200 outline-none"
                                        :class="currentSub == '{{ $t }}' ?
                                            'text-typing-accent bg-typing-accent/10 ring-1 ring-typing-accent/40' :
                                            'hover:text-typing-text'">{{ $t }}</button>
                                @endforeach
                            </div>
                        </template>
                        <template x-if="currentMain === 'words'">
                            <div class="flex gap-1.5">
                                @foreach (['10', '25', '50', '100'] as $w)
                                    <button
                                        @click.prevent="currentSub = '{{ $w }}'; $wire.setMode('words', '{{ $w }}'); $el.blur()"
                                        class="px-2.5 py-1 rounded-lg transition-all duration-200 outline-none"
                                        :class="currentSub == '{{ $w }}' ?
                                            'text-typing-accent bg-typing-accent/10 ring-1 ring-typing-accent/40' :
                                            'hover:text-typing-text'">{{ $w }}</button>
                                @endforeach
                            </div>
                        </template>
                        <template x-if="currentMain === 'quote'">
                            <span class="px-2.5 py-1 text-typing-muted italic text-xs">kutipan acak</span>
                        </template>
                        <template x-if="currentMain === 'survival'">
                            <span class="px-2.5 py-1 text-typing-muted italic text-xs">bertahan selama mungkin</span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- SURVIVAL: indikator nyawa & streak (hanya saat mode survival) -->
            <template x-if="currentMain === 'survival'">
                <div class="flex items-center justify-between gap-4 mb-4 transition-opacity duration-300"
                    :class="isStarted ? 'opacity-100' : 'opacity-50'">
                    <div class="flex items-center gap-1.5">
                        <template x-for="n in maxLives" :key="n">
                            <svg class="w-7 h-7 transition-all duration-300"
                                :class="n <= lives ? 'text-typing-error scale-100' : 'text-typing-surface scale-90'"
                                fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z" />
                            </svg>
                        </template>
                    </div>
                    <div class="flex items-center gap-2 font-sans text-[0.7rem] uppercase tracking-[0.2em] text-typing-muted">
                        <span>kata bersih</span>
                        <span class="font-mono text-typing-accent font-bold" x-text="cleanWordStreak"></span>
                        <span class="text-typing-muted">/ <span x-text="regenThreshold"></span></span>
                    </div>
                </div>
            </template>

            <!-- LIVE STATS -->
            <div class="flex gap-3 mb-6 transition-opacity duration-300"
                :class="isStarted ? 'opacity-100' : 'opacity-0'">
                <div class="flex-1 bg-typing-surface/60 border border-white/5 rounded-xl px-5 py-3">
                    <span class="text-[0.65rem] block text-typing-muted font-sans font-semibold uppercase tracking-[0.2em]">wpm</span>
                    <span class="text-4xl text-typing-accent font-mono font-bold" x-text="wpm">0</span>
                </div>
                <div class="flex-1 bg-typing-surface/60 border border-white/5 rounded-xl px-5 py-3">
                    <span class="text-[0.65rem] block text-typing-muted font-sans font-semibold uppercase tracking-[0.2em]">acc</span>
                    <span class="text-4xl text-typing-accent font-mono font-bold"><span x-text="accuracy">0</span>%</span>
                </div>
                <div class="flex-1 bg-typing-surface/60 border border-white/5 rounded-xl px-5 py-3">
                    <span class="text-[0.65rem] block text-typing-muted font-sans font-semibold uppercase tracking-[0.2em]">time</span>
                    <span class="text-4xl font-mono font-bold transition-colors duration-300"
                        :class="(currentMain === 'time' && timer < 5 && isStarted) ? 'text-typing-error' : 'text-typing-accent'"
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
                        class="absolute top-0 left-0 w-[2.5px] h-[1.5em] bg-typing-accent transition-all duration-100 ease-out z-20 rounded"
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
                                        'text-typing-text': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === true,
                                        'text-typing-error': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === false,
                                        'text-typing-muted': {{ $charPointer }} >= currentIndex || ({{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === 'skipped'),
                                        'border-b-2 border-typing-error': {{ $charPointer }} < currentIndex && (inputResults[{{ $charPointer }}] === false || inputResults[{{ $charPointer }}] === 'skipped')
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
                                        class="char-element relative transition-colors duration-100 inline-block text-typing-error tracking-tight opacity-90">
                                        <span x-text="extra"></span>
                                    </span>
                                </template>
                            </template>

                            @if (!$loop->last)
                                <span id="char-{{ $charPointer }}"
                                    class="char-element relative w-[0.5em] inline-block"
                                    :class="inputResults[{{ $charPointer }}] === false ? 'bg-typing-error/30' : ''">
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
                    class="flex items-center gap-2 text-typing-muted hover:text-typing-text focus:text-typing-accent focus:scale-105 transition-all transform hover:scale-105 outline-none px-4 py-2 rounded-xl hover:bg-typing-surface/60">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span class="font-sans text-xs uppercase tracking-widest">restart</span>
                    <kbd class="font-sans text-[0.6rem] px-1.5 py-0.5 rounded bg-typing-surface border border-white/10">tab</kbd>
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
                rawWpm: 0,
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

                // --- State khusus Survival Mode ---
                // Nyawa dipotong PER-KATA (maks -1/kata), pulih tiap 10 kata bersih (regen).
                lives: 5,
                maxLives: 5,
                regenThreshold: 10,   // jumlah kata bersih untuk +1 nyawa (revisi playtest: 20→10)
                cleanWordStreak: 0,   // kata bersih beruntun (reset saat kata kotor)
                currentWordDirty: false, // apakah kata yang sedang diketik sudah pernah error
                wordsCompleted: 0,    // total kata selesai (skor survival = ini saat mati)
                committedWordResults: {}, // {wordIndex: 'clean'|'dirty'} — kata yang sudah dinilai (idempoten)

                init() {
                    this.timer = (this.currentMain === 'time') ? parseInt(this.currentSub) : 0;

                    // Reset state survival tiap mulai/restart.
                    this.lives = this.maxLives;
                    this.cleanWordStreak = 0;
                    this.currentWordDirty = false;
                    this.wordsCompleted = 0;
                    this.committedWordResults = {};
                    
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

                // --- Helper Survival ---

                // Dipanggil tiap kali terjadi error di kata aktif (typo / huruf di-skip /
                // karakter berlebih). Hanya MENANDAI kata sebagai "kotor" — TIDAK memotong
                // nyawa di sini. Pemotongan nyawa terjadi sekali saat kata di-commit, maksimal
                // -1 per kata berapa pun jumlah errornya (revisi playtest: per-kata, bukan
                // per-karakter). Koreksi tidak menghapus status kotor. No-op di luar survival.
                markWordDirty() {
                    if (this.currentMain !== 'survival' || this.isFinished) return;
                    this.currentWordDirty = true;
                },

                // Dipanggil tiap satu kata selesai (spasi ditekan / kata di-skip).
                // Di sinilah nyawa dicek — basis PER-KATA:
                //   - kata kotor (ada error apa pun, walau dikoreksi) → -1 nyawa, streak reset.
                //   - kata bersih (nol error) → streak +1; tiap 10 kata bersih → regen +1 nyawa.
                // Bersih & kotor saling eksklusif: satu kata tak bisa sekaligus regen & potong.
                // IDEMPOTEN per-index: kalau kata yang sama di-commit ulang (mis. user backspace
                // mundur lalu maju lagi), penilaian lama dibatalkan dulu lewat uncommitWord —
                // sehingga satu kata tetap berkontribusi maksimal -1 nyawa (revisi playtest).
                completeWord(wordIndex) {
                    this.wordsCompleted++;

                    if (this.currentMain !== 'survival') return;

                    const isDirty = this.currentWordDirty;
                    this.currentWordDirty = false;

                    // Apakah kata ini SUDAH pernah memotong nyawa pada commit sebelumnya?
                    // (Terjadi bila user mundur untuk koreksi lalu maju lagi.) Cap per-kata:
                    // satu kata maksimal -1 nyawa sepanjang hidupnya — jangan potong dua kali.
                    const alreadyPenalized = this.committedWordResults[wordIndex] === 'dirty';

                    if (isDirty) {
                        // Tandai kotor (permanen untuk kata ini sampai uncommit penuh).
                        this.committedWordResults[wordIndex] = 'dirty';
                        this.cleanWordStreak = 0;

                        // Potong nyawa hanya jika belum pernah dipotong untuk kata ini.
                        if (!alreadyPenalized) {
                            this.lives = Math.max(0, this.lives - 1);
                            if (this.lives <= 0) {
                                this.finish();
                            }
                        }
                        return;
                    }

                    // Kata bersih: catat, tambah streak, regen nyawa bila mencapai ambang.
                    this.committedWordResults[wordIndex] = 'clean';
                    this.cleanWordStreak++;
                    if (this.cleanWordStreak >= this.regenThreshold) {
                        this.cleanWordStreak = 0;
                        this.lives = Math.min(this.maxLives, this.lives + 1);
                    }
                },

                // Dipanggil saat user backspace mundur ke kata sebelumnya untuk mengoreksi.
                // Membatalkan kontribusi STREAK & hitung kata dari commit terakhir, supaya saat
                // kata di-commit ulang tidak double-count. Yang TIDAK dibatalkan: potongan nyawa
                // untuk kata kotor (aturan "kotor tetap -1 walau dikoreksi") — status 'dirty'
                // sengaja DIPERTAHANKAN di committedWordResults agar commit ulang tahu nyawa sudah
                // terpotong dan tidak memotong lagi (cap per-kata).
                uncommitWord(wordIndex) {
                    if (this.currentMain !== 'survival') return;

                    const prev = this.committedWordResults[wordIndex];
                    if (prev === undefined) return;

                    this.wordsCompleted = Math.max(0, this.wordsCompleted - 1);

                    if (prev === 'clean') {
                        // Kata tadinya bersih: cabut kontribusinya ke streak, dan lupakan total —
                        // saat di-commit ulang akan dinilai dari nol lagi.
                        if (this.cleanWordStreak > 0) this.cleanWordStreak--;
                        delete this.committedWordResults[wordIndex];
                    }
                    // Jika 'dirty': biarkan tetap 'dirty' agar nyawa tak terpotong dua kali.
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

                                        // Survival: kita kembali masuk ke kata ini untuk koreksi.
                                        // Batalkan penilaian commit-nya agar tidak dihitung dua kali;
                                        // kata akan dinilai ulang saat di-commit kembali nanti.
                                        // currentWordDirty di-set true: kata yang sempat punya error
                                        // tetap "kotor" walau dikoreksi (koreksi tak memberi kekebalan).
                                        this.uncommitWord(this.currentWordIndex);
                                        this.markWordDirty();

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
                            // Survival: karakter berlebih = error → tandai kata kotor (nyawa
                            // dipotong nanti saat kata di-commit, maks -1 per kata).
                            this.markWordDirty();
                            this.calculateStats();
                            this.$nextTick(() => this.updatePosition());
                            return;
                        } else {
                            // SPASI DITEKAN: Pindah ke kata selanjutnya
                            this.correctKeystrokes++; // Spasi di akhir kata adalah tuts benar
                            this.inputResults[this.currentIndex] = true;
                            this.currentIndex++;
                            this.currentWordIndex++;
                            this.completeWord(this.currentWordIndex - 1); // survival: nilai kata yang baru selesai
                            if (this.currentIndex === this.targetArray.length) this.finish();
                            this.calculateStats();
                            this.$nextTick(() => this.updatePosition());
                            return;
                        }
                    }

                    // Jika user menekan spasi di tengah kata (belum selesai)
                    if (e.key === ' ') {
                        // Mencegah spam spasi: Abaikan spasi jika user belum mengetik huruf apapun di kata ini
                        if (this.currentIndex === bounds.start) {
                            return;
                        }

                        for (let i = this.currentIndex; i <= bounds.end; i++) {
                            this.inputResults[i] = 'skipped'; // Tandai terlewat

                            // Track missed character
                            const expectedChar = this.targetArray[i].toLowerCase();
                            if (expectedChar !== ' ' && expectedChar.length === 1) {
                                this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                            }
                        }
                        // Survival: melewati huruf = error → tandai kata kotor (potongan nyawa
                        // terjadi sekali saat completeWord, maks -1 untuk kata ini).
                        this.markWordDirty();
                        if (bounds.space !== null) {
                            this.inputResults[bounds.space] = 'skipped'; // Jangan berikan WPM gratis untuk spasi yang di-skip
                            this.currentIndex = bounds.space + 1;
                            this.currentWordIndex++;
                            this.completeWord(this.currentWordIndex - 1); // kata (ternoda) tetap terhitung selesai
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
                        // Survival: typo → tandai kata kotor saja. Nyawa baru dipotong saat
                        // kata di-commit (maks -1 per kata), bukan per-karakter.
                        this.markWordDirty();
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

                    // 1. Net WPM — pakai correctKeystrokes (SUMBER YANG SAMA dengan finish/server),
                    // termasuk spasi antar-kata yang benar (definisi Monkeytype). Ini memastikan
                    // angka live == angka di result page (tidak ada lagi WPM "gratis" saat finish).
                    this.wpm = Math.round((this.correctKeystrokes / 5) / timeElapsed) || 0;

                    // 1b. Raw WPM (mengabaikan error: total tuts / 5) — stat sampingan
                    this.rawWpm = Math.round((this.totalKeystrokes / 5) / timeElapsed) || 0;

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

                    // Durasi PRESISI (ms) dari keystroke pertama sampai sekarang — sumber yang
                    // sama dengan perhitungan live, supaya WPM final == WPM saat mengetik.
                    // (Sebelumnya pakai detik bulat 'subMode - timer' → durasi mengecil → WPM "gratis".)
                    const durationMs = this.startTime ? (Date.now() - this.startTime) : 0;

                    // Pembilang konsisten: pakai correctKeystrokes (sudah termasuk spasi antar-kata
                    // yang benar, sama seperti definisi Monkeytype).
                    const correct = this.correctKeystrokes;
                    const total = this.totalKeystrokes;

                    this.$wire.saveResult(durationMs, total, correct, this.wpmHistory, this.rawHistory, this.missedChars, this.wordsCompleted);
                }
            }
        }
    </script>
