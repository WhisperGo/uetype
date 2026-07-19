/**
 * Mesin ketik solo (Time / Words / Survival), termasuk Ghost Mode.
 *
 * Sebelumnya 846 baris ini hidup sebagai <script> inline di dalam
 * typing-engine.blade.php: tak bisa di-lint, tak bisa di-minify, tak bisa
 * di-cache browser sebagai aset terpisah, dan ikut terkirim ulang setiap kali
 * halaman /typing dimuat.
 *
 * Dipasang lewat spread di x-data (`...typingGame(teks)`) karena komponen ini
 * berbagi scope dengan dua properti @entangle (currentMain/currentSub) yang
 * ditulis dua arah dari tombol pemilih mode di markup.
 *
 * PENTING: karena objek ini di-SPREAD, jangan pernah memakai getter di sini --
 * spread mengevaluasi getter satu kali lalu membekukan hasilnya. Pakai method
 * biasa (lihat sparklinePoints()).
 */
// Preset stamina Survival.
//   sMax/sStart : kapasitas & stamina awal
//   graceSec    : detik awal dengan drain dilembutkan
//   dStart      : drain pasif per detik
//   dAccel      : percepatan drain per detik²
//   refill      : stamina per karakter benar
//   penalty     : drain ekstra saat kata kotor di-commit (cap per-kata)
const SURVIVAL_PRESETS = {
    easy:   { sMax: 120, sStart: 120, graceSec: 4, dStart: 3.0, dAccel: 0.11, refill: 2.4, penalty: 7 },
    medium: { sMax: 100, sStart: 100, graceSec: 3, dStart: 3.8, dAccel: 0.20, refill: 1.9, penalty: 10 },
    hard:   { sMax: 85,  sStart: 70,  graceSec: 0, dStart: 5.5, dAccel: 0.40, refill: 1.3, penalty: 16 },
};

// Batas event error yang dikirim ke server. Sesi latihan wajar jauh di bawah ini;
// 500 error dalam satu tes ≈ akurasi di bawah 50% di mode time 120 -- itu mashing.
const MAX_ERROR_EVENTS = 500;

function survivalConfig(difficulty) {
    return SURVIVAL_PRESETS[difficulty] || SURVIVAL_PRESETS.medium;
}

export default function typingGame(initialText) {
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
        containerTop: null,
        caretHeight: 0,
        positionFrame: null,
        caretInstant: true,
        caretDrawn: false,
        _caretDurFrame: null,
        currentWordIndex: 0,
        wordBounds: [],
        extraChars: {},
        cursorLeft: 0,
        cursorTop: 0,

        // --- Ghost Mode: cursor kedua ber-pacing linear (WPM konstan), diisi lewat event 'ghost-selected'. ---
        ghostActive: false,
        ghostWpm: 0,
        ghostLabel: '',
        ghostCharIndex: 0,
        ghostCursorLeft: 0,
        ghostCursorTop: 0,
        ghostFinished: false,
        ghostFinishTime: null,
        _ghostRafId: null,

        capsLockOn: false,

        isTyping: false,
        typingTimeout: null,
        totalKeystrokes: 0,
        correctKeystrokes: 0,
        wpmHistory: [],
        rawHistory: [],
        missedChars: {},
        // Satu entri per karakter target yang gagal diketik benar: {second, index, actual}.
        // missedChars tahu TUTS APA yang meleset; ini juga tahu KAPAN & DI KATA MANA.
        errorEvents: [],
        modeChangedCleanup: null,

        // --- Survival: stamina menyusut per detik, terisi per karakter benar, habis = game over. ---
        stamina: 100,         // nilai stamina sekarang
        staminaMax: 100,      // kapasitas/cap bar (di-set dari preset difficulty)
        staminaPct: 100,      // persentase untuk UI (0–100)
        survivalCfg: null,    // preset parameter aktif (lihat SURVIVAL_PRESETS)
        staminaInterval: null,// loop tick drain (halus, ~100ms)
        lastTickTime: 0,      // timestamp tick terakhir (untuk Δt presisi)
        currentWordDirty: false, // apakah kata yang sedang diketik sudah pernah error
        committedWordResults: {}, // {wordIndex: 'clean'|'dirty'} — kata yang sudah dinilai (idempoten)

        staminaCells: Array.from({ length: 16 }, (_, i) => i + 1),
        drainFlash: false,
        drainFlashTimeout: null,
        drainEventCount: 0,

        triggerDrainFlash() {
            this.drainFlash = true;
            clearTimeout(this.drainFlashTimeout);
            this.drainFlashTimeout = setTimeout(() => { this.drainFlash = false; }, 250);
        },

        syncCapsLock(e) {
            if (typeof e.getModifierState === 'function') {
                this.capsLockOn = e.getModifierState('CapsLock');
            }
        },

        init() {
            this.resetProgress();
            this.restoreGhostSelection();

            // Cleanup disimpan agar listener tak menumpuk saat Alpine remount.
            const cleanup = this.$wire.on('mode-changed', (payload) => {
                this.resetForNewText(payload.text ?? '');
            });
            this.modeChangedCleanup = typeof cleanup === 'function' ? cleanup : null;
        },

        // Ghost hanya sah di time/words. Kalau state global tersisa dari mode
        // sebelumnya sementara mode sekarang survival, buang -- jangan
        // dihidupkan lagi saat Alpine remount.
        ghostEligible() {
            return ['time', 'words'].includes(this.currentMain);
        },

        restoreGhostSelection() {
            if (!this.ghostEligible()) {
                window.__uetypeGhostSelection = null;
                this.ghostActive = false;
                this.ghostWpm = 0;
                this.ghostLabel = '';

                return;
            }

            const selection = window.__uetypeGhostSelection;
            if (!selection || !selection.active) return;

            this.ghostActive = true;
            this.ghostWpm = selection.wpm;
            this.ghostLabel = selection.label;
            this.ghostCharIndex = 0;
            this.ghostFinished = false;
            this.ghostFinishTime = null;

            this.$nextTick(() => {
                const pos = this.getCharPosition(0);
                if (pos) {
                    this.ghostCursorLeft = pos.left;
                    this.ghostCursorTop = pos.top;
                }
            });
        },

        // Reset penuh untuk teks BARU yang dikirim lewat event Livewire.
        resetForNewText(newText) {
            this.targetArray = newText.split('');
            this.resetProgress();
        },

        // Reset state (wordBounds, survival, dsb) dari targetArray saat ini.
        resetProgress() {
            this.stopRuntime();

            this.currentIndex = 0;
            this.inputResults = [];
            this.startTime = null;
            this.isStarted = false;
            this.isFinished = false;

            // Reset (mis. restart / ganti mode di tengah sesi) -> overlay chat tampil lagi.
            window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } }));
            this.timer = (this.currentMain === 'time') ? parseInt(this.currentSub) : 0;
            this.wpm = 0;
            this.rawWpm = 0;
            this.accuracy = 0;
            this.cursorLeft = 0;
            this.cursorTop = 0;
            this.scrollOffset = 0;
            this.lineHeight = 0;
            this.containerTop = null;
            this.caretHeight = 0;
            this.isTyping = false;

            // Preset survival diambil dari currentSub (easy|medium|hard).
            this.survivalCfg = survivalConfig(this.currentSub);
            this.staminaMax = this.survivalCfg.sMax;
            this.stamina = this.survivalCfg.sStart;
            this.staminaPct = Math.round((this.stamina / this.staminaMax) * 100);
            this.lastTickTime = 0;
            this.currentWordDirty = false;
            this.committedWordResults = {};
            this.drainEventCount = 0;
            this.drainFlash = false;

            this.wordBounds = [];
            this.extraChars = {};
            this.currentWordIndex = 0;
            this.totalKeystrokes = 0;
            this.correctKeystrokes = 0;
            this.wpmHistory = [];
            this.rawHistory = [];
            this.missedChars = {};
            this.errorEvents = [];
            this.ghostCharIndex = 0;
            this.ghostFinished = false;
            this.ghostFinishTime = null;
            this.ghostCursorLeft = 0;
            this.ghostCursorTop = 0;

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

            this.caretInstant = true;
            this.caretDrawn = false;
            // Gambar caret dengan RETRY antar-frame sampai benar-benar tergambar.
            // Kenapa retry: sesudah remount ganti mode, char-0 kadang BELUM ter-render saat
            // draw pertama -> updatePosition() return awal (activeEl null) -> moveCaret tak
            // pernah jalan -> transform:translate() TAK PERNAH di-set -> caret nyangkut di 0,0
            // (pojok kiri-atas baris). Ini paling sering di Survival karena DOM-nya jauh lebih
            // berat (16 sel stamina x-for) sehingga layout teks telat satu-dua frame; Standard
            // yang ringan hampir selalu sukses di draw pertama. caretDrawn baru true setelah
            // moveCaret sungguh menggambar, jadi kita ulang tiap frame (maks 12 ~200ms) sampai
            // char-0 ada & caret tergambar. caretInstant tetap true -> semua penempatan ini
            // instan (tanpa transisi), sesuai gate isTyping di kelas caret.
            const drawWhenReady = (retries) => {
                this.updatePosition();
                if (!this.caretDrawn && retries > 0) {
                    requestAnimationFrame(() => drawWhenReady(retries - 1));
                }
            };
            this.$nextTick(() => drawWhenReady(12));
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

        // Catat SATU karakter target yang gagal diketik benar. WAJIB dipanggil dari
        // dalam guard yang sama persis dengan yang menaikkan missedChars -- itu yang
        // membuat jumlah event === jumlah missedChars, invarian yang dipakai halaman
        // hasil untuk menyamakan titik di grafik dengan angka di heatmap tepat di bawahnya.
        //   charIndex : index absolut di targetArray
        //   actual    : tuts yang ditekan (handleInput sudah menjamin panjangnya 1),
        //               atau null untuk karakter yang DILEWATI -- user menekan spasi,
        //               tak pernah ada tuts untuk karakter ini.
        recordError(charIndex, actual) {
            if (this.errorEvents.length >= MAX_ERROR_EVENTS) return;
            this.errorEvents.push({
                second: this.startTime ? Math.floor((Date.now() - this.startTime) / 1000) : 0,
                index: charIndex,
                actual: actual,
            });
        },

        // --- Helper Survival ---

        // Tandai kata aktif "kotor" saat error. Nyawa dipotong nanti saat commit, bukan di sini.
        markWordDirty() {
            if (this.currentMain !== 'survival' || this.isFinished) return;
            this.currentWordDirty = true;
        },

        // Nilai satu kata yang selesai. Kata kotor kena penalti stamina sekali saja
        // (cap per-kata, idempoten kalau di-commit ulang); kata bersih tak kena apa-apa.
        completeWord(wordIndex) {
            if (this.currentMain !== 'survival') return;

            const isDirty = this.currentWordDirty;
            this.currentWordDirty = false;

            const alreadyPenalized = this.committedWordResults[wordIndex] === 'dirty';

            if (isDirty) {
                this.committedWordResults[wordIndex] = 'dirty';
                if (!alreadyPenalized) {
                    this.stamina = Math.max(0, this.stamina - this.survivalCfg.penalty);
                    this.syncStaminaPct();
                    this.triggerDrainFlash();
                    this.drainEventCount++;
                    if (this.stamina <= 0) this.survivalGameOver();
                }
                return;
            }

            this.committedWordResults[wordIndex] = 'clean';
        },

        // Backspace mundur ke kata sebelumnya: batalkan penilaian commit terakhir.
        // Status 'dirty' dipertahankan agar penalti tak dikenakan dua kali saat re-commit.
        uncommitWord(wordIndex) {
            if (this.currentMain !== 'survival') return;

            const prev = this.committedWordResults[wordIndex];
            if (prev === undefined) return;

            if (prev === 'clean') {
                delete this.committedWordResults[wordIndex];
            }
        },

        // Refill stamina tiap satu karakter benar (cap di staminaMax).
        refillStamina() {
            if (this.currentMain !== 'survival' || this.isFinished) return;
            this.stamina = Math.min(this.staminaMax, this.stamina + this.survivalCfg.refill);
            this.syncStaminaPct();
        },

        // Satu tick drain pasif; Δt presisi dari tick sebelumnya, drain naik seiring waktu.
        staminaTick() {
            if (this.currentMain !== 'survival' || this.isFinished || !this.startTime) return;

            const now = Date.now();
            const dt = this.lastTickTime ? (now - this.lastTickTime) / 1000 : 0;
            this.lastTickTime = now;
            if (dt <= 0) return;

            const elapsed = (now - this.startTime) / 1000;
            const cfg = this.survivalCfg;

            const graceFactor = elapsed < cfg.graceSec ? (elapsed / cfg.graceSec) : 1;

            // D = D_start + D_accel * elapsed
            const drainPerSec = (cfg.dStart + cfg.dAccel * elapsed) * graceFactor;

            this.stamina = Math.max(0, this.stamina - drainPerSec * dt);
            this.syncStaminaPct();

            if (this.stamina <= 0) this.survivalGameOver();
        },

        // Sinkronkan persentase bar untuk UI (0–100).
        syncStaminaPct() {
            const nextPct = Math.max(0, Math.min(100, Math.round((this.stamina / this.staminaMax) * 100)));
            if (nextPct !== this.staminaPct) {
                this.staminaPct = nextPct;
            }
        },

        // Stamina habis: hentikan loop tick, selesaikan sesi lewat finish() seperti mode lain.
        survivalGameOver() {
            if (this.isFinished) return;
            if (this.staminaInterval) {
                clearInterval(this.staminaInterval);
                this.staminaInterval = null;
            }
            this.finish();
        },

        destroy() {
            if (this.modeChangedCleanup) {
                this.modeChangedCleanup();
                this.modeChangedCleanup = null;
            }
            this.stopRuntime();
        },

        stopRuntime() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            if (this.staminaInterval) {
                clearInterval(this.staminaInterval);
                this.staminaInterval = null;
            }
            clearTimeout(this.drainFlashTimeout);
            this.drainFlashTimeout = null;
            clearTimeout(this.typingTimeout);
            this.typingTimeout = null;
            this.stopGhostAnimationLoop();
            if (this.positionFrame) {
                cancelAnimationFrame(this.positionFrame);
                this.positionFrame = null;
            }
            if (this._caretDurFrame) {
                cancelAnimationFrame(this._caretDurFrame);
                this._caretDurFrame = null;
            }
        },

        // Lookup posisi DOM char-{index}; fallback ke karakter terakhir bila index melewati teks.
        getCharPosition(index) {
            let activeEl = document.getElementById('char-' + index);
            let isEnd = false;

            if (!activeEl) {
                activeEl = document.getElementById('char-' + (index - 1));
                isEnd = true;
            }

            if (!activeEl) return null;

            return {
                left: isEnd ? activeEl.offsetLeft + activeEl.offsetWidth : activeEl.offsetLeft,
                top: activeEl.offsetTop,
            };
        },

        // Posisi ghost dari progres waktu (pacing linear); dipanggil per-frame lewat rAF.
        // ghostCharIndex (integer) dipakai untuk menang/kalah di finish(); posisi visual
        // dihitung dari nilai pecahan agar cursor bergerak halus melintasi tiap karakter.
        updateGhostPosition() {
            if (!this.startTime || this.ghostFinished) return;

            const minutesElapsed = (Date.now() - this.startTime) / 60000;
            const fractionalChars = Math.max(0, Math.min(
                this.ghostWpm * 5 * minutesElapsed,
                this.targetArray.length
            ));

            const flooredIndex = Math.floor(fractionalChars);
            this.ghostCharIndex = Math.min(flooredIndex, this.targetArray.length);

            if (fractionalChars >= this.targetArray.length) {
                if (!this.ghostFinished) {
                    this.ghostFinished = true;
                    this.ghostFinishTime = Date.now() - this.startTime;
                }
                const pos = this.getCharPosition(this.targetArray.length);
                if (pos) {
                    this.ghostCursorLeft = pos.left;
                    this.ghostCursorTop = pos.top;
                }
                return;
            }

            // Interpolasi pixel antar karakter; hanya bila keduanya sebaris (kalau wrap, lompat langsung).
            const fraction = fractionalChars - flooredIndex;
            const currentPos = this.getCharPosition(flooredIndex);
            if (!currentPos) return;

            const nextPos = this.getCharPosition(flooredIndex + 1);

            if (nextPos && nextPos.top === currentPos.top) {
                this.ghostCursorLeft = currentPos.left + (nextPos.left - currentPos.left) * fraction;
                this.ghostCursorTop = currentPos.top;
            } else {
                this.ghostCursorLeft = currentPos.left;
                this.ghostCursorTop = currentPos.top;
            }
        },

        // Loop animasi ghost via rAF (~60fps), terpisah dari timerInterval 1 detik.
        startGhostAnimationLoop() {
            if (this._ghostRafId) return; // sudah berjalan

            const tick = () => {
                if (!this.ghostActive || this.isFinished) {
                    this._ghostRafId = null;
                    return;
                }
                this.updateGhostPosition();
                this._ghostRafId = requestAnimationFrame(tick);
            };

            this._ghostRafId = requestAnimationFrame(tick);
        },

        stopGhostAnimationLoop() {
            if (this._ghostRafId) {
                cancelAnimationFrame(this._ghostRafId);
                this._ghostRafId = null;
            }
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

            if (this.containerTop === null || !this.lineHeight || !this.caretHeight) {
                const firstChar = document.getElementById('char-0');
                if (firstChar) {
                    if (this.containerTop === null) this.containerTop = firstChar.offsetTop;
                    if (!this.lineHeight) this.lineHeight = firstChar.offsetHeight;
                }
                if (!this.caretHeight) {
                    // Tinggi caret = 1.2em (kelas h-[1.2em]) DIHITUNG dari font-size ter-resolusi,
                    // BUKAN diukur via offsetHeight. Alasan: sesudah remount ganti mode, DOM
                    // Survival jauh lebih berat (16 sel stamina x-for) sehingga layout caret belum
                    // jadi saat diukur -> offsetHeight 0. Fallback `caretHeight || height` lalu
                    // memakai tinggi KARAKTER (line-height 1.6em), bukan 1.2em, jadi pemusatan
                    // (height - caretHeight)/2 = 0 -> caret nempel ke ATAS baris (naik) & terlihat
                    // beda dengan Standard (yang sempat terukur benar). getComputedStyle font-size
                    // selalu ter-resolusi tanpa menunggu layout, jadi nilainya identik di semua
                    // mode & anti-race. (Kalau kelas tinggi caret diubah, sesuaikan 1.2 di sini.)
                    const fs = parseFloat(getComputedStyle(this.$refs.caret || activeEl).fontSize);
                    if (fs) this.caretHeight = fs * 1.2;
                }
            }

            const left = activeEl.offsetLeft;
            const top = activeEl.offsetTop;
            const width = activeEl.offsetWidth;
            const height = activeEl.offsetHeight;

            const caretHeight = this.caretHeight || height;
            const targetTop = top + ((height - caretHeight) / 2);

            this.cursorLeft = isEnd ? left + width : left;
            this.cursorTop = targetTop;

            const currentTop = top - (this.containerTop || 0);
            const lh = this.lineHeight || 48;
            this.scrollOffset = currentTop >= lh * 2 ? currentTop - lh : 0;

            // Transform caret di-render REAKTIF via :style pada elemen caret (cursorLeft/cursorTop).
            // Di sini cukup tandai bahwa caret sudah berhasil diposisikan (char-0 ketemu) supaya
            // retry drawWhenReady() di resetProgress berhenti. Instan/meluncur ditentukan gate
            // isTyping di :class caret, bukan lagi flag imperatif.
            this.caretDrawn = true;
        },

        schedulePositionUpdate() {
            if (this.positionFrame) return;
            this.positionFrame = requestAnimationFrame(() => {
                this.positionFrame = null;
                this.updatePosition();
            });
        },

        deleteOneStep() {
            if (this.currentIndex <= 0) return false;

            const bounds = this.wordBounds[this.currentWordIndex];

            if (this.extraChars[this.currentWordIndex] && this.extraChars[this.currentWordIndex].length > 0) {
                this.extraChars[this.currentWordIndex].pop();
                this.schedulePositionUpdate();
                return true;
            }

            if (this.currentIndex === bounds.start) {
                if (this.currentWordIndex > 0) {
                    let prevWordIdx = this.currentWordIndex - 1;
                    if (this.wordHasError(prevWordIdx)) {
                        this.currentWordIndex--;

                        this.uncommitWord(this.currentWordIndex);
                        this.markWordDirty();

                        let prevBounds = this.wordBounds[this.currentWordIndex];
                        let jumpIndex = prevBounds.space;

                        this.inputResults[jumpIndex] = null;

                        while (jumpIndex > prevBounds.start && this.inputResults[jumpIndex - 1] === 'skipped') {
                            jumpIndex--;
                            this.inputResults[jumpIndex] = null;
                        }

                        this.currentIndex = jumpIndex;
                        this.schedulePositionUpdate();
                        return true;
                    }
                }
                return false;
            }

            this.currentIndex--;
            this.inputResults[this.currentIndex] = null;
            this.schedulePositionUpdate();
            return true;
        },

        handleInput(e) {
            if (this.isFinished) return;
            if ((e.ctrlKey || e.metaKey) && e.key !== 'Backspace') return;
            if (e.key === ' ') e.preventDefault();
            if (e.key.length > 1 && e.key !== 'Backspace') return;

            // Kursor berhenti berkedip selama mengetik.
            this.isTyping = true;
            clearTimeout(this.typingTimeout);
            this.typingTimeout = setTimeout(() => {
                this.isTyping = false;
            }, 500);

            if (!this.isStarted) {
                this.isStarted = true;
                this.startTime = Date.now();

                // Mulai mengetik -> caret baru boleh meluncur mulus antar-karakter.
                // Sebelum titik ini caretInstant tetap true (di-set di resetProgress) supaya
                // penempatan awal / reset / ganti mode selalu instan, tanpa animasi meluncur.
                this.caretInstant = false;

                // Sembunyikan overlay chat selama sesi ketik berjalan.
                window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: true } }));

                // Survival: loop drain ~100ms agar tekanan terasa mulus (drain & game over di staminaTick).
                if (this.currentMain === 'survival') {
                    this.lastTickTime = this.startTime;
                    this.staminaInterval = setInterval(() => this.staminaTick(), 100);
                }

                // Ghost: loop rAF terpisah dari timerInterval 1 detik di bawah.
                if (this.ghostActive) {
                    this.startGhostAnimationLoop();
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
                if (e.ctrlKey || e.metaKey) {
                    const startWord = this.currentWordIndex;
                    let guard = 0;
                    while (this.currentIndex > 0 && guard++ < 500) {
                        const atWordStart = this.currentIndex === this.wordBounds[this.currentWordIndex].start;
                        const moved = this.deleteOneStep();
                        if (!moved) break;
                        if (this.currentWordIndex !== startWord) break;
                        if (atWordStart) break;
                    }
                    this.schedulePositionUpdate();
                    return;
                }

                this.deleteOneStep();
                return;
            }

            // Sejak titik ini: user menekan karakter/spasi, bukan backspace.
            this.totalKeystrokes++;

            // Kursor di posisi spasi pembatas antar kata.
            if (this.currentIndex === bounds.space) {
                if (e.key !== ' ') {
                    // Overtyping: karakter berlebih ditampung, kata ditandai kotor.
                    if (!this.extraChars[this.currentWordIndex]) this.extraChars[this.currentWordIndex] = [];
                    if (this.extraChars[this.currentWordIndex].length < 15) {
                        this.extraChars[this.currentWordIndex].push(e.key);
                    }
                    this.markWordDirty();
                    this.calculateStats();
                    this.schedulePositionUpdate();
                    return;
                } else {
                    // Spasi ditekan: pindah ke kata berikutnya & nilai kata yang baru selesai.
                    this.correctKeystrokes++;
                    this.refillStamina();
                    this.inputResults[this.currentIndex] = true;
                    this.currentIndex++;
                    this.currentWordIndex++;
                    this.completeWord(this.currentWordIndex - 1);
                    if (this.currentIndex === this.targetArray.length) this.finish();
                    this.calculateStats();
                    this.schedulePositionUpdate();
                    return;
                }
            }

            // Spasi di tengah kata: sisa huruf ditandai terlewat (kata di-skip).
            if (e.key === ' ') {
                // Abaikan spasi kalau kata ini belum diketik sama sekali (cegah spam spasi).
                if (this.currentIndex === bounds.start) {
                    return;
                }

                for (let i = this.currentIndex; i <= bounds.end; i++) {
                    this.inputResults[i] = 'skipped';

                    const expectedChar = this.targetArray[i].toLowerCase();
                    if (expectedChar !== ' ' && expectedChar.length === 1) {
                        this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                        // null: user menekan spasi SEKALI lalu melewati sisa kata -- tak
                        // pernah ada tuts untuk karakter ini.
                        this.recordError(i, null);
                    }
                }
                this.markWordDirty();
                if (bounds.space !== null) {
                    this.inputResults[bounds.space] = 'skipped'; // spasi di-skip tak dihitung benar
                    this.currentIndex = bounds.space + 1;
                    this.currentWordIndex++;
                    this.completeWord(this.currentWordIndex - 1);
                } else {
                    this.currentIndex = this.targetArray.length;
                    this.finish();
                }
                this.calculateStats();
                this.schedulePositionUpdate();
                return;
            }

            // Pengetikan normal.
            const isCorrect = (e.key === this.targetArray[this.currentIndex]);
            if (isCorrect) {
                this.correctKeystrokes++;
                this.refillStamina();
            } else {
                const expectedChar = this.targetArray[this.currentIndex].toLowerCase();
                if (expectedChar !== ' ' && expectedChar.length === 1) {
                    this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                    this.recordError(this.currentIndex, e.key);
                }
                this.markWordDirty();
            }

            this.inputResults[this.currentIndex] = isCorrect;
            this.currentIndex++;

            if (this.currentIndex === this.targetArray.length) this.finish();
            this.calculateStats();
            this.schedulePositionUpdate();
        },

        calculateStats() {
            if (!this.startTime) return;

            const elapsedMs = Date.now() - this.startTime;

            // Lantai 1 detik: cegah WPM meledak di awal ketikan.
            const effectiveMs = (elapsedMs < 1000 && !this.isFinished) ? 1000 : elapsedMs;
            const timeElapsed = effectiveMs / 60000;

            if (timeElapsed <= 0) return;

            // Net WPM dari correctKeystrokes — sumber yang sama dengan finish/server,
            // sehingga angka live identik dengan angka di halaman hasil.
            this.wpm = Math.round((this.correctKeystrokes / 5) / timeElapsed) || 0;

            // Raw WPM: mengabaikan error (total tuts / 5).
            this.rawWpm = Math.round((this.totalKeystrokes / 5) / timeElapsed) || 0;

            // Akurasi berbasis tuts fisik (gaya Monkeytype).
            if (this.totalKeystrokes > 0) {
                this.accuracy = Math.round((this.correctKeystrokes / this.totalKeystrokes) * 100);
            } else {
                this.accuracy = 0;
            }
        },

        // wpmHistory[] -> string `points` untuk <polyline> sparkline, auto-scale ke min/max.
        //
        // METHOD, bukan getter. Sebagai getter, grafik ini TIDAK PERNAH tergambar:
        // objek ini di-spread ke dalam x-data, dan spread mengevaluasi getter satu
        // kali lalu menyalin hasilnya sebagai nilai statis. Saat itu wpmHistory masih
        // kosong, jadi hasilnya '' dan terkunci selamanya -- kotak SVG-nya muncul
        // tapi isinya kosong. Method tidak dievaluasi saat spread, jadi tetap hidup.
        sparklinePoints() {
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
            // Sesi ketik selesai — tampilkan lagi overlay chat.
            window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } }));
            clearInterval(this.timerInterval);
            if (this.staminaInterval) {
                clearInterval(this.staminaInterval);
                this.staminaInterval = null;
            }

            // Durasi presisi (ms) sejak keystroke pertama — sumber yang sama dengan
            // perhitungan live, agar WPM final identik dengan WPM saat mengetik.
            const durationMs = this.startTime ? (Date.now() - this.startTime) : 0;

            const correct = this.correctKeystrokes;
            const total = this.totalKeystrokes;

            // Hitung posisi ghost tepat pada momen finish (bukan snapshot rAF terakhir).
            if (this.ghostActive && !this.ghostFinished) {
                this.updateGhostPosition();
            }
            this.stopGhostAnimationLoop();
            const ghostWpmArg = this.ghostActive ? this.ghostWpm : null;
            const ghostLabelArg = this.ghostActive ? this.ghostLabel : null;
            const ghostCharsArg = this.ghostActive ? this.ghostCharIndex : null;

            this.$wire.saveResult(durationMs, total, correct, this.wpmHistory, this.rawHistory, this.missedChars, this.drainEventCount, ghostWpmArg, ghostLabelArg, ghostCharsArg, this.errorEvents);
        }
    }
}
