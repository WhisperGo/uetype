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

import { evaluateTyping } from './word-mechanic';

/**
 * How long a client waits before asking the server again to resolve an expired race deadline.
 *
 * The ask has to be able to REPEAT (see askServerToResolve), but it must not become a poll:
 * every racer and spectator in the room runs this loop, and the server resolves the deadline
 * on the first ask that is actually due. Five seconds keeps a lost or premature attempt from
 * stranding a race while costing at most one request per client per five seconds, only ever
 * while a clock is already at zero.
 */
const DEADLINE_RETRY_MS = 5000;

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

            // Sudden-death deadline, kept in the store on the MONOTONIC clock for the SAME
            // reason as the start countdown's `deadline`: a Livewire morph / Alpine re-init must
            // never restart NOR freeze it. Sudden death used to live in component instance state
            // (a per-instance setInterval + reactive counter), and because the morph that turns
            // SD on also flips the arena's x-data string (suddenDeathActive false->true), that
            // morph could swap the Alpine instance -- leaving the timer ticking on a discarded
            // one while the visible (wire:ignore) banner froze until a manual refresh. In the
            // store the value is instance-independent: whichever instance survives reads the
            // identical deadline, and the shared 1s `now` tick drives the countdown. sdArmed
            // drives the banner's visibility (suddenDeathActive getter in the component).
            sdDeadline: null,
            sdArmed: false,

            /**
             * Arm sudden death from the server's REMAINING seconds. Idempotent & monotonic:
             * the EARLIEST deadline wins, so a duplicate or looser broadcast can never extend
             * the window -- mirrors the server, which never resets countdown_started_at once set.
             */
            armSuddenDeath(remainingSeconds) {
                const rem = Math.max(0, Number(remainingSeconds) || 0);
                const candidate = performance.now() + rem * 1000;
                if (this.sdDeadline === null || candidate < this.sdDeadline) {
                    this.sdDeadline = candidate;
                }
                this.sdArmed = true;
                this.startClock(); // the shared 1s tick drives the reactive recompute below
            },

            /** Whole seconds left in the sudden-death window (15 -> 0). Reactive via `now`. */
            sdRemainingSeconds() {
                if (this.sdDeadline === null) return 0;
                void this.now; // subscribe this read to the 1s tick so the banner recomputes
                return Math.max(0, Math.ceil((this.sdDeadline - performance.now()) / 1000));
            },

            // The two race deadlines, held here for exactly the reasons sdDeadline is: the
            // monotonic clock means a wrong client clock can't shorten or extend them, and
            // living in the store means a Livewire morph can't freeze them on a discarded
            // Alpine instance. Both are seeded from server-issued REMAINING seconds.
            //
            // graceDeadline = the point an untouched racer is dropped as DNF.
            // ceilingDeadline = the point the race closes for everyone.
            graceDeadline: null,
            ceilingDeadline: null,

            /**
             * Arm both race deadlines from the server's remaining seconds. Earliest-wins and
             * idempotent, same contract as armSuddenDeath: re-seeding on a morph or a second
             * render keeps the original deadline instead of quietly granting more time.
             */
            armRaceDeadlines(graceSeconds, ceilingSeconds) {
                const arm = (current, seconds) => {
                    const candidate = performance.now() + Math.max(0, Number(seconds) || 0) * 1000;
                    return current === null || candidate < current ? candidate : current;
                };

                this.graceDeadline = arm(this.graceDeadline, graceSeconds);
                this.ceilingDeadline = arm(this.ceilingDeadline, ceilingSeconds);
                this.startClock();
            },

            /** Whole seconds before an idle racer is dropped. Reactive via `now`. */
            graceRemainingSeconds() {
                if (this.graceDeadline === null) return 0;
                void this.now;
                return Math.max(0, Math.ceil((this.graceDeadline - performance.now()) / 1000));
            },

            /** Whole seconds before the race closes for everyone. Reactive via `now`. */
            ceilingRemainingSeconds() {
                if (this.ceilingDeadline === null) return 0;
                void this.now;
                return Math.max(0, Math.ceil((this.ceilingDeadline - performance.now()) / 1000));
            },

            reset() {
                this.opponents = {};
                this.raceKey = null;
                this.deadline = null;
                this.sdDeadline = null;
                this.sdArmed = false;
                this.graceDeadline = null;
                this.ceilingDeadline = null;
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
                this.sdDeadline = null; // ...and neither does the old sudden-death window
                this.sdArmed = false;
                this.graceDeadline = null; // ...nor the previous race's start/ceiling clocks
                this.ceilingDeadline = null;
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
        // rAF guard so syncWordScroll runs at most once per frame, AFTER the browser has laid
        // out -- mirrors the solo engine's schedulePositionUpdate/positionFrame exactly, which
        // is what makes the two scrolls feel identical (offsetTop read post-reflow, not pre).
        _scrollFrame: null,
        // Scroll layout snapshot: _lineOf maps wordIndex -> its visual line number, measured ONCE
        // from the fully-wrapped paragraph so a read taken mid-transition can't move a word between
        // lines; _lineH is one line's height. Both dropped by _invalidateScroll() on every cause of
        // a re-wrap (resize, ResizeObserver on the track, fonts.ready) and on (re)init.
        _lineOf: null,
        _lineH: null,
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

        // Sudden death: state lives in the global `race` store (morph-proof), read here through
        // getters -- so a Livewire re-init can never freeze the visible countdown. The server's
        // checkSuddenDeath() is still the final source of truth for closing the race.
        lockedByTimeout: false,

        // True once the store has been armed (drives the banner's x-show).
        get suddenDeathActive() {
            return this.$store.race ? this.$store.race.sdArmed : false;
        },

        // Whole seconds left, derived from the store's monotonic deadline + 1s `now` tick.
        get suddenDeathRemaining() {
            return this.$store.race ? this.$store.race.sdRemainingSeconds() : 0;
        },

        // Seconds before an idle racer is dropped, and before the race closes for everyone.
        get startGraceRemaining() {
            return this.$store.race ? this.$store.race.graceRemainingSeconds() : 0;
        },

        get raceDeadlineRemaining() {
            return this.$store.race ? this.$store.race.ceilingRemainingSeconds() : 0;
        },

        // The store's shared 1s tick, exposed so it can be $watch'd from here. Watching
        // '$store.race.now' directly would throw on the render where the store isn't up yet;
        // every other reader in this component already guards it the same way.
        get _clockTick() {
            return this.$store.race ? this.$store.race.now : 0;
        },

        // Monotonic timestamp of the last checkRaceDeadline() call, so the retry above is
        // throttled rather than fired on every tick.
        _lastDeadlineAsk: 0,

        /**
         * Show the "start typing" countdown.
         *
         * Gated on progressPercent === 0 -- the SAME quantity the server judges by -- rather
         * than on totalKeystrokes. They are not the same: two mistyped characters raise the
         * keystroke count while progress stays at 0, and a player warned by one rule but
         * dropped by another would be told they were fine right up until they weren't.
         *
         * The countdown exists because a deadline nobody can see does not make anyone start.
         * That was the actual complaint; enforcing it silently would have fixed the hang and
         * left the game feeling arbitrary instead.
         */
        /**
         * Seconds an idle racer actually has left -- the EARLIER of the two clocks that can
         * end their race.
         *
         * Both can genuinely be live at once: a fast opponent can finish a 45-word text well
         * inside the 20-second grace window, and sudden death then runs on top of it. Showing
         * the grace number alone would promise time that sudden death is about to take away;
         * showing sudden death alone would promise time the grace rule is about to take away.
         * The minimum is the only number that is true in both directions -- the same principle
         * as §3.3/§3.4, applied before it could become another bug.
         */
        get startPromptRemaining() {
            return this.suddenDeathActive
                ? Math.min(this.startGraceRemaining, this.suddenDeathRemaining)
                : this.startGraceRemaining;
        },

        get showStartPrompt() {
            return this.raceStarted
                && !this.isSpectator
                && !this.isFinished
                && !this.lockedByTimeout
                && this.progressPercent === 0
                && this.startPromptRemaining > 0;
        },

        /**
         * Whole race time left (the hard ceiling), shown so nobody is racing against a limit
         * they cannot see. Hidden once sudden death starts: the ceiling stands down then
         * (see resolveRaceDeadlinesIfElapsed), so continuing to display it would be counting
         * toward a deadline that no longer governs anything.
         */
        get showRaceClock() {
            return this.raceStarted && !this.suddenDeathActive && this.raceDeadlineRemaining > 0;
        },

        /** m:ss for the race clock -- a bare "147" reads as nothing at this scale. */
        get raceClockLabel() {
            const left = Math.max(0, this.raceDeadlineRemaining);

            return `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
        },

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

            // Fresh render -> drop the cached line snapshot so it is re-measured from the newly
            // laid-out paragraph (word count/width may differ from a previous race).
            this._lineOf = null;
            this._lineH = null;

            // A mid-race reload lands on a word that may be far down the paragraph, so the
            // window has to be positioned before the player sees it. Runs for a fresh racer
            // too, where it settles on 0 -- one call rather than a branch. $nextTick waits for
            // Alpine to render the words, then scheduleWordScroll's rAF waits for layout.
            this.$nextTick(() => this.scheduleWordScroll());

            // Clear positions ONLY when entering a different race. If this component is
            // re-init'd for the same race (Livewire morph, sudden death), each mascot's
            // position is kept -- if wiped, every lane falls back to the old seed (0) until
            // the next payload arrives.
            if (this.$store.race) {
                this.$store.race.resetForRace(this.raceKey());
            }

            // Seed the store from the server's authoritative SD state (a mid-race reload, or a
            // re-init while SD is already running, lands here). AFTER resetForRace so a genuinely
            // new race has already cleared any stale window; armSuddenDeath is earliest-wins, so
            // re-seeding the same race keeps the original deadline rather than extending it.
            if (config.suddenDeathActive && this.$store.race) {
                this.$store.race.armSuddenDeath(config.suddenDeathRemaining ?? 15);
            }

            // Sudden death active at (re)init = the race is already running -> skip the countdown overlay.
            if (this.suddenDeathActive) {
                this.raceStarted = true;
                this.countdown = 'GO!';
                this.startTime = Date.now();
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

            // Close the race the instant the shared window hits 0, on the LIVE instance. The
            // countdown itself no longer needs starting -- it is derived from the store's
            // monotonic deadline and the shared 1s `now` tick, so it can never freeze on a
            // discarded instance (the "counts down only after a manual refresh" bug). This watch
            // just fires the one-shot lockRace() at 0; it re-evaluates whenever `now` ticks, and
            // lockRace() is idempotent, so a redundant fire is a no-op.
            this.$watch('suddenDeathRemaining', (left) => {
                if (this.suddenDeathActive && left <= 0) this.lockRace();
            });

            // Seed the race deadlines from the server's remaining seconds (see the store).
            // AFTER resetForRace, so a genuinely new race starts from a clean pair.
            if (this.$store.race) {
                this.$store.race.armRaceDeadlines(
                    config.startGraceRemaining ?? 0,
                    config.raceDeadlineRemaining ?? 0,
                );
            }

            // Ask the SERVER to resolve the deadline once a clock reaches zero. This is the
            // only signal available when nobody is typing: the server also enforces both
            // deadlines on the progress path, but that path needs someone to still be emitting.
            //
            // Driven by the store's 1s tick rather than by $watch on the countdowns themselves,
            // and that is the fix rather than a refinement. Alpine's $watch fires on a value
            // CHANGE; both clocks reach 0 and then sit there forever, so watching them gives
            // exactly ONE attempt per race. One dropped request -- a throttled tab, a failed
            // round-trip, or a clock armed slightly early being told "not yet" -- and the
            // server is never asked again. A race that hangs is precisely the bug this whole
            // feature exists to prevent, so the trigger must be able to repeat.
            //
            // resolveRaceDeadlinesIfElapsed is idempotent and race-safe, so a retry (or several
            // clients retrying together) costs one resolution. Deliberately does NOT lock the
            // local race: the grace deadline drops only the idle players, and everyone else
            // must keep typing.
            const askServerToResolve = () => {
                if (!this.raceStarted || this.lockedByTimeout || !this.$wire) return;

                // The ceiling stands down while sudden death runs (that window owns the ending
                // server-side), but the START GRACE keeps running straight through it -- so the
                // guard belongs on the ceiling alone. Applying it to both is what left the
                // server willing to drop an idle player while no client would ever ask.
                const due = this.startGraceRemaining <= 0
                    || (this.raceDeadlineRemaining <= 0 && !this.suddenDeathActive);

                if (!due) return;

                const at = performance.now();
                if (at - this._lastDeadlineAsk < DEADLINE_RETRY_MS) return;

                this._lastDeadlineAsk = at;
                this.$wire.checkRaceDeadline();
            };

            this.$watch('_clockTick', askServerToResolve);

            // A word's line number is only valid for the WIDTH and FONT it was measured at, so
            // every cause of a re-wrap has to drop the snapshot and re-measure. Miss one and
            // _lineOf silently keeps scrolling to a line the word is no longer on, with no way
            // back: the offset is a pure function of the (now wrong) map. rAF-coalesced via
            // scheduleWordScroll, so a burst of events costs one read.
            this._invalidateScroll = () => {
                this._lineOf = null;
                this._lineH = null;
                this.scheduleWordScroll();
            };

            // 1. Rotate / window resize. Kept alongside the observer below because on some mobile
            //    browsers the soft keyboard resizes the visual viewport without resizing the track.
            this._onResize = this._invalidateScroll;
            window.addEventListener('resize', this._onResize);

            // 2. Anything that changes the TRACK's own box -- none of which fire `resize`: the
            //    $arenaDense flip when the 4th racer joins (p-8 -> p-5), the sudden-death banner
            //    appearing in flow above the box, an opponent lane wrapping and changing the card
            //    width. Each re-wraps the paragraph. The observer also fires once on observe,
            //    which doubles as the initial measurement.
            if (typeof ResizeObserver !== 'undefined') {
                this._scrollObserver = new ResizeObserver(() => this._invalidateScroll());
                this.$nextTick(() => {
                    if (this.$refs.wordsTrack) this._scrollObserver.observe(this.$refs.wordsTrack);
                });
            }

            // 3. The web font landing after first paint re-wraps every line. Measured before that,
            //    the snapshot describes a FALLBACK-font layout -- wrong for the whole race, and
            //    this is not a resize, so nothing else would ever correct it.
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(() => this._invalidateScroll());
            }
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
            if (this._onResize) {
                window.removeEventListener('resize', this._onResize);
                this._onResize = null;
            }
            // The observer holds a reference to the track element, so it must be disconnected or
            // it outlives the component (the paragraph is removed on finish/give-up).
            if (this._scrollObserver) {
                this._scrollObserver.disconnect();
                this._scrollObserver = null;
            }
            if (this._scrollFrame) {
                cancelAnimationFrame(this._scrollFrame);
                this._scrollFrame = null;
            }
            if (this._countdownInterval) {
                clearInterval(this._countdownInterval);
                this._countdownInterval = null;
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

        /**
         * Sync sudden death from a server broadcast: arm the shared store deadline (idempotent,
         * earliest-wins) so every instance -- including any that a Livewire morph creates after
         * this -- reads the same countdown. The reactive display & the lockRace()-at-0 watch both
         * flow from the store, so nothing here has to touch a per-instance timer.
         */
        syncSuddenDeath(remainingFromServer) {
            if (!this.$store.race) return;
            this.$store.race.armSuddenDeath(remainingFromServer);
            if (this.suddenDeathRemaining <= 0) this.lockRace();
        },

        // Force-lock input & progress emits. Idempotent.
        lockRace() {
            if (this.lockedByTimeout) return;
            this.lockedByTimeout = true;
            this.isFinished = true;
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

            // SELF-HEAL the window on every keystroke -- the one thing solo does and the race did
            // not. Solo's updatePosition() runs from ~9 sites, every keystroke among them, so a
            // single bad frame corrects itself on the next character. The race only recomputed on
            // word-advance, so anything wrong -- a stale line snapshot, or a morph that wiped the
            // track's inline transform -- survived until the next space. Cheap: rAF-coalesced to
            // one call per frame, and once the snapshot exists syncWordScroll is a map lookup, not
            // a DOM read.
            this.scheduleWordScroll();

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

            const verdict = evaluateTyping(targetWord, this.typedText, this.prevTypedLength);

            this.hasError = verdict.hasError;
            this.totalKeystrokes += verdict.keystrokes;
            this.totalMistakes += verdict.mistakes;
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

        // Coalesce scroll updates to one per animation frame, run AFTER layout -- the exact
        // shape of the solo engine's schedulePositionUpdate(). Reading offsetTop inside rAF (not
        // synchronously / in $nextTick) guarantees the paragraph has already re-laid-out, so the
        // window lands on the settled position instead of a pre-reflow one. That post-layout
        // timing is what makes the race scroll move like solo rather than a frame behind.
        scheduleWordScroll() {
            if (this._scrollFrame) return;
            this._scrollFrame = requestAnimationFrame(() => {
                this._scrollFrame = null;
                this.syncWordScroll();
            });
        },

        /**
         * Slide the paragraph so the word being typed stays inside the three-line window.
         *
         * The paragraph is clipped to three lines; without this the player would have to scroll
         * it by hand, and on a phone every keystroke re-scrolls the focused input back into
         * view, so they could never see the words and the field at the same time.
         *
         * Solo's rule, verbatim (typing-game.js updatePosition): nothing moves until the active
         * word reaches the THIRD line, and that line then lands on the MIDDLE visible line --
         * one line of context above, one of lookahead below. Reacting a line later than "active
         * word to the top" is what removed the jitter of dropping early and bouncing back on the
         * next line's first letter: lines 1 and 2 are both dead still.
         *
         * Each word's line index is SNAPSHOT once into _lineOf and read from that map after. A
         * word's line is a fact of the text layout -- it changes only on a real re-wrap, and
         * every cause of one invalidates the snapshot (see init(): resize, a ResizeObserver on
         * the track, and fonts.ready). Reading it live per call meant re-measuring a paragraph
         * that could be mid-morph or mid-transform-transition.
         *
         * Silent when the ref is missing -- spectators and the finished/gave-up screens render
         * no paragraph at all.
         */
        syncWordScroll() {
            const track = this.$refs.wordsTrack;
            if (!track) return;

            // MEASURE EVERY WORD'S LINE ONCE, then never trust a live re-measure again.
            //
            // Why not just measure the active word each time: reading offsetTop against a
            // paragraph that is mid-transform-transition returns a transient value, so the active
            // word's computed line could flip between frames and the paragraph bobbed up and down.
            //
            // Treat each word's line as a FIXED FACT of the text layout instead. A word's line
            // only changes on a real re-wrap, never while typing -- so the first time we can
            // cleanly measure the full paragraph we snapshot the line index of every word into
            // _lineOf[] and read from that map after. Every cause of a re-wrap drops the snapshot
            // (see init(): resize, ResizeObserver on the track, fonts.ready), which is what keeps
            // "measure once" from turning into "measure once, wrongly, forever".
            //
            // NOTE: this snapshot is NOT what fixed the box jumping -- that was the missing
            // `wire:ignore` on the paragraph card (see the Blade comment). An OPPONENT's progress
            // never morphs our DOM at all: `.race.progress` goes straight into the Alpine store
            // with no Livewire round-trip (race-echo.js).
            if (!this._lineOf) {
                const first = track.querySelector('[data-word-index="0"]');
                const lineH = first ? first.offsetHeight : 0;
                const top0 = first ? first.offsetTop : 0;
                if (!lineH) return; // paragraph not laid out yet; a later call will catch it.

                this._lineH = lineH;
                const map = {};
                let maxLine = 0;
                track.querySelectorAll('[data-word-index]').forEach((el) => {
                    const idx = Number(el.dataset.wordIndex);
                    const line = Math.max(0, Math.round((el.offsetTop - top0) / lineH));
                    map[idx] = line;
                    if (line > maxLine) maxLine = line;
                });
                // Only trust the snapshot once the paragraph has actually wrapped into multiple
                // lines (a single-line measure mid-morph would map every word to line 0). Until
                // then, leave _lineOf null and retry on the next scheduled call.
                if (maxLine === 0 && Object.keys(map).length > 1) return;
                this._lineOf = map;
            }

            // The active word's line comes from the snapshot, not a live read.
            const lineIndex = this._lineOf[this.currentWordIndex] ?? 0;
            const lineHeight = this._lineH;

            // Solo's rule: scroll only once the active word reaches the THIRD line (index >= 2),
            // then land that line on the MIDDLE visible line (index - 1), keeping one line of
            // context above. Offset is always a whole number of line heights -> never mid-line,
            // and it is a pure step function of the (fixed) line index -> it can never bob back.
            this.wordScrollOffset = lineIndex >= 2 ? (lineIndex - 1) * lineHeight : 0;
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

            // Re-evaluate the window after the index moved. $nextTick lets Alpine apply the new
            // active-word classes, then scheduleWordScroll's rAF reads offsetTop post-layout.
            this.$nextTick(() => this.scheduleWordScroll());

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
