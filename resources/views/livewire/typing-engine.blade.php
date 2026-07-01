<div
    class="text-muted font-mono selection:bg-brand selection:text-foreground outline-none">
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

        <div class="max-w-5xl mx-auto px-4 pt-10 pb-16">

            @if (session('result_rejected'))
                <div
                    class="max-w-xl mx-auto mb-8 flex items-center gap-3 px-4 py-3 rounded-xl bg-danger/10 border border-danger/40 text-danger text-sm">
                    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
                    </svg>
                    <span class="font-sans">{{ session('result_rejected') }}</span>
                </div>
            @endif

            <!-- MODE CONTROL BAR (selaras Figma: Standard/Survival/Ghost → config → EN/ID) -->
            <!-- Saat mengetik, control bar di-fade DI TEMPAT (ruang tetap dipesan) supaya
                 area teks tidak melonjak ke atas. Sebelumnya pakai h-0/!mb-0 yang meng-collapse
                 tinggi → menyebabkan layout shift ~128px tiap kali mulai mengetik.
                 Opsi A: "Standard" hanya grup VISUAL; mainMode backend tetap time/words/quote. -->
            <div class="flex flex-col items-center gap-3 mb-2 transition-opacity duration-500"
                :class="isStarted ? 'opacity-0 pointer-events-none' : 'opacity-100'">

                <!-- Row 1: Mode utama (segment) -->
                <div class="inline-flex items-stretch gap-0.5 p-[3px] rounded-lg bg-surface border border-border"
                    role="group" aria-label="Pilih mode utama">
                    <button type="button" aria-label="Mode Standard"
                        :aria-pressed="['time','words','quote'].includes(currentMain)"
                        @click.prevent="if(!['time','words','quote'].includes(currentMain)){ currentMain='time'; currentSub='30'; $wire.setMode('time','30'); } $el.blur()"
                        class="px-[18px] py-[7px] rounded-md text-small font-mono font-bold transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand"
                        :class="['time','words','quote'].includes(currentMain) ? 'bg-brand text-foreground' : 'text-muted hover:text-foreground'">Standard</button>

                    <button type="button" aria-label="Mode Survival"
                        :aria-pressed="currentMain === 'survival'"
                        @click.prevent="currentMain='survival'; currentSub='medium'; $wire.setMode('survival','medium'); $el.blur()"
                        class="px-[18px] py-[7px] rounded-md text-small font-mono font-bold transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand"
                        :class="currentMain === 'survival' ? 'bg-brand text-foreground' : 'text-muted hover:text-foreground'">Survival</button>

                    <span aria-disabled="true" title="Segera hadir"
                        class="px-[18px] py-[7px] rounded-md text-small font-mono font-bold text-muted/50 cursor-not-allowed inline-flex items-center gap-1.5">
                        Ghost
                        <span class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
                    </span>
                </div>

                <!-- Row 2: Config (Standard → Time/Words/Quote + durasi; Survival → difficulty) -->
                <div class="flex items-center gap-1.5 min-h-[34px] text-small font-mono"
                    role="group" aria-label="Konfigurasi mode">
                    <!-- STANDARD: pemilih tipe + sub-konfigurasi -->
                    <template x-if="['time','words','quote'].includes(currentMain)">
                        <div class="flex items-center gap-1.5">
                            @foreach (['time' => 'Time', 'words' => 'Words', 'quote' => 'Quote'] as $type => $label)
                                <button type="button" aria-label="Tipe {{ $label }}"
                                    :aria-pressed="currentMain === '{{ $type }}'"
                                    @click.prevent="currentMain='{{ $type }}'; currentSub='{{ $type === 'time' ? '15' : ($type === 'words' ? '25' : 'medium') }}'; $wire.setMode('{{ $type }}', currentSub); $el.blur()"
                                    class="px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-brand"
                                    :class="currentMain === '{{ $type }}' ? 'bg-elevated border-border text-foreground font-bold' : 'border-border text-muted hover:text-foreground'">{{ $label }}</button>
                            @endforeach

                            <span class="w-px h-4 bg-border mx-1" aria-hidden="true"></span>

                            <template x-if="currentMain === 'time'">
                                <div class="flex gap-1.5">
                                    @foreach (['15', '30', '60', '120'] as $t)
                                        <button type="button" aria-label="Durasi {{ $t }} detik"
                                            :aria-pressed="currentSub == '{{ $t }}'"
                                            @click.prevent="currentSub='{{ $t }}'; $wire.setMode('time','{{ $t }}'); $el.blur()"
                                            class="px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-gold"
                                            :class="currentSub == '{{ $t }}' ? 'bg-gold border-gold text-background font-bold' : 'border-border text-muted hover:text-foreground'">{{ $t }}s</button>
                                    @endforeach
                                </div>
                            </template>
                            <template x-if="currentMain === 'words'">
                                <div class="flex gap-1.5">
                                    @foreach (['10', '25', '50', '100'] as $w)
                                        <button type="button" aria-label="{{ $w }} kata"
                                            :aria-pressed="currentSub == '{{ $w }}'"
                                            @click.prevent="currentSub='{{ $w }}'; $wire.setMode('words','{{ $w }}'); $el.blur()"
                                            class="px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-gold"
                                            :class="currentSub == '{{ $w }}' ? 'bg-gold border-gold text-background font-bold' : 'border-border text-muted hover:text-foreground'">{{ $w }}</button>
                                    @endforeach
                                </div>
                            </template>
                            <template x-if="currentMain === 'quote'">
                                <span class="px-2.5 py-1 text-muted italic text-x-small">kutipan acak</span>
                            </template>
                        </div>
                    </template>

                    <!-- SURVIVAL: difficulty -->
                    <template x-if="currentMain === 'survival'">
                        <div class="flex gap-1.5">
                            @foreach (['easy', 'medium', 'hard'] as $d)
                                <button type="button" aria-label="Tingkat {{ $d }}"
                                    :aria-pressed="currentSub == '{{ $d }}'"
                                    @click.prevent="currentSub='{{ $d }}'; $wire.setMode('survival','{{ $d }}'); $el.blur()"
                                    class="px-[14px] py-[6px] rounded-md border transition-all duration-150 outline-none capitalize hover:scale-[1.03] focus-visible:ring-2 focus-visible:ring-gold"
                                    :class="currentSub == '{{ $d }}' ? 'bg-gold border-gold text-background font-bold' : 'border-border text-muted hover:text-foreground'">{{ $d }}</button>
                            @endforeach
                        </div>
                    </template>
                </div>

                <!-- Row 3: Language switch (EN/ID) — tampil sesuai Figma, ID aktif; belum fungsional -->
                <div class="inline-flex items-stretch gap-0.5 p-[3px] rounded-lg bg-surface border border-border"
                    role="group" aria-label="Pilih bahasa (segera hadir)">
                    <span aria-disabled="true" title="Segera hadir"
                        class="px-[16px] py-[5px] rounded-md text-small font-mono font-bold text-muted/50 cursor-not-allowed">EN</span>
                    <span aria-pressed="true"
                        class="px-[16px] py-[5px] rounded-md text-small font-mono font-bold bg-brand text-foreground">ID</span>
                </div>
            </div>

            <template x-if="currentMain === 'survival'">
                <div class="mb-5 transition-opacity duration-300"
                    :class="isStarted ? 'opacity-100' : 'opacity-50'">
                    <div class="flex items-center justify-between mb-1.5">
                        <span class="font-sans text-[0.7rem] uppercase tracking-[0.25em] text-muted">stamina</span>
                        <span class="font-sans text-[0.7rem] tracking-[0.2em] text-muted capitalize"
                            x-text="currentSub"></span>
                    </div>
                    {{-- Warna fill via inline style (aman dari purge JIT); rgb dari token tema. --}}
                    <div class="w-full h-4 rounded-full bg-surface/80 border border-white/5 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-100 ease-linear"
                            :style="`width: ${staminaPct}%; background-color: rgb(${staminaPct > 50 ? 'var(--color-brand)' : (staminaPct > 25 ? 'var(--color-gold)' : 'var(--color-danger)')});`">
                        </div>
                    </div>
                </div>
            </template>

            <div class="group mb-6 transition-opacity duration-500"
                :class="!isStarted ? 'opacity-0' : (isFinished ? 'opacity-100' : 'opacity-60 hover:opacity-100')">
                <div class="flex items-start gap-10">
                    <div class="flex flex-col">
                        <span class="text-5xl font-mono font-bold tabular-nums leading-none transition-colors duration-300"
                            :class="(currentMain === 'time' && timer < 5 && isStarted) ? 'text-danger' : 'text-gold'"
                            aria-live="polite" x-text="currentMain === 'time' ? timer : timer + 's'">0</span>
                        <span class="text-x-small uppercase tracking-wide text-muted mt-2"
                            x-text="currentMain === 'time' ? 'left' : 'time'">time</span>
                    </div>

                    <div class="flex flex-col">
                        <span class="text-2xl text-muted font-mono font-bold tabular-nums leading-none" x-text="wpm">0</span>
                        <span class="text-x-small uppercase tracking-wide text-muted mt-1.5">wpm</span>
                    </div>

                    <div class="flex flex-col">
                        <span class="text-2xl text-muted font-mono font-bold tabular-nums leading-none">
                            <span x-text="accuracy">0</span>%
                        </span>
                        <span class="text-x-small uppercase tracking-wide text-muted mt-1.5">acc</span>
                    </div>
                </div>

                <div class="h-[28px] mt-2">
                    <svg x-show="wpmHistory.length > 1" x-cloak width="120" height="28"
                        viewBox="0 0 120 28" preserveAspectRatio="none" fill="none" aria-hidden="true">
                        <polyline :points="sparklinePoints" stroke="rgb(var(--color-brand))"
                            stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
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
                        class="absolute top-0 left-0 w-[2.5px] h-[1.5em] bg-brand transition-all duration-100 ease-out z-20 rounded"
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
                                        'text-foreground': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === true,
                                        'text-danger': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === false,
                                        'text-muted': {{ $charPointer }} >= currentIndex || ({{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === 'skipped'),
                                        'border-b-2 border-danger': {{ $charPointer }} < currentIndex && inputResults[{{ $charPointer }}] === 'skipped'
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
                                        class="char-element relative transition-colors duration-100 inline-block text-danger tracking-tight opacity-90">
                                        <span x-text="extra"></span>
                                    </span>
                                </template>
                            </template>

                            @if (!$loop->last)
                                <span id="char-{{ $charPointer }}"
                                    class="char-element relative w-[0.5em] inline-block"
                                    :class="inputResults[{{ $charPointer }}] === false ? 'bg-danger/30' : ''">
                                    &nbsp;
                                </span>
                                @php $charPointer++; @endphp
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="mt-12 flex justify-center">
                <button id="restartButton" @click.prevent="$wire.restart(); $el.blur()"
                    class="flex items-center gap-2 text-muted hover:text-foreground focus-visible:text-foreground focus-visible:ring-1 focus-visible:ring-border focus:bg-surface/60 transition-all transform hover:scale-105 outline-none px-4 py-2 rounded-xl hover:bg-surface/60">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24"
                        stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    <span class="font-sans text-xs uppercase tracking-widest">restart</span>
                    <kbd class="font-sans text-[0.6rem] px-1.5 py-0.5 rounded bg-surface border border-white/10">tab</kbd>
                    <span class="font-sans text-[0.6rem] text-muted">then</span>
                    <kbd class="font-sans text-[0.6rem] px-1.5 py-0.5 rounded bg-surface border border-white/10">enter</kbd>
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
            easy:   { sMax: 120, sStart: 120, graceSec: 4, dStart: 3.0, dAccel: 0.11, refill: 2.4, penalty: 7 },
            medium: { sMax: 100, sStart: 100, graceSec: 3, dStart: 3.8, dAccel: 0.20, refill: 1.9, penalty: 10 },
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
                // Membatalkan penilaian commit terakhir agar tak dinilai dua kali saat re-commit.
                // Penalti stamina untuk kata kotor TIDAK dikembalikan (aturan "kotor tetap kena
                // walau dikoreksi") — status 'dirty' DIPERTAHANKAN agar re-commit tak memotong lagi.
                uncommitWord(wordIndex) {
                    if (this.currentMain !== 'survival') return;

                    const prev = this.committedWordResults[wordIndex];
                    if (prev === undefined) return;

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

                // Ubah wpmHistory[] menjadi string `points` untuk <polyline> sparkline.
                // Auto-scale ke min/max history; getter reaktif Alpine (murni presentasi).
                get sparklinePoints() {
                    const h = this.wpmHistory;
                    if (h.length < 2) return '';
                    const w = 120, ht = 28, pad = 2;
                    const max = Math.max(...h), min = Math.min(...h);
                    const range = max - min || 1;
                    const stepX = w / (h.length - 1);
                    return h.map((v, i) => {
                        const x = i * stepX;
                        const y = ht - pad - ((v - min) / range) * (ht - pad * 2);
                        return `${x.toFixed(1)},${y.toFixed(1)}`;
                    }).join(' ');
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

                    this.$wire.saveResult(durationMs, total, correct, this.wpmHistory, this.rawHistory, this.missedChars);
                }
            }
        }
    </script>
