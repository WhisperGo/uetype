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
            <!-- Saat mengetik, selector di-fade DI TEMPAT (ruang tetap dipesan) supaya
                 area teks tidak melonjak ke atas. Sebelumnya pakai h-0/!mb-0 yang meng-collapse
                 tinggi → menyebabkan layout shift ~128px tiap kali mulai mengetik. -->
            <div class="flex flex-col items-center gap-3 mb-12 transition-opacity duration-500"
                :class="isStarted ? 'opacity-0 pointer-events-none' : 'opacity-100'">
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
                            @click.prevent="currentMain = 'survival'; currentSub = 'medium'; $wire.setMode('survival', 'medium'); $el.blur()"
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
                            <div class="flex gap-1.5">
                                @foreach (['easy', 'medium', 'hard'] as $d)
                                    <button
                                        @click.prevent="currentSub = '{{ $d }}'; $wire.setMode('survival', '{{ $d }}'); $el.blur()"
                                        class="px-2.5 py-1 rounded-lg transition-all duration-200 outline-none capitalize"
                                        :class="currentSub == '{{ $d }}' ?
                                            'text-typing-accent bg-typing-accent/10 ring-1 ring-typing-accent/40' :
                                            'hover:text-typing-text'">{{ $d }}</button>
                                @endforeach
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <!-- SURVIVAL: bar stamina terpadu (hanya saat mode survival) -->
            <template x-if="currentMain === 'survival'">
                <div class="mb-5 transition-opacity duration-300"
                    :class="isStarted ? 'opacity-100' : 'opacity-50'">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="font-sans text-[0.7rem] uppercase tracking-[0.25em] text-typing-muted">stamina</span>
                        <span class="font-sans text-[0.7rem] uppercase tracking-[0.2em] text-typing-muted capitalize"
                            x-text="currentSub"></span>
                    </div>
                    <!-- Track bar -->
                    <div class="w-full h-4 rounded-full bg-typing-surface/80 border border-white/5 overflow-hidden">
                        <!-- Fill: warna bergeser cyan→amber→rose sesuai sisa stamina.
                             Warna dipasang lewat inline background-color (bukan class Tailwind dinamis)
                             agar TIDAK ikut ke-purge JIT — bug sebelumnya: bar hilang di bawah 50%
                             karena class warna ternary tak ter-generate. -->
                        <div class="h-full rounded-full transition-all duration-100 ease-linear"
                            :style="`width: ${staminaPct}%; background-color: ${staminaPct > 50 ? '#22d3ee' : (staminaPct > 25 ? '#fbbf24' : '#f43f5e')};`">
                        </div>
                    </div>
                </div>
            </template>

            <!-- LIVE STATS -->
            <!-- Selalu dirender (ruang dipesan sejak awal) dan hanya di-fade lewat opacity —
                 BUKAN ditambah/dihapus dari DOM — supaya area teks tidak melonjak saat mulai
                 mengetik (nol layout shift). Fade dibuat lembut (500ms) agar tak mengagetkan.
                 Label waktu menyesuaikan mode: mode 'time' = hitung mundur; mode lain = stopwatch. -->
            <div class="flex gap-3 mb-6 transition-opacity duration-500"
                :class="isStarted ? 'opacity-100' : 'opacity-0'">
                <div class="flex-1 bg-typing-surface/60 border border-white/5 rounded-xl px-5 py-3">
                    <span class="text-[0.65rem] block text-typing-muted font-sans font-semibold uppercase tracking-[0.2em]">wpm</span>
                    <span class="text-4xl text-typing-accent font-mono font-bold tabular-nums" x-text="wpm">0</span>
                </div>
                <div class="flex-1 bg-typing-surface/60 border border-white/5 rounded-xl px-5 py-3">
                    <span class="text-[0.65rem] block text-typing-muted font-sans font-semibold uppercase tracking-[0.2em]">acc</span>
                    <span class="text-4xl text-typing-accent font-mono font-bold tabular-nums"><span x-text="accuracy">0</span>%</span>
                </div>
                <div class="flex-1 bg-typing-surface/60 border border-white/5 rounded-xl px-5 py-3">
                    <span class="text-[0.65rem] block text-typing-muted font-sans font-semibold uppercase tracking-[0.2em]"
                        x-text="currentMain === 'time' ? 'time' : 'waktu'">time</span>
                    <span class="text-4xl font-mono font-bold tabular-nums transition-colors duration-300"
                        :class="(currentMain === 'time' && timer < 5 && isStarted) ? 'text-typing-error' : 'text-typing-accent'"
                        x-text="currentMain === 'time' ? timer : timer + 's'">0</span>
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
        // Preset parameter Survival (stamina bar). Semua angka SEMENTARA — gampang di-tuning
        // saat playtest. Yang dikunci adalah polanya, bukan angkanya (lihat catatan revisi).
        //   sMax     : kapasitas bar (cap atas — cegah "menabung" stamina lalu santai)
        //   sStart   : stamina awal saat mulai
        //   graceSec : detik awal tanpa/dengan drain sangat lembut (biar pemain sempat "panas")
        //   dStart   : drain pasif awal per detik
        //   dAccel   : percepatan drain per detik² (escalation — drain makin deras seiring waktu)
        //   refill   : stamina bertambah per KARAKTER benar (berbasis char, bukan per-kata flat)
        //   penalty  : drain ekstra saat kata "kotor" di-commit (cap per-kata)
        const SURVIVAL_PRESETS = {
            easy:   { sMax: 120, sStart: 120, graceSec: 4, dStart: 2.2, dAccel: 0.08, refill: 2.6, penalty: 6 },
            medium: { sMax: 100, sStart: 100, graceSec: 3, dStart: 3.0, dAccel: 0.15, refill: 2.0, penalty: 8 },
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

                // --- State khusus Survival Mode (bar stamina terpadu) ---
                // Stamina menyusut seiring waktu (drain), terisi tiap karakter benar (refill),
                // terkuras ekstra saat kata kotor (penalti). Habis (0) = game over.
                stamina: 100,         // nilai stamina sekarang
                staminaMax: 100,      // kapasitas/cap bar (di-set dari preset difficulty)
                staminaPct: 100,      // persentase untuk UI (0–100)
                survivalCfg: null,    // preset parameter aktif (lihat SURVIVAL_PRESETS)
                staminaInterval: null,// loop tick drain (halus, ~100ms)
                lastTickTime: 0,      // timestamp tick terakhir (untuk Δt presisi)
                currentWordDirty: false, // apakah kata yang sedang diketik sudah pernah error
                wordsCompleted: 0,    // total kata selesai (stat sampingan)
                committedWordResults: {}, // {wordIndex: 'clean'|'dirty'} — kata yang sudah dinilai (idempoten)

                init() {
                    this.timer = (this.currentMain === 'time') ? parseInt(this.currentSub) : 0;

                    // Reset state survival tiap mulai/restart. Preset diambil dari currentSub
                    // (difficulty: 'easy'|'medium'|'hard'); mode lain tak terpengaruh.
                    this.survivalCfg = survivalConfig(this.currentSub);
                    this.staminaMax = this.survivalCfg.sMax;
                    this.stamina = this.survivalCfg.sStart;
                    this.staminaPct = Math.round((this.stamina / this.staminaMax) * 100);
                    this.lastTickTime = 0;
                    this.currentWordDirty = false;
                    this.wordsCompleted = 0;
                    this.committedWordResults = {};
                    if (this.staminaInterval) {
                        clearInterval(this.staminaInterval);
                        this.staminaInterval = null;
                    }
                    
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
                // Model STAMINA: kata kotor kena DRAIN EKSTRA tetap (penalti akurasi), cap per-kata.
                //   - kata bersih (nol error) → tak ada penalti (refill sudah datang dari karakter).
                //   - kata kotor (ada error apa pun, walau dikoreksi) → stamina -= penalty (sekali).
                // IDEMPOTEN per-index: kalau kata sama di-commit ulang (user backspace mundur lalu
                // maju lagi), penalti lama tak dikenakan dua kali — cap per-kata (revisi playtest).
                completeWord(wordIndex) {
                    this.wordsCompleted++;

                    if (this.currentMain !== 'survival') return;

                    const isDirty = this.currentWordDirty;
                    this.currentWordDirty = false;

                    // Apakah kata ini SUDAH pernah kena penalti pada commit sebelumnya?
                    // Cap per-kata: satu kata maksimal -1 penalti sepanjang hidupnya.
                    const alreadyPenalized = this.committedWordResults[wordIndex] === 'dirty';

                    if (isDirty) {
                        this.committedWordResults[wordIndex] = 'dirty';
                        if (!alreadyPenalized) {
                            this.stamina = Math.max(0, this.stamina - this.survivalCfg.penalty);
                            this.syncStaminaPct();
                            if (this.stamina <= 0) this.survivalGameOver();
                        }
                        return;
                    }

                    // Kata bersih: cukup catat (refill stamina sudah terjadi per-karakter saat diketik).
                    this.committedWordResults[wordIndex] = 'clean';
                },

                // Dipanggil saat user backspace mundur ke kata sebelumnya untuk mengoreksi.
                // Membatalkan hitung kata dari commit terakhir agar tak double-count saat re-commit.
                // Penalti stamina untuk kata kotor TIDAK dikembalikan (aturan "kotor tetap kena
                // walau dikoreksi") — status 'dirty' DIPERTAHANKAN agar re-commit tak memotong lagi.
                uncommitWord(wordIndex) {
                    if (this.currentMain !== 'survival') return;

                    const prev = this.committedWordResults[wordIndex];
                    if (prev === undefined) return;

                    this.wordsCompleted = Math.max(0, this.wordsCompleted - 1);

                    if (prev === 'clean') {
                        // Kata tadinya bersih: lupakan total, dinilai ulang dari nol saat re-commit.
                        delete this.committedWordResults[wordIndex];
                    }
                    // Jika 'dirty': biarkan tetap 'dirty' agar penalti tak dikenakan dua kali.
                },

                // Refill stamina tiap satu karakter benar (cap di staminaMax). No-op di luar survival.
                refillStamina() {
                    if (this.currentMain !== 'survival' || this.isFinished) return;
                    this.stamina = Math.min(this.staminaMax, this.stamina + this.survivalCfg.refill);
                    this.syncStaminaPct();
                },

                // Satu tick drain pasif. Δt = detik sejak tick sebelumnya (presisi, bukan asumsi
                // interval tetap). Drain naik seiring waktu (escalation) dengan grace di awal.
                staminaTick() {
                    if (this.currentMain !== 'survival' || this.isFinished || !this.startTime) return;

                    const now = Date.now();
                    const dt = this.lastTickTime ? (now - this.lastTickTime) / 1000 : 0;
                    this.lastTickTime = now;
                    if (dt <= 0) return;

                    const elapsed = (now - this.startTime) / 1000;
                    const cfg = this.survivalCfg;

                    // Grace: beberapa detik pertama drain dilembutkan agar awal tak terasa kasar.
                    const graceFactor = elapsed < cfg.graceSec ? (elapsed / cfg.graceSec) : 1;

                    // D = D_start + D_accel * elapsed  (escalation: makin lama makin deras).
                    const drainPerSec = (cfg.dStart + cfg.dAccel * elapsed) * graceFactor;

                    this.stamina = Math.max(0, this.stamina - drainPerSec * dt);
                    this.syncStaminaPct();

                    if (this.stamina <= 0) this.survivalGameOver();
                },

                // Sinkronkan persentase bar untuk UI (0–100).
                syncStaminaPct() {
                    this.staminaPct = Math.max(0, Math.min(100, Math.round((this.stamina / this.staminaMax) * 100)));
                },

                // Game over survival: stamina habis. Hentikan loop tick lalu selesaikan sesi
                // lewat jalur finish() yang sama dengan mode lain.
                survivalGameOver() {
                    if (this.isFinished) return;
                    if (this.staminaInterval) {
                        clearInterval(this.staminaInterval);
                        this.staminaInterval = null;
                    }
                    this.finish();
                },

                destroy() {
                    clearInterval(this.timerInterval);
                    if (this.staminaInterval) clearInterval(this.staminaInterval);
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

                        // Survival: jalankan loop drain stamina yang halus (~100ms) agar tekanan
                        // terasa mulus (bukan patah-patah per detik). Drain & game over di staminaTick.
                        if (this.currentMain === 'survival') {
                            this.lastTickTime = this.startTime;
                            this.staminaInterval = setInterval(() => this.staminaTick(), 100);
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
                            this.refillStamina();      // survival: spasi benar juga me-refill
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
                        this.refillStamina(); // survival: karakter benar me-refill stamina
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
                    if (this.staminaInterval) {
                        clearInterval(this.staminaInterval);
                        this.staminaInterval = null;
                    }

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
