/**
 * Solo typing engine (Time / Words / Survival), including Ghost Mode.
 *
 * These 846 lines used to live as an inline <script> in typing-engine.blade.php: couldn't
 * be linted, minified, or browser-cached as a separate asset, and were re-sent every time
 * the /typing page loaded.
 *
 * Installed via a spread into x-data (`...typingGame(text)`) because this component shares
 * scope with two @entangle properties (currentMain/currentSub) written two-way from the
 * mode-picker buttons in the markup.
 *
 * IMPORTANT: because this object is SPREAD, never use a getter here -- a spread evaluates
 * the getter once then freezes the result. Use a plain method instead.
 *
 * That is not hypothetical. The live WPM sparkline (since removed) silently never drew for
 * exactly this reason: as a getter it was evaluated once at spread time, when wpmHistory was
 * still empty, and stayed frozen at '' for the rest of the session. Nothing errored -- the
 * SVG simply rendered blank forever.
 */
// Survival stamina presets.
//   sMax/sStart : capacity & starting stamina
//   graceSec    : opening seconds with softened drain
//   dStart      : passive drain per second
//   dAccel      : drain acceleration per second²
//   refill      : stamina per correct character
//   penalty     : extra drain when a dirty word is committed (per-word cap)
const SURVIVAL_PRESETS = {
    easy:   { sMax: 120, sStart: 120, graceSec: 4, dStart: 3.0, dAccel: 0.11, refill: 2.4, penalty: 7 },
    medium: { sMax: 100, sStart: 100, graceSec: 3, dStart: 3.8, dAccel: 0.20, refill: 1.9, penalty: 10 },
    hard:   { sMax: 85,  sStart: 70,  graceSec: 0, dStart: 5.5, dAccel: 0.40, refill: 1.3, penalty: 16 },
};

// Survival "burst shield" (Temuan 3, docs/review-performance-2026-07-27.md).
// The problem: drain is TIME-based but refill is PER-CHARACTER, so a fast burst followed by a
// natural pause to breathe used to bleed stamina -- typing fast was not consistently safer.
// Each correct character now banks a little shield TIME (capped); while shield remains, drain
// is reduced, so a burst buys a soft landing for the short pause right after it.
// Why this can't be exploited into infinite survival: shield only ACCRUES from typing, always
// decays by real elapsed time each tick, and the reduction factor is > 0 -- so as the drain
// acceleration ramps up, net stamina still trends down and the run always ends. A fast typist
// simply lasts LONGER. Numbers are gameplay-tunable; there is no automated test for stamina
// balance, so verify the feel by playtest.
const SHIELD_PER_CHAR_MS = 140;   // shield time banked per correct character
const SHIELD_MAX_MS = 1400;       // cap so one burst can't bank unlimited safety (~1.4s)
const SHIELD_DRAIN_FACTOR = 0.35; // drain multiplier while shield is active

// Cap on error events sent to the server. A normal practice session is well below this;
// 500 errors in one test ≈ below 50% accuracy in time 120 -- that's mashing.
const MAX_ERROR_EVENTS = 500;

// Cap on keystroke intervals sent to the server for timing analysis (anti-cheat, §7.1).
// A random reservoir sample of this size keeps the payload small AND representative -- a
// cheat can't just "type honestly for the first N keys" to seed a human-looking prefix.
const MAX_KEY_INTERVALS = 300;

function survivalConfig(difficulty) {
    return SURVIVAL_PRESETS[difficulty] || SURVIVAL_PRESETS.medium;
}

/**
 * Floor between two Clan War progress reports, in milliseconds.
 *
 * Was 5000, and that number came from a constraint that no longer exists: the report was a
 * Livewire call, and textToType is a public property that rode along on every round trip, so
 * each ping dragged the whole text up and back. Reporting was made rare to make it cheap.
 *
 * The cost of that was paid entirely by the player who reloaded. Five seconds at 60 WPM is
 * twenty-five characters -- five words -- and they were lost every time, on top of the ping
 * that never survived the unload at all. "Coarse is safe" was true about correctness and quietly
 * false about the experience it was protecting.
 *
 * The report is now a plain endpoint carrying five integers, so it can run at the pace the
 * player actually generates events. One second still collapses a burst of finished words into
 * a single write without ever being felt.
 */
const WAR_PROGRESS_MIN_INTERVAL_MS = 1000;

/**
 * Mirrors race-arena.js restoreProgress(): rebuild a word position from a saved percentage.
 * Lives in its own module so Vitest can cover the arithmetic directly -- see war-resume.js.
 */
import { resumePosition } from './war-resume';

/**
 * @param {string} initialText  the text to type.
 * @param {object|null} warAttempt  Clan War attempt state from the server (TypingEngine::$warLock):
 *   { mode, config, resume, progress, remaining, budget, expired }. Null for a solo session.
 *   Every clock in it is the SERVER's: a refresh must not be able to wind one back.
 */
export default function typingGame(initialText, warAttempt = null) {
    return {
        warAttempt,
        // Seconds the countdown starts from. Normally the sub-mode, but a RESUMED war attempt
        // gets whatever the server says is left of its slot -- that is what makes reloading
        // cost real time instead of handing back a full test.
        timerStart: 0,
        _warProgressSentAt: 0,
        _warProgressLast: -1,
        targetArray: initialText.split(''),
        currentIndex: 0,
        inputResults: [],
        startTime: null,

        /**
         * When the countdown began, as distinct from when TYPING began.
         *
         * They were the same field once, and that was the bug behind "the timer resets on
         * refresh". `startTime` is stamped on the first keystroke because WPM is measured over
         * typing, not over sitting still -- correct, and it must stay that way. But the war
         * countdown was read off the same stamp, so on a resumed attempt the clock simply did
         * not move until a key was pressed: the server kept counting, the screen did not, and a
         * player could sit on a paused 35-second display for as long as they liked.
         *
         * Null on a fresh attempt (armed by the first keystroke, so reading time stays free) and
         * on every solo session.
         */
        countdownStart: null,
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
        // Client-side render structure for the text spans (Alpine x-for), so a restart swaps
        // text in-place instead of the server re-rendering ~600 spans. Rebuilt in resetProgress.
        renderWords: [],
        extraChars: {},
        cursorLeft: 0,
        cursorTop: 0,

        // --- Ghost Mode: a second cursor with linear pacing (constant WPM), filled via the 'ghost-selected' event. ---
        ghostActive: false,
        ghostWpm: 0,
        ghostLabel: '',
        ghostCharIndex: 0,
        ghostCursorLeft: 0,
        ghostCursorTop: 0,
        ghostFinished: false,
        ghostFinishTime: null,
        _ghostRafId: null,

        // Cache of every character's DOM position, built ONCE per layout so the ghost rAF loop
        // (~60fps) reads from memory instead of forcing a reflow (offsetLeft/offsetTop) up to 3x
        // per frame. Invalidated on reset (new text) and on window resize (re-wrap).
        _charPosCache: null,
        _onGhostResize: null,

        capsLockOn: false,

        isTyping: false,
        typingTimeout: null,
        totalKeystrokes: 0,
        correctKeystrokes: 0,

        // Soft-keyboard Backspace can be reported twice: once as keydown, once as a
        // `deleteContentBackward` beforeinput. Set by whichever arrives first so the other
        // knows to stand down -- see onTypingInputKeydown / onTypingBeforeInput.
        _softDeleteHandled: false,

        // AFK tracking: timestamp of the last keystroke, and the longest gap between two of
        // them. The server rejects abandoned runs from this (see TypingEngine::saveResult) --
        // the gap is what separates "walked away" from "types slowly", which no average can.
        lastKeyTime: null,
        maxIdleMs: 0,
        // Inter-keystroke intervals (ms) for server-side timing analysis (§7.1). A reservoir
        // sample bounded to MAX_KEY_INTERVALS; keyStrokeCount tracks the true total so the
        // sampler weights every keystroke equally, not just the first MAX_KEY_INTERVALS.
        keyIntervals: [],
        keyStrokeCount: 0,
        wpmHistory: [],
        rawHistory: [],
        missedChars: {},
        // One entry per target character that wasn't typed correctly: {second, index, actual}.
        // missedChars knows WHICH KEY was missed; this also knows WHEN & IN WHICH WORD.
        errorEvents: [],
        modeChangedCleanup: null,

        // --- Survival: stamina drains per second, refills per correct character, empty = game over. ---
        stamina: 100,         // current stamina value
        staminaMax: 100,      // bar capacity/cap (set from the difficulty preset)
        staminaPct: 100,      // percentage for the UI (0–100)
        survivalCfg: null,    // active parameter preset (see SURVIVAL_PRESETS)
        staminaInterval: null,// drain tick loop (smooth, ~100ms)
        lastTickTime: 0,      // last tick timestamp (for a precise Δt)
        shieldMs: 0,          // burst shield: banked drain-reduction time (see SHIELD_* consts)
        currentWordDirty: false, // whether the word being typed has already errored
        committedWordResults: {}, // {wordIndex: 'clean'|'dirty'} — words already scored (idempotent)

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

            // A resumed war attempt is already on the clock, so the countdown starts NOW rather
            // than on the first keystroke. Waiting for a key was what let a reload hand back the
            // full slot: the server kept counting from its anchor while the screen sat frozen at
            // whatever it was handed, so the pressure the slot is defined by simply stopped.
            //
            // Fresh attempts are untouched -- they still arm on the first keystroke, which is
            // what keeps reading time free (see ClanWarAttempt::GRACE_SECONDS).
            if (this.warAttempt?.deadlineArmed && !this.warAttempt?.expired) {
                this.startClock();
            }

            // Cleanup is stored so listeners don't stack up on Alpine remount.
            const cleanup = this.$wire.on('mode-changed', (payload) => {
                this.resetForNewText(payload.text ?? '');
            });
            this.modeChangedCleanup = typeof cleanup === 'function' ? cleanup : null;

            // A window resize re-wraps the text, so the cached character positions are stale.
            // Drop them; the ghost loop rebuilds lazily. The real caret is unaffected -- it
            // re-reads the active character's offset live on every keystroke.
            this._onGhostResize = () => { this._charPosCache = null; };
            window.addEventListener('resize', this._onGhostResize);
        },

        // Ghost is only valid in time/words. If global state lingers from a previous mode
        // while the current mode is survival, drop it -- don't revive it on Alpine remount.
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

        // Full reset for NEW text sent via a Livewire event.
        resetForNewText(newText) {
            this.targetArray = newText.split('');
            this.resetProgress();
        },

        // Reset state (wordBounds, survival, etc.) from the current targetArray.
        resetProgress() {
            this.stopRuntime();

            this.currentIndex = 0;
            this.inputResults = [];
            this.startTime = null;
            this.countdownStart = null;
            this.isStarted = false;
            this.isFinished = false;

            // Reset (e.g. restart / mode change mid-session) -> the chat overlay shows again.
            window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } }));
            // A resumed war slot starts from the server's remaining seconds, not the sub-mode:
            // 25 seconds into a 30-second slot the countdown must read 5, or the reload the
            // whole attempt system exists to price would simply hand back a fresh test.
            this.timerStart = (this.currentMain === 'time')
                ? (this.warAttempt?.remaining ?? parseInt(this.currentSub))
                : 0;
            this.timer = this.timerStart;
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

            // The survival preset is taken from currentSub (easy|medium|hard).
            this.survivalCfg = survivalConfig(this.currentSub);
            this.staminaMax = this.survivalCfg.sMax;
            this.stamina = this.survivalCfg.sStart;
            this.staminaPct = Math.round((this.stamina / this.staminaMax) * 100);
            this.lastTickTime = 0;
            this.shieldMs = 0;
            this.currentWordDirty = false;
            this.committedWordResults = {};
            this.drainEventCount = 0;
            this.drainFlash = false;

            this.wordBounds = [];
            this.extraChars = {};
            this.currentWordIndex = 0;
            this.totalKeystrokes = 0;
            this.correctKeystrokes = 0;
            this.lastKeyTime = null;
            this.maxIdleMs = 0;
            this.keyIntervals = [];
            this.keyStrokeCount = 0;
            this.wpmHistory = [];
            this.rawHistory = [];
            this.missedChars = {};
            this.errorEvents = [];
            this.ghostCharIndex = 0;
            this.ghostFinished = false;
            this.ghostFinishTime = null;
            this.ghostCursorLeft = 0;
            this.ghostCursorTop = 0;
            // New text -> the old character-position cache no longer maps anything.
            this._charPosCache = null;

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

            // Build the client render structure from wordBounds (restart delay, Tier 2,
            // docs/review-performance-2026-07-27.md). One entry per word: its characters (each
            // with its ABSOLUTE index i -- needed for the char-{i} id and inputResults[i] binding)
            // and the trailing space index (null on the last word). The Alpine x-for in the view
            // renders from this, so swapping text on restart never round-trips ~600 spans.
            this.renderWords = this.wordBounds.map((b) => {
                const chars = [];
                for (let i = b.start; i <= b.end; i++) {
                    chars.push({ c: this.targetArray[i], i });
                }

                return { chars, spaceIndex: b.space };
            });

            // AFTER wordBounds/renderWords are built (it reads both) and BEFORE the caret draw
            // below, so the retry loop places the caret and the scroll window at the resumed
            // line for free rather than flashing at word one first.
            this.restoreWarProgress();

            this.caretInstant = true;
            this.caretDrawn = false;
            // Draw the caret with a per-frame RETRY until it's actually drawn.
            // Why retry: after a mode-change remount, char-0 is sometimes NOT rendered yet on
            // the first draw -> updatePosition() returns early (activeEl null) -> moveCaret
            // never runs -> transform:translate() is NEVER set -> the caret sticks at 0,0
            // (top-left of the line). This is most common in Survival because its DOM is much
            // heavier (16 stamina cells via x-for) so text layout lags a frame or two; the
            // lighter Standard almost always succeeds on the first draw. caretDrawn only
            // becomes true after moveCaret truly draws, so we retry each frame (max 12 ~200ms)
            // until char-0 exists & the caret is drawn. caretInstant stays true -> all these
            // placements are instant (no transition), matching the isTyping gate on the caret class.
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

        // Record ONE target character that wasn't typed correctly. MUST be called from
        // inside the exact same guard that increments missedChars -- that's what keeps the
        // event count === the missedChars count, the invariant the result page uses to line
        // up the chart points with the heatmap numbers right below them.
        //   charIndex : absolute index in targetArray
        //   actual    : the key pressed (handleInput already guarantees length 1), or null
        //               for a SKIPPED character -- the user pressed space, there was never a
        //               keystroke for this character.
        // Record the gap since the previous keystroke, then stamp this one. Called for every
        // real key INCLUDING backspace: correcting a word is still the player being present.
        trackIdle() {
            const now = Date.now();

            if (this.lastKeyTime !== null) {
                const gap = now - this.lastKeyTime;
                if (gap > this.maxIdleMs) this.maxIdleMs = gap;

                // Reservoir-sample the interval so the server sees a representative slice of
                // the WHOLE run, not just the opening (§7.1). Fill first, then each later
                // interval replaces a random slot with probability MAX/count -- uniform over
                // the session, so a bot can't seed a human-looking prefix and forge the rest.
                this.keyStrokeCount++;
                if (this.keyIntervals.length < MAX_KEY_INTERVALS) {
                    this.keyIntervals.push(gap);
                } else {
                    const j = Math.floor(Math.random() * this.keyStrokeCount);
                    if (j < MAX_KEY_INTERVALS) this.keyIntervals[j] = gap;
                }
            }

            this.lastKeyTime = now;
        },

        recordError(charIndex, actual) {
            if (this.errorEvents.length >= MAX_ERROR_EVENTS) return;
            this.errorEvents.push({
                second: this.startTime ? Math.floor((Date.now() - this.startTime) / 1000) : 0,
                index: charIndex,
                actual: actual,
            });
        },

        // --- Survival helpers ---

        // Mark the active word "dirty" on an error. Stamina is docked later on commit, not here.
        markWordDirty() {
            if (this.currentMain !== 'survival' || this.isFinished) return;
            this.currentWordDirty = true;
        },

        // Score one completed word. A dirty word takes the stamina penalty just once (per-word
        // cap, idempotent if re-committed); a clean word takes nothing.
        completeWord(wordIndex) {
            // Every path that finishes a word passes through here, which is exactly why the
            // resume ping hangs off it: one hook instead of three, and it can never drift out
            // of step with what the player actually completed.
            this.reportWarProgress();

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

        /**
         * Tell the server where this Clan War attempt has got to, and what this session spent
         * getting there.
         *
         * Two things travel together and must never be separated: the POSITION (so a reload
         * resumes here) and this session's LEDGER -- its own typing clock and its own keystroke
         * counts. The ledger is what makes a resumed run score honestly, because the session
         * that follows a refresh can only report itself; everything before it is whatever these
         * pings managed to bank.
         *
         * That coupling is also what makes a dropped ping harmless. Losing one loses the
         * position AND the time AND the characters together, so what survives is still an
         * internally consistent run -- the player simply resumes a little further back than
         * they really were. Reporting fewer characters over fewer seconds is the same pace.
         *
         * `force` is for page unload, where the throttle must not swallow the one report that
         * decides where the player comes back.
         */
        reportWarProgress(force = false) {
            if (!this.warAttempt || this.currentMain === 'survival' || !this.isStarted) return;

            if (this.currentIndex === this._warProgressLast && !force) return;

            const now = Date.now();
            if (!force && now - this._warProgressSentAt < WAR_PROGRESS_MIN_INTERVAL_MS) return;

            this._warProgressSentAt = now;
            this._warProgressLast = this.currentIndex;

            const body = JSON.stringify({
                claim: this.warAttempt.claim,
                chars: this.currentIndex,
                // This session's own clock and counters -- never the attempt's totals. The
                // server holds those, and handing the client a running total to echo back
                // would be inviting it to inflate one.
                typedMs: this.startTime ? Date.now() - this.startTime : 0,
                totalKeystrokes: this.totalKeystrokes,
                correctKeystrokes: this.correctKeystrokes,
            });

            // keepalive so the browser finishes the request even as the page goes away -- the
            // whole reason this left the Livewire queue. Errors are swallowed: there is nothing
            // to retry during an unload, and the server only ever raises what it stores.
            fetch(this.warAttempt.report, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
                keepalive: true,
            }).catch(() => {});
        },

        /**
         * Rebuild the word position from the character count the server saved (a reloaded
         * attempt).
         *
         * Consumes whole "word + space" spans until the next word would not fit, so the cursor
         * lands at the START of the first unfinished word. A partial word is never restored --
         * the saved position was recorded on finished words, so a prefix was never part of it,
         * and inventing one would put characters on screen the player never typed.
         *
         * What this restores is a VIEW, not credit. The characters are drawn as already-correct
         * so the player can see their work is still there, but they are deliberately NOT added
         * to totalKeystrokes/correctKeystrokes.
         *
         * That line is the whole bug this method used to carry. Adding them made this session's
         * counters describe the entire attempt while startTime still described only this
         * session, so the characters of an hour could be divided by the seconds of a minute --
         * and, because the restored characters were all marked correct, every mistake made
         * before the reload was erased along the way. Both halves of the attempt's real totals
         * now live on the server (the ledger in ClanWarAttempt), which is the only place that
         * can see more than one session.
         */
        restoreWarProgress() {
            const chars = this.warAttempt?.chars ?? 0;

            if (!this.warAttempt || chars <= 0 || this.currentMain === 'survival') return;

            const { consumed, wordIndex } = resumePosition(
                this.wordBounds,
                this.targetArray.length,
                chars
            );

            if (consumed <= 0) return;

            this.currentWordIndex = wordIndex;
            this.currentIndex = consumed;

            for (let i = 0; i < consumed; i++) {
                this.inputResults[i] = true;
            }

            this._warProgressLast = consumed;
        },

        // Backspacing into the previous word: undo its last commit scoring. The 'dirty'
        // status is kept so the penalty isn't applied twice on re-commit.
        uncommitWord(wordIndex) {
            if (this.currentMain !== 'survival') return;

            const prev = this.committedWordResults[wordIndex];
            if (prev === undefined) return;

            if (prev === 'clean') {
                delete this.committedWordResults[wordIndex];
            }
        },

        // Refill stamina on each correct character (capped at staminaMax), and bank a little
        // burst-shield time (capped) so a fast run earns a soft landing for the pause after it.
        refillStamina() {
            if (this.currentMain !== 'survival' || this.isFinished) return;
            this.stamina = Math.min(this.staminaMax, this.stamina + this.survivalCfg.refill);
            this.shieldMs = Math.min(SHIELD_MAX_MS, this.shieldMs + SHIELD_PER_CHAR_MS);
            this.syncStaminaPct();
        },

        // One passive drain tick; precise Δt from the previous tick, drain rises over time.
        staminaTick() {
            if (this.currentMain !== 'survival' || this.isFinished || !this.startTime) return;

            const now = Date.now();
            // Clamp Δt: setInterval does NOT guarantee 100ms. When the main thread is busy
            // (heavy paint from the reactive text, GC, tab throttling) a tick is delayed, and
            // an unclamped Δt would drain the whole delayed gap in ONE tick -- stamina jumps
            // to 0 and the run ends abruptly, which reads on screen as the timer "freezing"
            // (finish() stops timerInterval). Capping Δt keeps a late tick from draining more
            // than a normal one; the loss of the missed interval's drain is negligible and far
            // better than a false game-over. See docs/review-performance-2026-07-27.md (Temuan 2).
            const dt = this.lastTickTime ? Math.min((now - this.lastTickTime) / 1000, 0.25) : 0;
            this.lastTickTime = now;
            if (dt <= 0) return;

            const elapsed = (now - this.startTime) / 1000;
            const cfg = this.survivalCfg;

            const graceFactor = elapsed < cfg.graceSec ? (elapsed / cfg.graceSec) : 1;

            // D = D_start + D_accel * elapsed
            let drainPerSec = (cfg.dStart + cfg.dAccel * elapsed) * graceFactor;

            // Burst shield: while banked shield time remains, drain is reduced, and the shield
            // is spent by this tick's real elapsed time. The factor is > 0 so the acceleration
            // ramp still wins eventually -- shield delays the game over, never prevents it.
            if (this.shieldMs > 0) {
                drainPerSec *= SHIELD_DRAIN_FACTOR;
                this.shieldMs = Math.max(0, this.shieldMs - dt * 1000);
            }

            this.stamina = Math.max(0, this.stamina - drainPerSec * dt);
            this.syncStaminaPct();

            if (this.stamina <= 0) this.survivalGameOver();
        },

        // Sync the bar percentage for the UI (0–100).
        syncStaminaPct() {
            const nextPct = Math.max(0, Math.min(100, Math.round((this.stamina / this.staminaMax) * 100)));
            if (nextPct !== this.staminaPct) {
                this.staminaPct = nextPct;
            }
        },

        // Stamina empty: stop the tick loop, end the session via finish() like other modes.
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
            if (this._onGhostResize) {
                window.removeEventListener('resize', this._onGhostResize);
                this._onGhostResize = null;
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

        // Look up the DOM position of char-{index}; fall back to the last character if the index runs past the text.
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

        // Build the character-position cache from the laid-out DOM. One pass of offsetLeft/
        // offsetTop reads (a single forced reflow) replaces the up-to-3 reads-per-frame the
        // ghost loop would otherwise do at ~60fps. Rebuilt lazily whenever _charPosCache is
        // null (invalidated on reset & resize). Returns null until char-0 exists.
        buildCharPositionCache() {
            const cache = [];
            let i = 0;
            let el = document.getElementById('char-0');
            while (el) {
                cache[i] = { left: el.offsetLeft, top: el.offsetTop, width: el.offsetWidth };
                i++;
                el = document.getElementById('char-' + i);
            }
            this._charPosCache = cache.length ? cache : null;

            return this._charPosCache;
        },

        // Ghost position lookup from the cache; mirrors getCharPosition's end-fallback (an
        // index past the last character -> right edge of the last one). Falls back to a live
        // DOM read only while the cache isn't ready yet.
        cachedCharPosition(index) {
            const cache = this._charPosCache || this.buildCharPositionCache();
            if (!cache) return this.getCharPosition(index);

            const at = cache[index];
            if (at) return { left: at.left, top: at.top };

            const prev = cache[index - 1];
            if (prev) return { left: prev.left + prev.width, top: prev.top };

            return null;
        },

        // Ghost position from time progress (linear pacing); called per-frame via rAF.
        // ghostCharIndex (integer) decides win/lose at finish(); the visual position is
        // computed from the fractional value so the cursor glides smoothly across each character.
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
                const pos = this.cachedCharPosition(this.targetArray.length);
                if (pos) {
                    this.ghostCursorLeft = pos.left;
                    this.ghostCursorTop = pos.top;
                }
                return;
            }

            // Pixel interpolation between characters; only when both are on the same line (on a wrap, jump directly).
            const fraction = fractionalChars - flooredIndex;
            const currentPos = this.cachedCharPosition(flooredIndex);
            if (!currentPos) return;

            const nextPos = this.cachedCharPosition(flooredIndex + 1);

            if (nextPos && nextPos.top === currentPos.top) {
                this.ghostCursorLeft = currentPos.left + (nextPos.left - currentPos.left) * fraction;
                this.ghostCursorTop = currentPos.top;
            } else {
                this.ghostCursorLeft = currentPos.left;
                this.ghostCursorTop = currentPos.top;
            }
        },

        // Ghost animation loop via rAF (~60fps), separate from the 1-second timerInterval.
        startGhostAnimationLoop() {
            if (this._ghostRafId) return; // already running

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

            // Check for overtyping at the space
            if (this.extraChars[this.currentWordIndex] && this.extraChars[this.currentWordIndex].length > 0 && this
                .currentIndex === this.wordBounds[this.currentWordIndex].space) {
                let lastIdx = this.extraChars[this.currentWordIndex].length - 1;
                activeEl = document.getElementById('extra-' + this.currentWordIndex + '-' + lastIdx);
                isEnd = true; // Put the cursor to the RIGHT of the extra character
            } else {
                activeEl = document.getElementById('char-' + this.currentIndex);
                if (!activeEl) {
                    activeEl = document.getElementById('char-' + (this.currentIndex - 1));
                    isEnd = true; // Put the cursor to the RIGHT of the last character
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
                    // Caret height = 1.2em (the h-[1.2em] class) COMPUTED from the resolved
                    // font-size, NOT measured via offsetHeight. Reason: after a mode-change
                    // remount, the Survival DOM is much heavier (16 stamina cells via x-for) so
                    // the caret layout isn't ready when measured -> offsetHeight 0. The fallback
                    // `caretHeight || height` would then use the CHARACTER height (line-height
                    // 1.6em), not 1.2em, so centering (height - caretHeight)/2 = 0 -> the caret
                    // sticks to the TOP of the line (rides up) and looks different from Standard
                    // (which happened to measure correctly). getComputedStyle font-size always
                    // resolves without waiting for layout, so the value is identical across all
                    // modes & race-proof. (If the caret-height class changes, adjust the 1.2 here.)
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

            // The caret transform is rendered REACTIVELY via :style on the caret element
            // (cursorLeft/cursorTop). Here we just mark that the caret was positioned
            // successfully (char-0 found) so the drawWhenReady() retry in resetProgress stops.
            // Instant vs. gliding is decided by the isTyping gate on the caret :class, no
            // longer an imperative flag.
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

        // ===== SOFT-KEYBOARD (TOUCH) INPUT PATH =====
        //
        // The engine reads physical keys from a global @keydown.window listener and owns no
        // focusable element -- which meant a phone had nothing to tap, no way to raise the
        // on-screen keyboard, and therefore no way to play at all.
        //
        // The fix is a hidden-but-focusable input whose events are SYNTHESISED into
        // handleInput() below. Nothing here maintains state of its own: missedChars,
        // errorEvents, the keystroke counters and trackIdle() all keep their single writer,
        // so the invariants they feed (the anti-cheat ceilings, and
        // `Σ chart points === Σ missedChars`) hold for touch exactly as for a keyboard.
        //
        // Why keydown is NOT the mobile path: Android Gboard reports composing keys as
        // 'Unidentified' (keyCode 229), so the character never arrives. And with the input
        // focused a physical key would fire keydown AND beforeinput for the same character --
        // double-counting every keystroke. Exactly one path may run, which the existing
        // `editing` guard in the Blade view already arranges: it skips the window listener
        // whenever an INPUT holds focus, handing control here.

        /** Raise the keyboard. MUST be called from inside a real gesture handler: iOS only
         *  opens the keyboard for a focus() that a user action triggered. */
        focusTypingInput() {
            const el = this.$refs.typingInput;
            if (!el) return;

            el.value = '';
            // preventScroll: the input is stretched over the text area, so letting the
            // browser scroll it into view would jump the page mid-tap.
            el.focus({ preventScroll: true });
        },

        /** One synthesised keystroke, shaped exactly as handleInput() reads it. */
        feedKey(key, modifiers = {}) {
            this.handleInput({
                key,
                ctrlKey: modifiers.ctrlKey === true,
                altKey: modifiers.altKey === true,
                metaKey: modifiers.metaKey === true,
                preventDefault() {},
            });
        },

        /** Replay inserted text character by character. Swipe typing and autocorrect deliver
         *  a whole word at once; the engine must still see it one character at a time. */
        feedText(text) {
            if (!text) return;

            for (const ch of text) {
                // Newlines/tabs can arrive from a paste; they are not part of any wordlist.
                if (ch === '\n' || ch === '\r' || ch === '\t') continue;
                this.feedKey(ch);
            }
        },

        onTypingInputKeydown(e) {
            // Backspace only -- the one key soft keyboards report reliably. Characters are
            // left to beforeinput/input so they cannot be counted twice.
            if (e.key !== 'Backspace') return;

            this._softDeleteHandled = true;
            this.feedKey('Backspace', e);
        },

        onTypingBeforeInput(e) {
            // Cancelling keeps the field empty, so there is never a value to diff or a stale
            // fragment to replay. Composition events are not always cancelable, though --
            // then the text does land, and onTypingInput() below picks it up instead.
            const cancelled = e.cancelable;
            if (cancelled) e.preventDefault();

            if (e.inputType && e.inputType.startsWith('delete')) {
                // Skip when keydown already reported this Backspace; act only when it didn't.
                if (this._softDeleteHandled) {
                    this._softDeleteHandled = false;

                    return;
                }

                this.feedKey('Backspace', {
                    // Browsers expose the platform-native word deletion semantically via
                    // beforeinput even when keydown is unavailable (notably soft keyboards).
                    altKey: e.inputType === 'deleteWordBackward',
                });

                return;
            }

            this._softDeleteHandled = false;

            if (cancelled) this.feedText(e.data);
        },

        onTypingInput(e) {
            // Only reached when beforeinput could not be cancelled. Drain the field so the
            // same text is not replayed on the next event, then feed what actually landed.
            const value = e.target.value;
            e.target.value = '';

            if (e.inputType && !e.inputType.startsWith('insert')) return;

            this.feedText(value);
        },

        handleInput(e) {
            if (this.isFinished) return;
            if ((e.ctrlKey || e.metaKey) && e.key !== 'Backspace') return;
            if (e.key === ' ') e.preventDefault();
            if (e.key.length > 1 && e.key !== 'Backspace') return;

            // The cursor stops blinking while typing.
            this.isTyping = true;
            clearTimeout(this.typingTimeout);
            this.typingTimeout = setTimeout(() => {
                this.isTyping = false;
            }, 500);

            if (!this.isStarted) {
                this.isStarted = true;
                this.startTime = Date.now();

                // Typing starts -> the caret may now glide smoothly between characters. Before
                // this point caretInstant stays true (set in resetProgress) so the initial
                // placement / reset / mode change is always instant, without a glide animation.
                this.caretInstant = false;

                // Hide the chat overlay while the typing session runs.
                window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: true } }));

                // Survival: ~100ms drain loop so the pressure feels smooth (drain & game over in staminaTick).
                if (this.currentMain === 'survival') {
                    this.lastTickTime = this.startTime;
                    this.staminaInterval = setInterval(() => this.staminaTick(), 100);
                }

                // Ghost: an rAF loop separate from the 1-second timerInterval below.
                if (this.ghostActive) {
                    this.startGhostAnimationLoop();
                }

                this.startClock();
            }

            // After the start block: the first keystroke only stamps the clock (no gap to
            // measure yet), every later one closes the interval since the previous key.
            this.trackIdle();

            let bounds = this.wordBounds[this.currentWordIndex];

            if (e.key === 'Backspace') {
                // Windows/Linux use Ctrl+Backspace; macOS uses Option+Backspace (`altKey`).
                // Keep Meta for existing users who already rely on the previous shortcut.
                if (e.ctrlKey || e.altKey || e.metaKey) {
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

            // From this point on: the user pressed a character/space, not backspace.
            this.totalKeystrokes++;

            // Cursor at the word-separator space position.
            if (this.currentIndex === bounds.space) {
                if (e.key !== ' ') {
                    // Overtyping: the extra characters are buffered, the word is marked dirty.
                    if (!this.extraChars[this.currentWordIndex]) this.extraChars[this.currentWordIndex] = [];
                    if (this.extraChars[this.currentWordIndex].length < 15) {
                        this.extraChars[this.currentWordIndex].push(e.key);
                    }
                    this.markWordDirty();
                    this.calculateStats();
                    this.schedulePositionUpdate();
                    return;
                } else {
                    // Space pressed: advance to the next word & score the just-completed word.
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

            // Space mid-word: the remaining letters are marked skipped (the word is skipped).
            if (e.key === ' ') {
                // Ignore the space if this word hasn't been typed at all (prevent space-spam).
                if (this.currentIndex === bounds.start) {
                    return;
                }

                for (let i = this.currentIndex; i <= bounds.end; i++) {
                    this.inputResults[i] = 'skipped';

                    const expectedChar = this.targetArray[i].toLowerCase();
                    if (expectedChar !== ' ' && expectedChar.length === 1) {
                        this.missedChars[expectedChar] = (this.missedChars[expectedChar] || 0) + 1;
                        // null: the user pressed space ONCE then skipped the rest of the word --
                        // there was never a keystroke for this character.
                        this.recordError(i, null);
                    }
                }
                this.markWordDirty();
                if (bounds.space !== null) {
                    this.inputResults[bounds.space] = 'skipped'; // a skipped space isn't counted correct
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

            // Normal typing.
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

            // A resumed Clan War attempt is ONE run split across page loads, so the numbers on
            // screen have to describe the run, not the fragment. The server already adds these
            // when it scores the submission (ClanWarAttempt's ledger); adding them here too is
            // what stops the live figure and the result screen from disagreeing — a resumed
            // player would otherwise watch a WPM they know is wrong, then see it jump at the
            // end. Zero for every solo session, which leaves the arithmetic below untouched.
            const carriedMs = this.warAttempt?.carriedMs ?? 0;
            const carriedCorrect = this.warAttempt?.carriedCorrect ?? 0;
            const carriedTotal = this.warAttempt?.carriedTotal ?? 0;

            const elapsedMs = (Date.now() - this.startTime) + carriedMs;

            // 1-second floor: keep WPM from exploding at the very start of typing.
            const effectiveMs = (elapsedMs < 1000 && !this.isFinished) ? 1000 : elapsedMs;
            const timeElapsed = effectiveMs / 60000;

            if (timeElapsed <= 0) return;

            const correct = this.correctKeystrokes + carriedCorrect;
            const total = this.totalKeystrokes + carriedTotal;

            // Net WPM from correctKeystrokes — the same source as finish/server, so the live
            // number is identical to the one on the result page.
            this.wpm = Math.round((correct / 5) / timeElapsed) || 0;

            // Raw WPM: ignores errors (total keystrokes / 5).
            this.rawWpm = Math.round((total / 5) / timeElapsed) || 0;

            // Accuracy based on physical keystrokes (Monkeytype-style).
            if (total > 0) {
                this.accuracy = Math.round((correct / total) * 100);
            } else {
                this.accuracy = 0;
            }
        },

        /**
         * Start the one-second loop that drives the countdown, the stats and the WPM history.
         *
         * Split out of the first-keystroke block because a RESUMED war attempt has to start it
         * at MOUNT instead -- the server's clock is already running, so a countdown that waits
         * for a keystroke is a countdown the player can pause by not typing.
         *
         * The two clocks inside are deliberately separate. `countdownStart` drives the war
         * deadline and runs from whenever the clock was armed; `startTime` drives WPM and runs
         * from the first keystroke. Reading both off one stamp is what let a resumed player
         * watch a frozen timer.
         *
         * Idempotent: arming an already-running clock is a no-op, so the mount arming it and a
         * later keystroke arming it again settle on one interval rather than two.
         */
        startClock() {
            if (this.timerInterval) return;

            this.countdownStart = this.countdownStart ?? Date.now();

            this.timerInterval = setInterval(() => {
                // The countdown's own clock. On a fresh attempt it was armed by the first
                // keystroke, so this equals the typing clock; on a resume it was armed at mount.
                const countdownElapsed = Math.floor((Date.now() - this.countdownStart) / 1000);

                // The typing clock, which stays null until the player actually types. A resumed
                // attempt can be counting down with nobody typing yet, and the stats below must
                // report zero for that rather than crediting the wait.
                const typedElapsed = this.startTime
                    ? Math.floor((Date.now() - this.startTime) / 1000)
                    : 0;

                if (this.currentMain === 'time') {
                    // timerStart, not the sub-mode: on a resumed war slot they differ, and
                    // reading the sub-mode here would quietly restore the full test length
                    // one second after resetProgress() shortened it.
                    const remaining = this.timerStart - countdownElapsed;
                    this.timer = remaining > 0 ? remaining : 0;
                    if (this.timer <= 0) this.finish();
                } else {
                    this.timer = typedElapsed;

                    // Survival war slots run inside a wall budget that shrinks with every
                    // abandoned attempt, so stop at it rather than letting the player type
                    // seconds the war will not credit.
                    if (this.currentMain === 'survival' && this.warAttempt?.budget != null
                        && countdownElapsed >= this.warAttempt.budget) {
                        this.finish();
                    }
                }

                this.calculateStats();

                // History samples describe TYPING, so they are gated on the typing clock: a
                // resumed attempt waiting for its first keystroke must not push a run of zeros
                // that the consistency check would then read as inhumanly steady.
                if (this.startTime && typedElapsed > 0 && !this.isFinished) {
                    this.wpmHistory.push(this.wpm);
                    const timeElapsedMins = (Date.now() - this.startTime) / 60000;
                    const raw = Math.round((this.totalKeystrokes / 5) / timeElapsedMins) || 0;
                    this.rawHistory.push(raw);
                }
            }, 1000);
        },

        finish() {
            this.isFinished = true;
            // Typing session done — show the chat overlay again.
            window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } }));
            clearInterval(this.timerInterval);
            if (this.staminaInterval) {
                clearInterval(this.staminaInterval);
                this.staminaInterval = null;
            }

            // Precise duration (ms) since the first keystroke — the same source as the live
            // calculation, so the final WPM is identical to the WPM while typing.
            const durationMs = this.startTime ? (Date.now() - this.startTime) : 0;

            // Trailing gap: in `time` the countdown ends the session by itself, so a player
            // who walked away leaves their longest silence AFTER the final keystroke. Without
            // closing that interval here the defining shape of an AFK run is never measured.
            if (this.lastKeyTime !== null) {
                const trailingIdle = Date.now() - this.lastKeyTime;
                if (trailingIdle > this.maxIdleMs) this.maxIdleMs = trailingIdle;
            }

            const correct = this.correctKeystrokes;
            const total = this.totalKeystrokes;

            // Compute the ghost position exactly at the finish moment (not the last rAF snapshot).
            if (this.ghostActive && !this.ghostFinished) {
                this.updateGhostPosition();
            }
            this.stopGhostAnimationLoop();
            const ghostWpmArg = this.ghostActive ? this.ghostWpm : null;
            const ghostLabelArg = this.ghostActive ? this.ghostLabel : null;
            const ghostCharsArg = this.ghostActive ? this.ghostCharIndex : null;

            // One named object, not twelve positional arguments: adding a field no longer
            // means counting commas here and in every caller. Keys map 1:1 to
            // App\Support\SoloSessionPayload.
            this.$wire.saveResult({
                durationMs: durationMs,
                totalKeystrokes: total,
                correctKeystrokes: correct,
                wpmHistory: this.wpmHistory,
                rawHistory: this.rawHistory,
                missedChars: this.missedChars,
                drainEventCount: this.drainEventCount,
                ghostWpm: ghostWpmArg,
                ghostLabel: ghostLabelArg,
                ghostCharsAtFinish: ghostCharsArg,
                errorEvents: this.errorEvents,
                maxIdleMs: this.maxIdleMs,
                keyIntervals: this.keyIntervals,
                keyStrokeCount: this.keyStrokeCount,
            });
        }
    }
}
