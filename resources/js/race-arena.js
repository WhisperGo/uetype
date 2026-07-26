/**
 * Multiplayer race arena: the Alpine 'race' store (opponent positions) + the 'raceArena'
 * component (typing, countdown, sudden death).
 *
 * These 533 lines used to live as a <script> inside the @assets block of
 * multiplayer-lobby.blade.php -- couldn't be linted, minified, or browser-cached as a
 * separate asset.
 *
 * The original block had zero Blade interpolation, so the move was a pure copy-paste. All
 * server data still comes in through @js(...) in the markup (raceArena config and
 * laneSeeds), not through this file.
 */
// Alpine 'raceArena' component: typing + sudden-death logic.
// registerRaceArena() is idempotent (global flag).
const registerRaceArena = (Alpine) => {
    if (window.__raceArenaRegistered) return;
    window.__raceArenaRegistered = true;

    // Global 'race' store: opponent mascot positions from WebSocket payloads. A store (not
    // component state) so it survives Livewire morphs. opponents = { [userId]: {progress, wpm, finished} }.
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
                    // A player who already finished stays finished at 100%: a late-arriving
                    // old packet must not drag them back from the finish line.
                    if (prev.finished) {
                        next.finished = true;
                        next.progress = Math.max(next.progress, prev.progress);
                    }
                }

                // Reassign the object so Alpine reactivity triggers.
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
            // A player's live rank: 1 + the number of players further ahead. A tie -> the
            // same rank (two players at 0% are both rank 1). seeds = { [userId]: progress }
            // from Blade, used for players who haven't yet sent a WebSocket payload.
            rankOf(userId, seeds = {}) {
                const at = (id) => this.opponents[id]?.progress ?? seeds[id] ?? 0;
                const mine = at(userId);
                let ahead = 0;
                for (const id of Object.keys(seeds)) {
                    if (String(id) !== String(userId) && at(id) > mine) ahead++;
                }
                return ahead + 1;
            },
            // The race this store currently "owns". Used to tell a "new race" (safe to
            // clear) from a "re-init of the same race".
            raceKey: null,

            // Countdown deadline on the MONOTONIC clock (performance.now()), not Date.now().
            // Kept in the store, not the component, so a Livewire morph / Alpine re-init
            // never restarts the countdown.
            deadline: null,

            /**
             * Shared clock tick (ms epoch), bumped every second during the race.
             *
             * Each player's WPM = f(correct chars, elapsed time). Since time keeps moving
             * even when nobody types, lanes must be recomputed periodically. This reactive
             * value drives that -- ONE timer for all lanes, independent of opponent tabs (a
             * background tab is frozen by the browser, so an idle opponent never broadcasts
             * a fresh WPM).
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
             * Lock the deadline ONCE per race. Later calls for the same race are ignored,
             * so the countdown keeps running toward the original deadline. `remainingMs`
             * comes from the server (time left when the page was rendered).
             */
            armCountdown(key, remainingMs) {
                if (this.raceKey === key && this.deadline !== null) return;
                this.raceKey = key;
                this.deadline = performance.now() + remainingMs;
            },

            /** Milliseconds left until start; <= 0 means the race may begin. */
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
             * Clear ONLY if this is genuinely a different race. A re-init of the same race
             * (Livewire morph, sudden death, component re-mount) must not wipe positions --
             * that's what used to snap every mascot back to 0 when a player paused typing.
             */
            resetForRace(key) {
                if (this.raceKey === key) return;
                this.opponents = {};
                this.raceKey = key;
                this.deadline = null; // different race -> the old deadline no longer applies
            },
        });
    }

    Alpine.data('raceArena', (config = {}) => ({
        countdown: 3,
        raceStarted: false,
        myId: config.myId,
        // Spectator: renders the arena (countdown + racer lanes) but never types, emits
        // progress, or gives up. This gates every input path.
        isSpectator: !!config.isSpectator,
        roomCode: config.roomCode || '',
        textToType: config.textToType || '',
        // Absolute race-start time (ms epoch); used only as the WPM start point & race
        // identity, NOT for the countdown (the client clock can't be trusted).
        raceStartsAtMs: config.raceStartsAt ? new Date(config.raceStartsAt).getTime() : null,
        // Time left until start per the SERVER when this page was rendered.
        // null = race not scheduled yet.
        raceStartsInMs: config.raceStartsInMs ?? null,
        _countdownInterval: null,
        // Saved race progress (0-100) for a mid-race reload; 0 = fresh start / spectator.
        resumeProgress: config.resumeProgress ?? 0,
        words: [],
        currentWordIndex: 0,
        // Pixels the paragraph is slid up by, so the word being typed stays inside the
        // three-line window. Written only by syncWordScroll().
        wordScrollOffset: 0,
        typedText: '',
        startTime: null,
        isFinished: false,
        hasError: false,
        correctCharsFromPastWords: 0,
        totalKeystrokes: 0,
        totalMistakes: 0,
        prevTypedLength: 0,

        /**
         * A space that was refused because the word isn't finished yet.
         *
         * Needed because `hasError` answers a DIFFERENT question: it is true only when the
         * typed text is not a PREFIX of the target. Type "the" for "then" and the screen
         * looks perfectly fine -- yet the space is refused, so without this flag the player
         * just sees a dead spacebar. Cleared on the next keystroke by checkInput().
         */
        justBlocked: false,
        _blockedFrame: null,

        // Local player's progress & WPM, reactive, read by the own mascot lane in the view. Updated each checkInput().
        progressPercent: 0,
        liveWpm: 0,

        // Throttle progress emits to the server: local visuals instant, network capped at ~120ms + a trailing flush.
        _lastEmit: 0,
        _emitTimer: null,

        // WPM ticker (1s): refreshes the number while the player has stopped typing.
        _wpmInterval: null,

        // Sudden death: a client-side timer, but the server's checkSuddenDeath() is still the final source of truth.
        suddenDeathActive: !!config.suddenDeathActive,
        suddenDeathRemaining: config.suddenDeathRemaining ?? 15,
        lockedByTimeout: false,
        _sdInterval: null,

        init() {
            this.words = this.textToType.split(' ');

            // Resume a mid-race reload where the player left off. The server persists only a
            // percentage, so rebuild the word position from it: advance over whole words that
            // fit inside the correct-character count, landing at the START of the first
            // unfinished word (word-lock then lets them keep typing it). A spectator or a
            // fresh racer has resumeProgress 0 and skips this entirely.
            if (!this.isSpectator && this.resumeProgress > 0) {
                this.restoreProgress();
            }

            // A mid-race reload lands on a word that may be far down the paragraph, so the
            // window has to be positioned before the player sees it. Runs for a fresh racer
            // too, where it settles on 0 -- one call rather than a branch.
            this.$nextTick(() => this.syncWordScroll());

            // Clear positions ONLY when entering a different race. If this component is
            // re-init'd for the same race (Livewire morph, sudden death), each mascot's
            // position is kept -- if wiped, every lane falls back to the old seed (0) until
            // the next payload arrives.
            if (this.$store.race) {
                this.$store.race.resetForRace(this.raceKey());
            }

            // Sudden death active at (re)init = the race is already running -> skip the countdown overlay.
            if (this.suddenDeathActive) {
                this.raceStarted = true;
                this.countdown = 'GO!';
                this.startTime = Date.now();
                this.startSuddenDeathClock();
                // A spectator doesn't type: no local WPM or input focus needed.
                if (!this.isSpectator) {
                    this.startWpmTicker();
                    this.$nextTick(() => {
                        if (this.$refs.typeInput) this.$refs.typeInput.focus();
                    });
                }
            } else {
                // Count down to the server's race_starts_at: the same absolute time on every screen.
                this.startSyncedCountdown();
            }

            // Server signal when the room is force-closed -> lock everything.
            this.$wire.on('force-finish', () => this.lockRace());

            // Bridge the .race.sudden_death event -> this component's countdown. Stored so it can be removed on destroy.
            this._onSuddenDeath = (ev) => this.syncSuddenDeath(ev.detail.remaining);
            window.addEventListener('race-sudden-death', this._onSuddenDeath);
        },

        // One race's identity: room + start time. A rematch in the same room uses a new
        // race_starts_at -> the key changes -> the store is cleared.
        raceKey() {
            return `${this.roomCode}@${this.raceStartsAtMs ?? 'pending'}`;
        },

        /**
         * Count down to the deadline locked in the store when this race was first seen.
         * Because the deadline is monotonic & outside the component, a Livewire morph or
         * Alpine re-init just continues the same count -- never restarts it from 3.
         */
        startSyncedCountdown() {
            // The server didn't schedule a race -> nothing to count down.
            if (this.raceStartsInMs === null) {
                this.beginRace();
                return;
            }

            this.$store.race.armCountdown(this.raceKey(), this.raceStartsInMs);

            // The deadline already passed when this component mounted (e.g. the arena was
            // morphed mid-race): jump straight into the race, don't show "3" again.
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
                    // ceil so 2001ms..3000ms => "3", etc. Show at least "1".
                    this.countdown = Math.max(1, Math.ceil(remainingMs / 1000));
                }
            };

            tick();
            this._countdownInterval = setInterval(tick, 100);
        },

        // startTime is pinned to the server's race_starts_at so every player's WPM shares the same start point.
        beginRace() {
            if (this.raceStarted) return;
            this.raceStarted = true;
            this.startTime = this.raceStartsAtMs ?? Date.now();
            // A spectator only watches: no local WPM or input focus.
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
            if (this._blockedFrame) {
                cancelAnimationFrame(this._blockedFrame);
                this._blockedFrame = null;
            }
        },

        // Sync the remaining time from the server & make sure the local clock is running.
        syncSuddenDeath(remainingFromServer) {
            this.suddenDeathActive = true;
            // Take the most conservative value if the clock is already running (avoid ticking back up).
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

        // Force-lock input & progress emits. Idempotent.
        lockRace() {
            if (this.lockedByTimeout) return;
            this.lockedByTimeout = true;
            this.isFinished = true;
            if (this._sdInterval) {
                clearInterval(this._sdInterval);
                this._sdInterval = null;
            }
            // WPM stops at its last value; the race is over for this player.
            if (this._wpmInterval) {
                clearInterval(this._wpmInterval);
                this._wpmInterval = null;
            }
            if (this._emitTimer) {
                clearTimeout(this._emitTimer);
                this._emitTimer = null;
            }
            if (this.$refs.typeInput) this.$refs.typeInput.blur();

            // checkSuddenDeath() is idempotent: safe to call from several clients at once.
            if (this.$wire) this.$wire.checkSuddenDeath();
        },

        /**
         * Signal a refused key/space, and make sure it signals AGAIN on every repeat.
         *
         * justBlocked drives the red highlight on the active word and input. Left simply set
         * to true, a burst of refusals wouldn't visibly re-signal (it's already true) -- which
         * reads as "nothing is stopping me" precisely when the player is leaning on the key
         * hardest. Dropping the flag for one frame re-asserts the cue every time.
         */
        nudgeBlocked() {
            if (this._blockedFrame) cancelAnimationFrame(this._blockedFrame);

            this.justBlocked = false;
            this._blockedFrame = requestAnimationFrame(() => {
                this._blockedFrame = null;
                this.justBlocked = true;
            });
        },

        /** Drop the refusal signal, cancelling a restart that hasn't painted yet. */
        clearBlocked() {
            if (this._blockedFrame) {
                cancelAnimationFrame(this._blockedFrame);
                this._blockedFrame = null;
            }

            this.justBlocked = false;
        },

        checkInput() {
            if (this.lockedByTimeout || this.isFinished || !this.raceStarted) return;

            // Any keystroke means the player is acting on the refused space, so drop the hint.
            this.clearBlocked();

            let targetWord = this.words[this.currentWordIndex];

            // ===== THE TYPED-SPACE PATH (soft keyboards) =====
            //
            // A space that reached the field means handleSpace never fired -- Gboard reports
            // keydown as 'Unidentified'/229 while composing, so the desktop path is silent on
            // most phones. Without this branch the space is simply not a valid prefix, gets
            // dropped by the rejection below, and the player is stuck on word one forever with
            // nothing on screen explaining why.
            //
            // Cut at the FIRST space and DISCARD the rest. Two independent reasons, both
            // load-bearing:
            //
            //  1. Stripping every space instead would let "th e" pass as an exact "the",
            //     silently rewriting the word-lock rule.
            //  2. Dropping the remainder caps this at ONE word per event. Swipe typing and
            //     paste deliver several words at once; looping over them would finish the race
            //     in a handful of gestures, and since placement is ranked by finish TIME that
            //     is instantly the optimal strategy -- the exact bug word-lock exists to kill.
            const spaceAt = this.typedText.indexOf(' ');

            if (spaceAt !== -1) {
                const candidate = this.typedText.slice(0, spaceAt);

                this.typedText = candidate;

                if (candidate === targetWord) {
                    // A word that landed whole never passed the per-character counter below, so
                    // credit it here or a swipe typist reads a free 100% accuracy while a
                    // desktop player pays for every typo. Only in this branch: placing it above
                    // the split would double-count against `if (isNewChar)` further down.
                    this.totalKeystrokes += Math.max(0, candidate.length - this.prevTypedLength);
                    this.prevTypedLength = candidate.length;

                    this.advanceWord();

                    // MUST return: targetWord above is now stale (advanceWord moved the index),
                    // so falling through would test the final-word branch against the previous
                    // word and emit a second progress update.
                    return;
                }

                // Refused. The stray space is gone; let the ordinary prefix rules below judge
                // what is left, exactly as if it had been typed key by key.
                this.nudgeBlocked();
            }

            const isNewChar = this.typedText.length > this.prevTypedLength;
            const isWrong = this.typedText.length > 0 && !targetWord.startsWith(this.typedText);

            // Reject a wrong character AS IT IS TYPED: the only way forward through a word is to
            // type its exact prefix, so a mistyped key never enters the field at all. This is
            // stricter than the space-level word-lock (which only blocks the jump BETWEEN words)
            // -- here the current word can never even hold a wrong letter.
            //
            // Backspace is always allowed: it shortens typedText, so isNewChar is false and this
            // branch is skipped (a shorter prefix of a valid prefix is still valid).
            if (isNewChar && isWrong) {
                // Still count the attempt so accuracy stays honest -- the keystroke happened,
                // it was just refused. Without this, accuracy would read a false 100%.
                this.totalKeystrokes++;
                this.totalMistakes++;

                // Drop the offending character; the field reverts to the last correct prefix.
                this.typedText = this.typedText.slice(0, this.prevTypedLength);
                this.hasError = false;
                this.nudgeBlocked();

                return;
            }

            this.hasError = false;

            if (isNewChar) {
                this.totalKeystrokes++;
            }
            this.prevTypedLength = this.typedText.length;

            // One formula shared with the WPM ticker (see correctCharsSoFar/currentWpm).
            let totalCorrectChars = this.correctCharsSoFar();
            let progressPercent = Math.floor((totalCorrectChars / this.textToType.length) * 100);

            let accuracyPercent = this.currentAccuracy();
            let liveWpm = this.currentWpm();

            // Local reactive state updated directly -> the own mascot moves instantly, no network wait.
            this.liveWpm = liveWpm;

            // The last word auto-completes when its final letter is correct.
            if (this.currentWordIndex === this.words.length - 1 && this.typedText === targetWord) {
                this.isFinished = true;
                this.progressPercent = 100;
                this.publishLocal(100, liveWpm, true);
                // force=true: finish must be sent immediately, not throttled.
                this.emitProgress(100, liveWpm, accuracyPercent, true);
                return;
            }

            this.progressPercent = progressPercent;
            this.publishLocal(progressPercent, liveWpm, false);
            this.emitProgress(progressPercent, liveWpm, accuracyPercent, false);
        },

        /**
         * Correct characters typed so far: the words already passed + the correct prefix
         * of the word currently being typed.
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

        /** Standard WPM: (correct chars / 5) divided by minutes elapsed. */
        currentWpm() {
            const minutes = (Date.now() - this.startTime) / 60000;
            if (minutes <= 0) return 0;

            return Math.floor((this.correctCharsSoFar() / 5) / minutes);
        },

        /**
         * Keeps the local `liveWpm` fresh while the player has stopped typing, so the value
         * sent to the server (e.g. on finish) isn't stale.
         *
         * Sends NOTHING over the network: each lane already computes its opponent's WPM from
         * progress + time (see liveWpmValue). Relying on the owner's emits would stall the
         * number, because an inactive opponent tab is frozen by the browser -- exactly the
         * bug fixed here.
         */
        startWpmTicker() {
            if (this._wpmInterval) return;

            this._wpmInterval = setInterval(() => {
                if (this.isFinished || this.lockedByTimeout || !this.raceStarted) return;

                this.liveWpm = this.currentWpm();
            }, 1000);
        },

        /** Running accuracy; split out so the ticker doesn't duplicate the formula. */
        currentAccuracy() {
            return this.totalKeystrokes > 0
                ? Math.round(((this.totalKeystrokes - this.totalMistakes) / this.totalKeystrokes) * 100)
                : 100;
        },

        // Publish the local position to the store (under our own userId key) so the own lane & opponents are uniform.
        publishLocal(progress, wpm, finished) {
            if (this.myId == null) return;
            this.$store.race.apply(this.myId, {
                progress_percent: progress,
                wpm: wpm,
                finished: finished,
            });
        },

        // Throttle ~120ms + a trailing-edge flush so the last position isn't lost; `force` is always immediate.
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

            // Too soon: schedule a trailing-edge flush with the latest values when the timer fires.
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

        /**
         * Rebuild the word position from a saved progress percentage (mid-race reload).
         *
         * The server stores only progress_percent, so convert it back to a correct-character
         * count and consume whole "word + space" spans until the next word wouldn't fit. The
         * cursor lands at the START of the first unfinished word: past words count toward
         * progress (correctCharsFromPastWords) and typedText is empty, so the player simply
         * carries on. We never restore a PARTIAL word -- word-lock only credits whole words,
         * so a partial prefix was never part of the saved progress anyway.
         */
        /**
         * Slide the paragraph so the word being typed sits on the top visible line.
         *
         * The paragraph is clipped to three lines; without this the player would have to
         * scroll it by hand, and on a phone every keystroke re-scrolls the focused input back
         * into view, so they could never see the words and the field at the same time.
         *
         * Reads offsetTop rather than counting lines: the words wrap differently at every
         * width, and a line count computed in JS would disagree with what the browser
         * actually laid out. Landing the active line at the top (offset 0 for the first line,
         * so nothing moves until the player reaches line two) buys the most lookahead.
         *
         * Silent when the ref is missing -- spectators and the finished/gave-up screens render
         * no paragraph at all.
         */
        syncWordScroll() {
            const track = this.$refs.wordsTrack;
            if (!track) return;

            const active = track.querySelector(`[data-word-index="${this.currentWordIndex}"]`);
            if (!active) return;

            // Compute an ABSOLUTE offset from the layout, never an incremental one.
            //
            // The old code read the active word's `offsetTop`, which is measured from the nearest
            // POSITIONED ancestor (offsetParent). Neither the track nor its wrappers are `relative`,
            // so offsetParent walked up to a card far above the paragraph -- baking that card's own
            // page position into the offset. The paragraph then slid up out of the clipping window
            // once the player advanced, so the active word vanished off the top.
            //
            // The obvious "add the gap to the current offset" is ALSO wrong: getBoundingClientRect()
            // reads the word's position while the 150ms slide transition is still animating, so the
            // gap is a partial, in-flight value. Accumulating those partial deltas makes the offset
            // drift a little more every keystroke -- the "position keeps changing, scroll is
            // erratic" bug.
            //
            // Fix: measure the active word's top RELATIVE TO THE TRACK'S top. Both elements are
            // translated together by the same translateY, so their difference cancels the transform
            // out entirely and yields the word's pure, final layout position inside the track --
            // which IS exactly the absolute translateY needed to bring that word to the track's top
            // edge. It doesn't matter that the read happens mid-animation, because the delta between
            // two elements that move together is transform-independent. Line one gives 0 (nothing
            // moves until line two), preserving lookahead.
            const trackTop = track.getBoundingClientRect().top;
            const activeTop = active.getBoundingClientRect().top;

            this.wordScrollOffset = Math.max(0, activeTop - trackTop);
        },

        restoreProgress() {
            const totalChars = this.textToType.length;
            const targetCorrect = Math.round((this.resumeProgress / 100) * totalChars);

            let consumed = 0;
            let index = 0;

            while (index < this.words.length) {
                // Each completed word contributes its length + 1 for the trailing space
                // (matches advanceWord: correctCharsFromPastWords += targetWord.length + 1).
                const span = this.words[index].length + 1;

                if (consumed + span > targetCorrect) break;

                consumed += span;
                index++;
            }

            // Clamp to the last valid index so words[currentWordIndex] is never undefined.
            // Landing on the last word is fine and never auto-finishes: typedText is empty and
            // finishing still requires an exact match / space the player has to type.
            this.currentWordIndex = Math.min(index, this.words.length - 1);
            this.correctCharsFromPastWords = consumed;
            this.progressPercent = Math.floor((consumed / totalChars) * 100);
        },

        /**
         * The DESKTOP space path: a physical spacebar asking to pass the current word.
         *
         * Soft keyboards do not reliably reach here -- Gboard reports keydown as
         * 'Unidentified'/229 while composing a word -- so the same request also arrives as a
         * space inside the field, handled by checkInput(). Both end in advanceWord(), which
         * owns the rule.
         */
        handleSpace(e) {
            // preventDefault() FIRST, and unreachable by any early return. This is what makes
            // the two space paths mutually exclusive: cancelling on the KEYDOWN phase kills the
            // default action, so beforeinput/insertion/input never happen and the space never
            // reaches the field -- leaving checkInput()'s value path blind to it. A `return`
            // placed above this line leaks the space into the field and both paths would run.
            // (It used to sit below the guard; that was harmless only because the input happens
            // to be :disabled on the very same condition -- an accident nobody was guarding.)
            e.preventDefault();

            if (this.lockedByTimeout || this.isFinished || !this.raceStarted) return;

            this.advanceWord();
        },

        /**
         * Word-lock: a word never passes until it is typed EXACTLY.
         *
         * This is the one thing that makes "progress == correct characters" an INVARIANT
         * rather than an assumption. The server derives correct chars from
         * progress% x text length and never sees the typed text, so it cannot check this
         * itself (see the note in MultiplayerLobby::updateRaceProgress).
         *
         * Space used to advance the word whatever was typed, which meant typing only part
         * of each word reached the finish line with far less effort -- and since placement
         * is ranked by finish TIME, that was the winning strategy. It also inflated the
         * server's "recomputed" Net WPM, because that number is derived from progress.
         *
         * Deliberately stricter than Solo, which stays permissive: a solo player who skips
         * letters only lowers their own WPM, whereas a racer was beating honest opponents.
         * A mistake is never punished beyond the time it costs -- backspace, fix it, move on.
         *
         * THE single definition of "a word may pass", reached from both space paths
         * (handleSpace for a physical spacebar, checkInput for a space that arrived as text).
         * Copying the rule into the second caller would give the anti-cheat gate two versions
         * that can drift apart -- and there is no server-side check to catch it if they do.
         * It is also the only `+=` writer of correctCharsFromPastWords; the sole other write
         * is restoreProgress(), which re-derives it from the server's stored percentage.
         */
        advanceWord() {
            const targetWord = this.words[this.currentWordIndex];

            // Covers an untouched word too (an empty string never equals a target word), so
            // space-spam is refused by this same gate rather than a separate check.
            if (this.typedText !== targetWord) {
                this.nudgeBlocked();

                return;
            }

            this.correctCharsFromPastWords += targetWord.length + 1;
            this.currentWordIndex++;
            this.typedText = '';
            this.hasError = false;
            this.prevTypedLength = 0;

            // After the DOM has re-rendered: the active word gains padding when it becomes
            // active, which can reflow the line it sits on, so offsetTop is only trustworthy
            // once Alpine has applied the new classes.
            this.$nextTick(() => this.syncWordScroll());

            // The last word can also finish via space (not only via an exact match in checkInput()).
            if (this.currentWordIndex >= this.words.length) {
                this.isFinished = true;
                this.progressPercent = 100;

                // typedText is empty & there's no next word, so
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

// Alpine may already be booting (register immediately) or not yet (register on alpine:init).
if (window.Alpine) {
    registerRaceArena(window.Alpine);
}
document.addEventListener('alpine:init', () => registerRaceArena(window.Alpine));
