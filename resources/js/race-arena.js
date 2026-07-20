/**
 * Arena balapan multiplayer: store Alpine 'race' (posisi lawan) + komponen
 * 'raceArena' (mengetik, countdown, sudden death).
 *
 * Sebelumnya 533 baris ini hidup sebagai <script> di dalam blok @assets pada
 * multiplayer-lobby.blade.php -- tak bisa di-lint, di-minify, maupun di-cache
 * browser sebagai aset terpisah.
 *
 * Blok aslinya nol interpolasi Blade, jadi pemindahannya murni copy-paste.
 * Semua data dari server tetap masuk lewat @js(...) di markup (config raceArena
 * dan laneSeeds), bukan lewat file ini.
 */
// Komponen Alpine 'raceArena': logika typing + sudden death.
// registerRaceArena() idempoten (flag global).
const registerRaceArena = (Alpine) => {
    if (window.__raceArenaRegistered) return;
    window.__raceArenaRegistered = true;

    // Store global 'race': posisi maskot lawan dari payload WebSocket. Store (bukan state
    // komponen) agar bertahan lintas Livewire morph. opponents = { [userId]: {progress, wpm, finished} }.
    if (!Alpine.store('race')) {
        Alpine.store('race', {
            opponents: {},
            apply(userId, data) {
                const prev = this.opponents[userId];
                const next = {
                    progress: data.progress_percent ?? 0,
                    wpm: data.wpm ?? 0,
                    finished: !!data.finished,
                };

                if (prev) {
                    // Pemain yang sudah finish tetap finish di 100%: paket lama
                    // yang menyusul tak boleh menariknya mundur dari garis finis.
                    if (prev.finished) {
                        next.finished = true;
                        next.progress = Math.max(next.progress, prev.progress);
                    }
                }

                // Reassign object agar reaktivitas Alpine ter-trigger.
                this.opponents = { ...this.opponents, [userId]: next };
            },
            leaderId() {
                let bestId = null;
                let bestProgress = 0;
                for (const [id, o] of Object.entries(this.opponents)) {
                    const p = o.progress ?? 0;
                    if (p > bestProgress) {
                        bestProgress = p;
                        bestId = id;
                    }
                }
                return bestId;
            },
            // Peringkat hidup pemain: 1 + jumlah pemain yang progresnya lebih jauh.
            // Seri -> peringkat sama (dua pemain di 0% sama-sama peringkat 1).
            // seeds = { [userId]: progress } dari Blade, dipakai untuk pemain
            // yang belum pernah mengirim payload WebSocket.
            rankOf(userId, seeds = {}) {
                const at = (id) => this.opponents[id]?.progress ?? seeds[id] ?? 0;
                const mine = at(userId);
                let ahead = 0;
                for (const id of Object.keys(seeds)) {
                    if (String(id) !== String(userId) && at(id) > mine) ahead++;
                }
                return ahead + 1;
            },
            // Race yang sedang "dimiliki" store ini. Dipakai untuk membedakan
            // "race baru" (boleh dibersihkan) dari "re-init race yang sama".
            raceKey: null,

            // Tenggat countdown pada jam MONOTONIK (performance.now()), bukan
            // Date.now(). Disimpan di store, bukan di komponen, supaya morph
            // Livewire / re-init Alpine tak pernah mengulang hitung mundur.
            deadline: null,

            /**
             * Detak jam bersama (ms epoch), dinaikkan tiap detik selama balapan.
             *
             * WPM tiap pemain = f(karakter benar, waktu berlalu). Karena waktu
             * terus jalan walau tak ada yang mengetik, lane harus dihitung ulang
             * secara berkala. Nilai reaktif ini yang memicunya -- SATU timer untuk
             * seluruh lane, dan tak bergantung pada tab lawan (tab latar dibekukan
             * browser, jadi lawan yang diam takkan pernah menyiarkan WPM barunya).
             */
            now: Date.now(),
            _nowInterval: null,

            startClock() {
                if (this._nowInterval) return;
                this._nowInterval = setInterval(() => {
                    this.now = Date.now();
                }, 1000);
            },

            stopClock() {
                if (! this._nowInterval) return;
                clearInterval(this._nowInterval);
                this._nowInterval = null;
            },

            /**
             * Kunci tenggat SEKALI per race. Panggilan berikutnya untuk race yang
             * sama diabaikan, jadi countdown terus berjalan menuju tenggat semula.
             * `remainingMs` datang dari server (sisa waktu saat halaman dirender).
             */
            armCountdown(key, remainingMs) {
                if (this.raceKey === key && this.deadline !== null) return;
                this.raceKey = key;
                this.deadline = performance.now() + remainingMs;
            },

            /** Sisa milidetik menuju start; <= 0 berarti race sudah boleh mulai. */
            remainingMs() {
                if (this.deadline === null) return 0;
                return this.deadline - performance.now();
            },

            reset() {
                this.opponents = {};
                this.raceKey = null;
                this.deadline = null;
                this.stopClock();
            },

            /**
             * Bersihkan HANYA kalau ini benar-benar race lain. Re-init pada race
             * yang sama (morph Livewire, sudden death, komponen di-mount ulang)
             * tak boleh menghapus posisi -- itulah yang dulu menarik semua maskot
             * kembali ke 0 saat pemain berhenti mengetik sejenak.
             */
            resetForRace(key) {
                if (this.raceKey === key) return;
                this.opponents = {};
                this.raceKey = key;
                this.deadline = null; // race lain -> tenggat lama tak berlaku
            },
        });
    }

    Alpine.data('raceArena', (config = {}) => ({
        countdown: 3,
        raceStarted: false,
        myId: config.myId,
        // Penonton: ikut render arena (countdown + lane pembalap) tapi tak pernah
        // mengetik, meng-emit progress, atau menyerah. Semua jalur input dijaga ini.
        isSpectator: !!config.isSpectator,
        roomCode: config.roomCode || '',
        textToType: config.textToType || '',
        // Waktu absolut (ms epoch) race mulai; hanya dipakai sebagai titik awal WPM
        // & identitas race, BUKAN untuk countdown (jam klien tak bisa dipercaya).
        raceStartsAtMs: config.raceStartsAt ? new Date(config.raceStartsAt).getTime() : null,
        // Sisa waktu menuju start menurut SERVER saat halaman ini dirender.
        // null = race belum dijadwalkan.
        raceStartsInMs: config.raceStartsInMs ?? null,
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
        // true kalau kata itu dilewati salah/belum lengkap (space tanpa exact match) - untuk highlight visual riwayat error per kata.
        wordHadError: [],

        // Progress & WPM pemain lokal, reaktif, dibaca lane maskot sendiri di view. Diperbarui tiap checkInput().
        progressPercent: 0,
        liveWpm: 0,

        // Throttle emit progress ke server: visual lokal instan, jaringan dibatasi ~120ms + trailing flush.
        _lastEmit: 0,
        _emitTimer: null,

        // Ticker WPM (1 detik): menyegarkan angka saat pemain berhenti mengetik.
        _wpmInterval: null,

        // Sudden death: timer client-side, tapi checkSuddenDeath() di server tetap sumber kebenaran final.
        suddenDeathActive: !!config.suddenDeathActive,
        suddenDeathRemaining: config.suddenDeathRemaining ?? 15,
        lockedByTimeout: false,
        _sdInterval: null,

        init() {
            this.words = this.textToType.split(' ');

            // Bersihkan posisi HANYA saat masuk race yang berbeda. Kalau komponen
            // ini di-init ulang untuk race yang sama (morph Livewire, sudden death),
            // posisi tiap maskot dipertahankan -- kalau dihapus, semua lane jatuh
            // ke seed lama (0) sampai payload berikutnya tiba.
            if (this.$store.race) {
                this.$store.race.resetForRace(this.raceKey());
            }

            // Sudden death aktif saat (re)init = race sudah berjalan -> skip overlay countdown.
            if (this.suddenDeathActive) {
                this.raceStarted = true;
                this.countdown = 'GO!';
                this.startTime = Date.now();
                this.startSuddenDeathClock();
                // Penonton tak mengetik: tak perlu WPM lokal maupun fokus input.
                if (!this.isSpectator) {
                    this.startWpmTicker();
                    this.$nextTick(() => {
                        if (this.$refs.typeInput) this.$refs.typeInput.focus();
                    });
                }
            } else {
                // Hitung mundur ke race_starts_at server: waktu absolut sama di semua layar.
                this.startSyncedCountdown();
            }

            // Sinyal server saat room ditutup paksa -> kunci total.
            this.$wire.on('force-finish', () => this.lockRace());

            // Bridge event .race.sudden_death -> hitung mundur komponen ini. Disimpan agar bisa di-remove saat destroy.
            this._onSuddenDeath = (ev) => this.syncSuddenDeath(ev.detail.remaining);
            window.addEventListener('race-sudden-death', this._onSuddenDeath);
        },

        // Identitas satu balapan: room + waktu mulai. Rematch di room yang sama
        // memakai race_starts_at baru -> key berubah -> store dibersihkan.
        raceKey() {
            return `${this.roomCode}@${this.raceStartsAtMs ?? 'pending'}`;
        },

        /**
         * Hitung mundur ke tenggat yang dikunci di store saat race ini pertama
         * kali terlihat. Karena tenggatnya monotonik & di luar komponen, morph
         * Livewire atau re-init Alpine hanya melanjutkan hitungan yang sama --
         * tidak pernah mengulanginya dari 3.
         */
        startSyncedCountdown() {
            // Server tak menjadwalkan race -> tak ada yang perlu dihitung mundur.
            if (this.raceStartsInMs === null) {
                this.beginRace();
                return;
            }

            this.$store.race.armCountdown(this.raceKey(), this.raceStartsInMs);

            // Tenggat sudah lewat saat komponen ini di-mount (mis. arena di-morph
            // di tengah balapan): langsung masuk race, jangan tampilkan "3" lagi.
            if (this.$store.race.remainingMs() <= 0) {
                this.countdown = 'GO!';
                this.beginRace();
                return;
            }

            const tick = () => {
                const remainingMs = this.$store.race.remainingMs();

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

        // startTime dipatok ke race_starts_at server agar WPM antar pemain pakai titik awal sama.
        beginRace() {
            if (this.raceStarted) return;
            this.raceStarted = true;
            this.startTime = this.raceStartsAtMs ?? Date.now();
            // Penonton hanya menonton: tak ada WPM lokal maupun fokus input.
            if (this.isSpectator) return;
            this.startWpmTicker();
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
            if (this._wpmInterval) {
                clearInterval(this._wpmInterval);
                this._wpmInterval = null;
            }
            if (this._emitTimer) {
                clearTimeout(this._emitTimer);
                this._emitTimer = null;
            }
        },

        // Sinkronkan sisa waktu dari server & pastikan clock lokal jalan.
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
            // WPM berhenti di angka terakhir; balapan sudah usai bagi pemain ini.
            if (this._wpmInterval) {
                clearInterval(this._wpmInterval);
                this._wpmInterval = null;
            }
            if (this._emitTimer) {
                clearTimeout(this._emitTimer);
                this._emitTimer = null;
            }
            if (this.$refs.typeInput) this.$refs.typeInput.blur();

            // checkSuddenDeath() idempoten: aman dipanggil beberapa klien bersamaan.
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

            // Satu rumus dipakai bersama ticker WPM (lihat correctCharsSoFar/currentWpm).
            let totalCorrectChars = this.correctCharsSoFar();
            let progressPercent = Math.floor((totalCorrectChars / this.textToType.length) * 100);

            let accuracyPercent = this.currentAccuracy();
            let liveWpm = this.currentWpm();

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

        /**
         * Jumlah karakter benar yang sudah diketik sejauh ini: kata-kata yang
         * sudah lewat + awalan benar pada kata yang sedang diketik.
         */
        correctCharsSoFar() {
            const targetWord = this.words[this.currentWordIndex] ?? '';
            let correctInCurrent = 0;

            for (let i = 0; i < this.typedText.length; i++) {
                if (this.typedText[i] !== targetWord[i]) break;
                correctInCurrent++;
            }

            return this.correctCharsFromPastWords + correctInCurrent;
        },

        /** WPM standar: (karakter benar / 5) dibagi menit yang berlalu. */
        currentWpm() {
            const minutes = (Date.now() - this.startTime) / 60000;
            if (minutes <= 0) return 0;

            return Math.floor((this.correctCharsSoFar() / 5) / minutes);
        },

        /**
         * Menjaga `liveWpm` lokal tetap segar saat pemain berhenti mengetik,
         * agar nilai yang dikirim ke server (mis. saat finish) tak basi.
         *
         * TIDAK mengirim apa pun ke jaringan: tiap lane sudah menghitung WPM
         * lawannya sendiri dari progres + waktu (lihat liveWpmValue). Kalau
         * mengandalkan kiriman pemiliknya, tab lawan yang tidak aktif dibekukan
         * browser dan angkanya macet -- persis bug yang diperbaiki di sini.
         */
        startWpmTicker() {
            if (this._wpmInterval) return;

            this._wpmInterval = setInterval(() => {
                if (this.isFinished || this.lockedByTimeout || !this.raceStarted) return;

                this.liveWpm = this.currentWpm();
            }, 1000);
        },

        /** Akurasi berjalan; dipisah agar ticker tak menduplikasi rumusnya. */
        currentAccuracy() {
            return this.totalKeystrokes > 0
                ? Math.round(((this.totalKeystrokes - this.totalMistakes) / this.totalKeystrokes) * 100)
                : 100;
        },

        // Publikasikan posisi lokal ke store (key userId sendiri) agar lane sendiri & lawan seragam.
        publishLocal(progress, wpm, finished) {
            if (this.myId == null) return;
            this.$store.race.apply(this.myId, {
                progress_percent: progress,
                wpm: wpm,
                finished: finished,
            });
        },

        // Throttle ~120ms + trailing-edge flush agar posisi terakhir tak hilang; `force` selalu segera.
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

        // Permisif seperti Solo: spasi selalu pindah kata (tak pernah mengunci); huruf salah/terlewat
        // dicatat sebagai mistake tapi progres tetap jalan.
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
                // Hanya huruf ekor yang belum diketik; yang sudah diketik dihitung di checkInput().
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

                // typedText sudah kosong & tak ada kata berikutnya, jadi
                // correctCharsSoFar() == correctCharsFromPastWords.
                const accuracyPercent = this.currentAccuracy();
                const liveWpm = this.currentWpm();
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
