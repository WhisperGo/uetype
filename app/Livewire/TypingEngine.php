<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Services\AchievementService;
use App\Services\AntiCheatService;
use App\Services\AutomaticResultResolver;
use App\Services\ClanWarAttempt;
use App\Services\ClanWarScorer;
use App\Services\GhostResolver;
use App\Services\KeystrokeAnalyzer;
use App\Services\LongitudinalBaseline;
use App\Services\SoloSessionGuard;
use App\Services\SurvivalPlausibility;
use App\Services\TextGeneratorService;
use App\Services\TypingErrorInspector;
use App\Support\ClanWarAttemptState;
use App\Support\ClanWarBroadcast;
use App\Support\SoloSessionPayload;
use App\Support\TypingLanguage;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The core solo typing arena: picks the text, receives the finished session, and
 * persists it. WPM/accuracy are recomputed server-side (AntiCheatService), never
 * trusted from the client, then feed personal bests, XP, and achievements.
 */
#[Layout('layouts.app')]
class TypingEngine extends Component
{
    // Main mode: 'time', 'words', 'survival'
    public $mainMode = 'time';

    // Sub-mode (number/length choice)
    public $subMode = '30';

    // Whitelist of valid sub-modes per main mode; a server-side gate because
    // mainMode/subMode are client-controlled and mode_config is a leaderboard filter key.
    private const ALLOWED_SUBMODES = [
        'time' => ['15', '30', '60', '120'],
        'words' => ['10', '25', '50', '100'],
        'survival' => ['easy', 'medium', 'hard'],
    ];

    /**
     * Result submissions allowed per minute.
     *
     * The limit exists to stop a script from sweeping payloads until one clears the
     * anti-cheat checks -- not to pace honest practice.
     *
     * Raised 10 -> 30 (2026-07-27). The old value came with the note "the shortest test is
     * 15 seconds, so even back-to-back honest play stays well under this". That was simply
     * untrue: `words/10` is the shortest test, and a fast player finishes one in under four
     * seconds. Measured against a realistic practice cycle (~4s typing + tab/enter + reading
     * the next text) that is ~11 runs per minute -- over the limit -- so a player drilling
     * short tests got rejected for their ELEVENTH honest run and was told it was implausible.
     *
     * 30 sits above any human practice rate (a 2s cycle sustained for a full minute) while
     * still bounding a scripted sweep to something a rate-limited attacker cannot brute-force
     * a payload with. As with every other threshold here: re-tune from real logs, not
     * intuition -- and the rejection log now records this reason so that is possible.
     */
    private const MAX_RESULTS_PER_MINUTE = 30;

    /**
     * Player-facing message per rejection reason, keyed by the reason passed to
     * rejectSubmission(). Anything not listed falls back to the generic "implausible" text.
     *
     * Split because one message for every gate actively misinformed people: hitting a rate
     * limit or returning to a lapsed session is not cheating, and telling an honest player
     * their result was implausible sent them hunting for a problem with their typing.
     */
    private const REJECTION_MESSAGES = [
        'rate_limited' => 'typing.result_rate_limited',
        'session_mismatch' => 'typing.result_session_expired',
        'survival_impossible' => 'typing.result_survival_unverified',
    ];

    /**
     * Whether keystroke-timing flags REJECT a run, or are only logged (§7.1).
     *
     * A fail-safe rollout was the right call: rejecting on day one risks locking out players
     * whose cached bundle sends no intervals at all. But "flip it once the logs look safe" is
     * not a criterion, it is an intention — and an anti-cheat layer that never gets switched on
     * is an anti-cheat layer that does not exist. So the bar is written down instead:
     *
     *   FLIP TO true WHEN, over at least 14 days of production traffic, the
     *   'Keystroke timing flagged' warning appears for FEWER THAN 1 IN 500 saved results, AND
     *   no flagged run belongs to a player whose history is otherwise unremarkable (check the
     *   flagged user ids against /review-queue and their own profiles).
     *
     *   IF THE BAR IS NOT MET, the analyzer's thresholds are wrong, not the players — tune
     *   KeystrokeAnalyzer and restart the window rather than shipping it as-is.
     *
     * Review date: 2026-09-01. If nobody has looked by then, the honest move is to say so in
     * the anti-cheat doc rather than let this comment quietly become permanent.
     *
     * The same applies to MAX_CHARS_PER_SECOND and IMPOSSIBLE_CONSISTENCY, whose own comments
     * call their calibration overdue: see docs/features/anti-cheat-wpm.md §7.
     */
    private const KEYSTROKE_TIMING_ENFORCED = false;

    /**
     * Share of the session an idle gap may reach before the run counts as abandoned.
     *
     * Proportional rather than flat because the same pause means different things: 12
     * seconds is most of a 15-second test but an ordinary hesitation in a 120-second one.
     */
    private const AFK_IDLE_FRACTION = 0.25;

    /**
     * Floor for the idle threshold, so short tests keep a usable pause budget. Reading a
     * hard word, sneezing, glancing away -- all normal, none of them AFK.
     */
    private const AFK_MIN_IDLE_SECONDS = 10.0;

    // Locked: the client renders this text but must never set it. Without the lock a
    // tampered payload could swap in a much longer text to justify a huge character count.
    // mainMode/subMode can't be locked (the view @entangles them); a mode swapped after the
    // text was issued is caught instead by SoloSessionGuard::matchesIssuedSession().
    #[Locked]
    public $textToType;

    // "Retry" text (words mode) for this request only: pulled from session in mount(),
    // used by generateText() instead of random assembly. A private property (not public
    // Livewire) so it isn't persisted across requests -- the next restart() still yields
    // random text, not sticky.
    private ?string $retryText = null;

    public string $contentLang = TypingLanguage::DEFAULT;

    /**
     * Identifies THIS component instance -- effectively, this browser tab -- so the session
     * guard can hold one issued-text record per open tab (see SoloSessionGuard).
     *
     * #[Locked] is the whole point. Livewire round-trips public properties through the
     * client, so without it a forged payload could name whichever issued session it liked
     * and submit against a text it never received. Locked means the server assigns it once
     * in mount() and refuses any client attempt to change it.
     *
     * Not persisted anywhere else: a new tab mounts a new component and gets a new key, which
     * is exactly the granularity wanted.
     */
    #[Locked]
    public string $tabKey = '';

    // Active-ghost flag; server is the source of truth. Ghost is valid only for
    // time/words -- switching to survival forces false (an invariant, not just a client event).
    public bool $ghostActive = false;

    // Clan War: id of the ClanWarModeClaim being worked (from ?war_claim=). If valid, the
    // mode is locked to the claim's mode/config and the typing result becomes a war attempt
    // automatically. Null = solo session.
    #[Url(as: 'war_claim')]
    public ?int $warClaimId = null;

    /**
     * War attempt detail for the view banner and the Alpine engine: mode/config, whether this
     * mount is a resume, the saved progress, and the clock the SERVER says is left.
     *
     * #[Locked] is load-bearing, not decoration. `remaining` drives the countdown, so a client
     * able to raise it could type 45 real seconds while the server stores a 30-second duration
     * -- a free 1.5x on WPM. It also guards `isAfkSession()`, which skips the AFK check whenever
     * a war lock is present.
     */
    #[Locked]
    public ?array $warLock = null;

    /**
     * This request's attempt state (text, anchor, remaining clock), memoised.
     *
     * A private property, so it is rebuilt per request rather than round-tripped through the
     * client -- the anchor is the one fact a tampered payload must never be able to restate.
     * That also means it is NOT carried from mount() into the submit request, which is why
     * warAttempt() re-opens rather than reads.
     */
    private ?ClanWarAttemptState $warAttempt = null;

    // Ghost deep-link from the leaderboard: ?ghost=<user_id>&mode=<time|words>&config=<sub>.
    // The opponent's WPM is re-derived from the DB (not from the client), same as GhostPicker.
    #[Url(as: 'ghost')]
    public ?int $ghostUserId = null;

    #[Url(as: 'mode')]
    public ?string $ghostMode = null;

    #[Url(as: 'config')]
    public ?string $ghostConfig = null;

    public function mount()
    {
        // One key per mounted component = one per open tab, assigned server-side so the
        // guard can keep this tab's issued text separate from any other tab's.
        $this->tabKey = (string) Str::uuid();

        // Content language is restored FIRST: war only locks mode/config, not language, and
        // opening a war attempt freezes a text that has to be assembled in the right language.
        if (session()->has('typing_preferences')) {
            $this->contentLang = TypingLanguage::resolve(session('typing_preferences')['contentLang'] ?? null);
        }

        // War-lock is checked next: valid -> mode is forced to the claim; invalid ->
        // reset to null and behave as a normal solo session (fail-safe).
        $claim = $this->resolveWarClaim();

        if ($claim) {
            [$this->mainMode, $this->subMode] = $this->normalizeMode($claim->mode, $claim->mode_config);

            // A page load is what ENDS a session, so it is where the session that was running
            // gets sealed into the permanent ledger. Only here: open() also runs during
            // saveResult(), and the submission there already reports the live session in full.
            // Sealing at that moment would count the same keystrokes twice.
            //
            // Sealed BEFORE the attempt is opened, so the state handed to the client already
            // carries the folded totals rather than a snapshot taken a line too early.
            app(ClanWarAttempt::class)->sealLiveSession($claim);

            // Opening the page IS starting the attempt. Idempotent: a refresh, the Back button
            // and a second tab all land on the anchor written the first time, so none of them
            // buys a fresh clock or a fresh text. See ClanWarAttempt.
            $attempt = $this->warAttempt($claim);

            // An attempt whose clock ran out with NOTHING to bank is over, and rendering a
            // typing page for it is worse than useless -- it is a trap. The client used to be
            // asked to finish() such a session, which submitted zero keystrokes over zero
            // seconds, which anti-cheat correctly refused as `no_input`, which redirected
            // straight back into the same expired claim, which asked the client to finish()
            // again. Survival hit this every time, because survival never resumes and so
            // never has anything to bank. Send the player somewhere real instead.
            //
            // Not an early `return`: the rest of mount() still has to leave the component in a
            // renderable state (textToType is spread into Alpine unconditionally), and Livewire
            // honours the queued redirect either way.
            if ($attempt->isExpired()) {
                // An expired attempt is settled HERE, by the server, from the ledger -- never by
                // asking the client to finish() a session it never ran.
                //
                // That used to be an `x-init` auto-finish in the view, and it submitted
                // `durationMs: 0` over zero keystrokes, because the restored characters are
                // deliberately not credited to the client's counters (they live in the ledger).
                // Anti-cheat then refused it as `no_input` and bounced the player back into the
                // same expired claim, which ran the same auto-finish again. The client has no
                // information to contribute at this point; everything the run is worth is
                // already on this side of the wire.
                $this->settleExpiredAttempt($claim, $attempt);

                return;
            }

            // The one countdown grace this attempt gets, spent on the mount that renders it.
            // Charged here rather than in open() because open() also runs during saveResult(),
            // and burning the allowance there would bill a player for the load they are leaving.
            app(ClanWarAttempt::class)->consumeCountdownGrace($claim);

            // The claim id and the endpoint are added here rather than inside the state object:
            // one is routing and the other is identity, and ClanWarAttemptState is about what
            // the attempt IS. Handing the client the URL beats hardcoding it in JS -- the route
            // stays named in exactly one place.
            $this->warLock = $attempt->toLockPayload($this->mainMode, $this->subMode) + [
                'claim' => $claim->id,
                'report' => route('clan-war.attempt-progress'),
            ];
        } else {
            $this->warClaimId = null;

            // Restore previous preferences if any (normal solo session only). Through
            // normalizeMode so wild values / survival-for-guest are rejected too.
            if (session()->has('typing_preferences')) {
                $prefs = session('typing_preferences');
                [$this->mainMode, $this->subMode] = $this->normalizeMode($prefs['mode'] ?? 'time', $prefs['subMode'] ?? '30');
            }
        }

        // Ghost is processed after war-lock so war still wins; it applies only to solo
        // sessions and time/words. The deep-link (?ghost=) is first digested into a stored
        // selection, then applyGhostRestore restores that selection (or the one already in
        // session from a previous test -- this is what makes ghost persist across tests).
        if (! $this->warLock) {
            $this->ingestGhostDeepLink();
            $this->applyGhostRestore();
        }

        // Retry (words mode): a one-shot pull. Solo path only -- war-lock wins, same as
        // the ghost deep-link. Mode/config come along so "redo that one" is genuinely the
        // same, not the last preference.
        if (! $this->warLock) {
            $this->applyRetryFromSession();
        }

        $this->generateText();
    }

    /**
     * Install the retry text from session (one-shot) if valid: words mode + text present.
     * generateText() then uses $retryText instead of assembling new random text.
     */
    private function applyRetryFromSession(): void
    {
        $retry = session()->pull('typing_retry');

        if (! is_array($retry) || ($retry['mode'] ?? null) !== 'words' || empty($retry['text'])) {
            return;
        }

        $this->mainMode = 'words';
        $this->subMode = (string) ($retry['subMode'] ?? $this->subMode);
        $this->retryText = (string) $retry['text'];
    }

    /**
     * Digest the deep-link ?ghost=&mode=&config= (from a leaderboard row) into a stored
     * ghost SELECTION in session -- so it becomes sticky like a picker selection. Locks
     * mode/config to the ghost's. time/words only; wild params / opponent without a
     * record are ignored (fail-safe). Dispatch is left to applyGhostRestore().
     */
    private function ingestGhostDeepLink(): void
    {
        if (! $this->ghostUserId || ! Auth::check()
            || ! in_array($this->ghostMode, ['time', 'words'], true)) {
            $this->clearGhostDeepLinkParams();

            return;
        }

        [$main, $sub] = $this->normalizeMode($this->ghostMode, $this->ghostConfig);

        // Validate the opponent actually has a record in that mode/config before storing.
        $ghost = app(GhostResolver::class)->resolve('leaderboard', $this->ghostUserId, $main, $sub, Auth::id());

        if ($ghost === null) {
            $this->clearGhostDeepLinkParams();

            return;
        }

        $this->mainMode = $main;
        $this->subMode = $sub;

        session()->put('ghost_selection', ['type' => 'leaderboard', 'ref_id' => $this->ghostUserId]);

        $this->clearGhostDeepLinkParams();
    }

    /**
     * Restore the ghost from the stored session selection for the CURRENT mode/config.
     * This is what makes ghost persist across Next Test / refresh: the selection stays in
     * session, and on each mount/mode-switch to time-words we RE-derive WPM from the DB
     * (not a frozen number) and display it.
     *
     * Non-eligible mode (survival) -> ghost is merely HIDDEN (ghostActive false), the
     * session selection is NOT removed -> it reappears automatically on return to time/words.
     */
    private function applyGhostRestore(): void
    {
        // Ghost is locked for guests: the leaderboard is closed to guests, so ghost must
        // not leak through this door -- even if ghost_selection were forged.
        if (! Auth::check()) {
            $this->ghostActive = false;

            return;
        }

        if (! $this->isGhostEligibleMode()) {
            $this->ghostActive = false;

            return;
        }

        $selection = session('ghost_selection');
        if (! is_array($selection) || empty($selection['type'])) {
            return;
        }

        $ghost = app(GhostResolver::class)->resolve(
            $selection['type'],
            $selection['ref_id'] ?? null,
            $this->mainMode,
            $this->subMode,
            Auth::id(),
        );

        // Opponent no longer has a record in this config -> hide (selection kept).
        if ($ghost === null) {
            $this->ghostActive = false;

            return;
        }

        $this->ghostActive = true;
        $this->dispatch('ghost-selected', type: $ghost['type'], wpm: $ghost['wpm'], label: $ghost['label']);
    }

    /**
     * Explicit clear (arena "Clear" button): drop the session selection so the ghost
     * does NOT reappear on the next test. Different from the survival suspend, which
     * only hides without deleting.
     */
    public function clearGhost(): void
    {
        session()->forget('ghost_selection');
        $this->ghostActive = false;
        $this->dispatch('ghost-cleared');
    }

    /** Ghost Mode is valid only for time & words (survival excluded). */
    private function isGhostEligibleMode(): bool
    {
        return in_array($this->mainMode, ['time', 'words'], true);
    }

    /** Sync the server ghost state when the client selects/deselects a ghost. */
    #[On('ghost-selected')]
    public function onGhostSelected(): void
    {
        $this->ghostActive = $this->isGhostEligibleMode();
    }

    // Hide the ghost cursor (suspend on survival OR explicit clear). DELIBERATELY does not
    // touch session: the selection is only removed via clearGhost()/clearOpponent().
    #[On('ghost-cleared')]
    public function onGhostCleared(): void
    {
        $this->ghostActive = false;
    }

    /** Clear the ghost params from URL/state after processing. */
    private function clearGhostDeepLinkParams(): void
    {
        $this->ghostUserId = null;
        $this->ghostMode = null;
        $this->ghostConfig = null;
    }

    /**
     * Fetch & validate the ClanWarModeClaim from $warClaimId: must belong to the user's
     * active clan, war Ongoing, not yet submitted. Null if invalid (throws nothing, just ignored).
     */
    private function resolveWarClaim(): ?ClanWarModeClaim
    {
        if (! $this->warClaimId || ! Auth::check()) {
            return null;
        }

        $claim = ClanWarModeClaim::with('war')->find($this->warClaimId);

        if (! $claim || $claim->isSubmitted()) {
            return null;
        }

        if (! $claim->war || $claim->war->status !== ClanWarStatus::Ongoing) {
            return null;
        }

        // The claim must belong to this user's active clan.
        $myClan = Auth::user()->clan;
        if (! $myClan || $myClan->id !== $claim->clan_id) {
            return null;
        }

        return $claim;
    }

    /*
         * Progress reporting used to live here as a Livewire method, and that was the bug.
         *
         * A Livewire call is an XHR the browser CANCELS on unload, so the one ping that mattered
         * most -- the one fired as the player pressed refresh -- never arrived. What the server had
         * was the last routine ping, up to five seconds stale, which is why players landed several
         * words behind where they stopped. It also had to be throttled hard, because textToType is
         * a public property that rides along on every round trip.
         *
         * It now lives at App\Http\Controllers\ClanWarProgressController, reached by a keepalive
         * fetch, exactly like the multiplayer leave-beacon that exists for the same reason. Off the
         * Livewire queue the payload is a handful of integers, so it can report every finished word
         * instead of every fifth second.
         */

    /**
     * Close out an attempt whose clock ran out while nobody was on the page.
     *
     * Two outcomes, decided by whether the ledger holds anything:
     *
     *  - NOTHING banked: the slot produced no typing at all, so there is no result to write.
     *    The player goes back to the war with an explanation. Rendering a typing page for a
     *    spent attempt is worse than useless -- it is a trap, and it was the shape of the
     *    original infinite loop.
     *  - WORK banked: the ledger already holds every keystroke and every millisecond of it, so
     *    the run is submitted through the ordinary saveResult() path with a payload the SERVER
     *    wrote. Reusing that path rather than a private shortcut is the point: anti-cheat, the
     *    character ceiling, XP, personal bests and the war scorer all keep applying, and there
     *    is no second way into TypingResult that could drift from the first.
     *
     * The live slot is sealed by mount() before this runs, so `carried` here is the whole
     * attempt -- including the session that was interrupted by the reload.
     */
    private function settleExpiredAttempt(ClanWarModeClaim $claim, ClanWarAttemptState $attempt): void
    {
        $carried = app(ClanWarAttempt::class)->carried($claim);

        if ($carried['total'] <= 0) {
            session()->flash('clan_war_claim_error', __('clan.error.attempt_expired'));

            $this->redirect(route('clan-war.index'));

            return;
        }

        // The submission below runs the full saveResult() pipeline, and that pipeline checks
        // the run against the text the server ISSUED for this tab. mount() returns before
        // generateText() on this path, so the record has to be laid down here or the settlement
        // would be refused as `session_mismatch` -- rejected for the absence of a session it
        // was never going to have. The text is the attempt's own frozen one, so what is issued
        // is exactly what the ledger's characters were typed against.
        $this->textToType = $attempt->text;

        app(SoloSessionGuard::class)->start(
            $this->mainMode,
            (string) $this->subMode,
            $attempt->text,
            $this->tabKey
        );

        // Zeroes for this SESSION's counters, because this session typed nothing: every
        // keystroke being banked belongs to the ledger, and saveResult() adds that itself
        // (see the `$carried` block there). Reporting the ledger here as well would count the
        // same work twice.
        //
        // `wpmHistory` stays empty on purpose. It is a per-second sample of a live run, and no
        // run is live here; the consistency check reads MIN_CONSISTENCY_SAMPLES and correctly
        // declines to judge a sample this small rather than reading a fabricated one.
        $this->saveResult([
            'durationMs' => 0,
            'totalKeystrokes' => 0,
            'correctKeystrokes' => 0,
            'wpmHistory' => [],
            'rawHistory' => [],
            'missedChars' => [],
            'drainEventCount' => 0,
            'errorEvents' => [],

            // Explicitly not AFK. The gap this measures is silence DURING a run, and the
            // silence here is the reload itself -- the player did not walk away from their
            // typing, they were cut off from it. Leaving it at 0 keeps isAfkSession() from
            // discarding work that genuinely happened.
            'maxIdleMs' => 0,
            'keyIntervals' => [],
            'keyStrokeCount' => 0,
        ]);
    }

    /**
     * The attempt state for a claim, opened once per request and reused.
     *
     * Re-opens rather than reads a stored value because $warAttempt is private and therefore
     * gone by the time the finished session is submitted. That is safe precisely because
     * open() is idempotent: the anchor and the frozen text are written on first contact and
     * never moved, so calling it again during saveResult() reads the same attempt the player
     * has been sitting in, with the clock that has really run.
     */
    private function warAttempt(ClanWarModeClaim $claim): ClanWarAttemptState
    {
        return $this->warAttempt ??= app(ClanWarAttempt::class)->open($claim, $this->contentLang);
    }

    /**
     * Link the typing result to the war claim (re-validated, never trusting the client's
     * $warClaimId). The conditional update `whereNull('typing_result_id')` prevents two
     * parallel submits from filling the same claim. Called inside saveResult()'s DB::transaction.
     *
     * Returns what the war actually received, or null when this attempt filled NO claim. The
     * authority is the conditional update, not the scorer: a teammate's parallel submit can
     * take the slot a millisecond earlier, and the war then got nothing from this run. The
     * result screen reads this, so returning points the war never received would put a number
     * on screen that no scoreboard will ever agree with.
     *
     * @return array{war_id: int, ceiling: int, basis: string, basis_value: float, basis_scale: int,
     *               performance_ratio: float, accuracy_multiplier: float, points: float}|null
     */
    private function attachToWarClaim(TypingResult $typingResult): ?array
    {
        $claim = $this->resolveWarClaim();

        if (! $claim) {
            return null;
        }

        // Survival credit is capped by whatever wall budget the attempt had left, so an
        // abandoned run cannot be retried into a better one. Never applied to the stored
        // result -- see ClanWarScorer::breakdown for why that direction is unsafe.
        $breakdown = ClanWarScorer::breakdown(
            $claim->mode,
            $claim->mode_config,
            $typingResult,
            app(ClanWarAttempt::class)->scoredDuration($claim, (float) $typingResult->duration_seconds),
        );

        if ($breakdown === null) {
            return null;
        }

        // Conditional update: only fill if not yet submitted (idempotent, race-safe).
        // A probationary result reserves the one-shot slot but contributes zero until the
        // automatic resolver clears it. Counting its full score here made Clan War trust a
        // number the public leaderboard explicitly refused to trust.
        $verificationPending = ! $typingResult->isTrustworthy();
        $filled = ClanWarModeClaim::where('id', $claim->id)
            ->whereNull('typing_result_id')
            ->update([
                'typing_result_id' => $typingResult->id,
                'user_id' => Auth::id(),
                'points' => $verificationPending ? 0 : $breakdown['points'],
            ]);

        return $filled === 1
            ? $breakdown + [
                'war_id' => $claim->clan_war_id,
                'verification_pending' => $verificationPending,
            ]
            : null;
    }

    // Validate mode+sub-mode against the whitelist, falling back to a safe default if wild.
    // One source of truth used by setMode & saveResult so client values never reach the DB.
    private function normalizeMode($main, $sub): array
    {
        if (! array_key_exists($main, self::ALLOWED_SUBMODES)) {
            return ['time', '30'];
        }

        // Survival is locked for guests (login required): normalize to the Standard default.
        // A single choke point -> setMode & preference restore are both closed off.
        if ($main === 'survival' && ! Auth::check()) {
            return ['time', '30'];
        }

        $allowed = self::ALLOWED_SUBMODES[$main];

        $sub = (string) $sub;
        if (! in_array($sub, $allowed, true)) {
            $sub = $allowed[0]; // first safe default
        }

        return [$main, $sub];
    }

    // Change mode and fetch new text.
    public function setMode($main, $sub)
    {
        // War-lock: mode can't be changed while working a war attempt (server-side gate).
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            return;
        }

        [$main, $sub] = $this->normalizeMode($main, $sub);

        $this->mainMode = $main;
        $this->subMode = $sub ?? 'medium';

        // Save the user's preferences to session so they don't reset.
        session()->put('typing_preferences', [
            'mode' => $main,
            'subMode' => $sub,
            'contentLang' => $this->contentLang,
        ]);
        session()->save();

        $this->generateText();

        if (! $this->isGhostEligibleMode()) {
            // Survival: hide the ghost cursor (suspend). The session selection is
            // DELIBERATELY not removed -> it restores automatically on return to time/words.
            $this->ghostActive = false;
            $this->dispatch('ghost-cleared');
        } else {
            // time/words: restore the stored ghost, re-derived for the new mode/config.
            $this->applyGhostRestore();
        }

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    // Change the typed content language, separate from the UI language.
    public function setContentLang($lang)
    {
        // War-lock: text can't be rerolled during a war attempt. restart() already closes
        // the "refresh until you get short words" path, but generateText() is also called
        // here -- without this gate a player could just flip languages back and forth to
        // re-randomize the text, the same loophole through another door. One claim, one
        // text, one chance.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            return;
        }

        $this->contentLang = TypingLanguage::resolve($lang);

        session()->put('typing_preferences', [
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            'contentLang' => $this->contentLang,
        ]);
        session()->save();

        $this->generateText();

        // New text assembled -> the ghost cursor position resets too; restore the stored
        // ghost so it stays visible (self-guard: no-op on survival / no selection).
        $this->applyGhostRestore();

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    /** Reroll the text for the current mode (blocked under war-lock). */
    public function restart()
    {
        // War-lock: text can't be rerolled during a war attempt (closes the "refresh until
        // you get short words" loophole); server-side gate, one claim one chance.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            return;
        }

        $this->generateText();

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );

        // The new text reaches the client through the 'mode-changed' event above and is applied
        // in-place by the Alpine engine (resetForNewText -> x-for over renderWords). Restart
        // changes nothing that is server-rendered, so skip the render entirely: no ~600-span
        // morph over the wire. Together with the client-rendered spans and the stable wire:key,
        // this is what makes restart fast. See docs/review-performance-2026-07-27.md (Tier 2).
        $this->skipRender();
    }

    /**
     * Produce the text for the current session (retry text, fixed war text, or random),
     * then record it server-side so the submitted result can be validated against what
     * was actually issued.
     */
    public function generateText()
    {
        $this->assembleText();

        // Every path above lands here: the guard always sees the text the player really
        // got, along with the moment it was handed over (see SoloSessionGuard).
        app(SoloSessionGuard::class)->start(
            $this->mainMode,
            (string) $this->subMode,
            (string) $this->textToType,
            $this->tabKey
        );
    }

    /** Pick the text itself; generateText() owns the session bookkeeping around it. */
    private function assembleText(): void
    {
        // Retry (words mode): use the identical previous-session text, not random assembly.
        // Only set in mount() for the solo path (war-lock wins), and only once --
        // restart()/setMode() call generateText() with $retryText already null again.
        if ($this->retryText !== null) {
            $this->textToType = $this->retryText;
            $this->retryText = null;

            return;
        }

        // Clan War: the text was frozen onto the claim when the attempt was opened, so every
        // re-entry serves the identical paper. This covers all THREE war modes. Words alone
        // used to be fixed (clan_war_fixed_texts, for cross-clan fairness); time and survival
        // fell through to random assembly, so every mount re-rolled them -- the very reroll
        // restart() and setContentLang() are blocked from performing.
        if ($this->warAttempt !== null) {
            $this->textToType = $this->warAttempt->text;

            return;
        }

        // Time/words/survival: text assembled randomly from the wordlist per content language.
        $this->textToType = app(TextGeneratorService::class)
            ->forSoloMode($this->mainMode, (string) $this->subMode, $this->contentLang);
    }

    /**
     * Receive a finished solo session from the client, recompute its metrics server-side
     * (anti-cheat), persist the result, update personal bests, XP and achievements, then
     * redirect to the result page. Client-supplied WPM/accuracy is never trusted.
     */
    public function saveResult(array $payload)
    {
        // One object instead of twelve positional arguments: the client reports a single
        // event, and the array->types normalisation now lives in the payload itself.
        // Client WPM/accuracy is NOT among them -- the server always recomputes it.
        $session = SoloSessionPayload::fromArray($payload);

        // Mode gate: normalize against the whitelist before it's used for score/mode_config,
        // so a wild difficulty/sub-mode can't reach the DB and pollute leaderboard filters.
        [$this->mainMode, $this->subMode] = $this->normalizeMode($this->mainMode, $this->subMode);

        // Kept as locals because the pipeline below narrows them further (the character cap
        // and the correct<=total clamp); the payload itself stays immutable.
        $totalKeystrokes = $session->totalKeystrokes;
        $correctKeystrokes = $session->correctKeystrokes;

        // Rate limit before any work: results are submitted once every 15+ seconds by a
        // real player, so a burst is either a bug or someone scripting attempts to find a
        // payload that slips through. Keyed per user (guests share the IP bucket).
        $rateKey = 'save-result:'.(Auth::id() ?? request()->ip());

        if (RateLimiter::tooManyAttempts($rateKey, self::MAX_RESULTS_PER_MINUTE)) {
            return $this->rejectSubmission('rate_limited');
        }

        RateLimiter::hit($rateKey, 60);

        // Recomputing from client-supplied counts is not the same as verifying them: a
        // forged payload (1495 correct chars "in" 60s = 299 WPM) recomputes to exactly the
        // fake number it claims. The guard holds what the SERVER issued for this session,
        // in the session store rather than a Livewire property -- the client can set those.
        $guard = app(SoloSessionGuard::class);

        // A submission whose mode no longer matches the issued text (e.g. take a 15s test,
        // report it as 120s) has no honest reading; there is also nothing to submit when no
        // text was ever issued. Both are refused outright.
        if (! $guard->matchesIssuedSession($this->mainMode, (string) $this->subMode, $this->tabKey)) {
            // Read BEFORE clear(): "no session at all" (expired) and "a session for a different
            // mode" are different failures, and only the latter is suspicious. After clear()
            // both look identical, which is what made this gate unreadable.
            $issued = $guard->current($this->tabKey);

            $guard->clear($this->tabKey);

            return $this->rejectSubmission('session_mismatch', [
                'had_session' => $issued !== null,
                'issued_mode' => $issued['mode'] ?? null,
                'issued_sub_mode' => $issued['sub_mode'] ?? null,
            ]);
        }

        $claimedDuration = max(0.0, $session->durationMs / 1000);

        // A duration longer than the session has been open is time that never passed. Only
        // the variable-length modes need this: `time` takes its duration from the sub-mode
        // regardless, so the claim there is already ignored.
        //
        // It matters most for Clan War survival, where points scale with duration_seconds --
        // "I survived 9999 seconds" would otherwise buy the full 150-point ceiling outright.
        // Elsewhere a long duration only lowers WPM, which is why the claim is not policed
        // in the other direction.
        if ($this->mainMode !== 'time' && $guard->claimsMoreTimeThanElapsed($claimedDuration, $this->tabKey)) {
            $elapsed = $guard->elapsedSeconds($this->tabKey);

            $guard->clear($this->tabKey);

            return $this->rejectSubmission('duration_over_elapsed', [
                'claimed_seconds' => round($claimedDuration, 2),
                'elapsed_seconds' => $elapsed === null ? null : round($elapsed, 2),
            ]);
        }

        // A Clan War attempt is measured against the CLAIM's clock, not this tab's. Both gates
        // below need it, so it is resolved once here; null for every solo session.
        $warClaim = $this->resolveWarClaim();
        $warAttempt = $warClaim ? $this->warAttempt($warClaim) : null;
        $warClock = $warClaim ? app(ClanWarAttempt::class) : null;

        // Duration comes from the SERVER, not the payload. In `time` mode the sub-mode
        // fixes it outright; the variable-length modes keep their (now bounded) claim.
        //
        // War overrides it: an attempt that spanned a refresh reports only the session that is
        // submitting, so the sessions it abandoned have to be added back or the run is scored
        // over a fraction of the time it really took. See ClanWarAttempt::resolveDuration.
        $duration = $warClock !== null
            ? $warClock->resolveDuration($warClaim, $claimedDuration)
            : $guard->resolveDuration($claimedDuration, $this->mainMode, (string) $this->subMode);

        // ...and the numerator has to span the same attempt as the denominator. This is the
        // pair that has to move together, and the whole bug was that it did not: the client
        // used to restore the previous sessions' characters into its own counters while its
        // clock started fresh, so the characters of a whole attempt were divided by the
        // seconds of its last minute. Now the client reports only what IT did, and both halves
        // of the fraction are completed here, from a ledger the client cannot write.
        //
        // Correct is carried alongside total on purpose. Restoring the characters as if they
        // had all been typed correctly was the quieter half of the same bug: every mistake made
        // before a refresh vanished, and ClanWarScorer's accuracy multiplier (0.5-1.0x) paid
        // for it. Errors are part of the work too.
        $carried = $warClock?->carried($warClaim) ?? ['ms' => 0, 'correct' => 0, 'total' => 0];

        $totalKeystrokes += $carried['total'];
        $correctKeystrokes += $carried['correct'];

        // Character counts are bounded by what the issued text could physically produce in
        // that time, so an inflated count can no longer buy WPM, XP or a leaderboard slot.
        //
        // For a war attempt the window is the ATTEMPT's, not this mount's: the guard measures
        // elapsed per tab, and a refresh mints a new tab key, so a resumed player carrying
        // hundreds of genuinely typed characters would be judged against a clock reading zero.
        // Anchored elapsed only ever grows, so this is stricter over the attempt's life than
        // the per-mount clock it replaces -- it just stops punishing the honest resumer.
        $maxChars = $guard->maxPlausibleChars(
            $this->mainMode,
            $duration,
            $this->tabKey,
            $warClock?->elapsedSeconds($warClaim),
            $warAttempt?->wallBudgetSeconds,
        );

        if ($maxChars !== null && $totalKeystrokes > $maxChars) {
            // A payload this far past the physical ceiling is fabricated, not merely noisy.
            // Silently clamping it would still hand the sender a valid result; refuse it
            // instead, the same way an impossible WPM is refused below.
            $elapsed = $guard->elapsedSeconds($this->tabKey);

            $guard->clear($this->tabKey);

            // The three numbers that decide this gate, so a false positive here can be
            // diagnosed from the log instead of by re-deriving the ceiling by hand.
            return $this->rejectSubmission('char_ceiling_exceeded', [
                'total_keystrokes' => $totalKeystrokes,
                'max_chars' => $maxChars,
                'duration_seconds' => round($duration, 2),
                'elapsed_seconds' => $elapsed === null ? null : round($elapsed, 2),
            ]);
        }

        // Correct can never exceed total; clamp so the pair stays coherent.
        $correctKeystrokes = min($correctKeystrokes, $totalKeystrokes);
        $incorrectKeystrokes = max(0, $totalKeystrokes - $correctKeystrokes);

        // One issued text = one submission. Without this, the same finished session could
        // be replayed to farm XP and records. Scoped to THIS tab: another tab's open test is
        // a separate session and must survive this one being submitted.
        $guard->clear($this->tabKey);

        // Survival: the leaderboard metric is duration_seconds, not score. The score column
        // becomes a side stat (correct chars while surviving); other modes don't use it.
        // Read AFTER the cap so a survival score can't carry an inflated count either.
        $score = $this->mainMode === 'survival' ? $correctKeystrokes : null;

        // Server recomputes WPM/accuracy from chars & duration (not trusting the client);
        // implausible sessions are rejected, not saved.
        $antiCheat = app(AntiCheatService::class);

        $check = $antiCheat->check(
            $correctKeystrokes,
            $totalKeystrokes,
            $duration,
        );

        // Final numbers always use the server recomputation (source of truth).
        $finalNetWpm = $check['net_wpm'];
        $finalRawWpm = $check['raw_wpm'];
        $finalAccuracy = $check['accuracy'];

        // Consistency serves two masters, and they need different standards of proof.
        //
        // For DISPLAY, any run with 2+ samples gets a number -- it is a stat, not a verdict.
        // For REJECTING, the sample must be large enough that steadiness is actually what is
        // being measured; on a 4-second `words/10` run it is not (see MIN_CONSISTENCY_SAMPLES),
        // and using the display figure there is what rejected honest ~174 WPM players as bots.
        //
        // Low-WPM runs stay exempt inside isImpossiblyConsistent(): a slow, careful beginner
        // is legitimately steady.
        $consistency = $this->computeConsistency($session->wpmHistory);

        if ($antiCheat->isImpossiblyConsistent(
            $this->consistencyForAntiCheat($session->wpmHistory),
            $finalNetWpm
        )) {
            $check['reasons'][] = 'consistency_impossible';
        }

        // Keystroke-timing analysis (§7.1): the one check that looks at the SHAPE of the
        // typing, not just its magnitude. Fail-safe rollout -- while KEYSTROKE_TIMING_ENFORCED
        // is false, a flagged run is only LOGGED (so we can confirm no honest player trips it
        // before it can reject anything); an empty sample (old bundle / short run) never
        // flags. Flip the flag to true once the logs show it's safe.
        $timing = app(KeystrokeAnalyzer::class)->analyze($session->keyIntervals, $session->keyStrokeCount);

        if ($timing['has_data'] && ! empty($timing['reasons'])) {
            Log::warning('Keystroke timing flagged', [
                'user_id' => Auth::id(),
                'mode' => $this->mainMode,
                'net_wpm' => $finalNetWpm,
                'reasons' => $timing['reasons'],
                'enforced' => self::KEYSTROKE_TIMING_ENFORCED,
            ]);

            if (self::KEYSTROKE_TIMING_ENFORCED) {
                $check['reasons'] = array_merge($check['reasons'], $timing['reasons']);
            }
        }

        // Reject only what genuinely deserves it (cheating / empty session / stalling in
        // survival). The gate used to be `! $check['valid']`, which also threw away real
        // SLOW-TYPER results -- low throughput is slow, not cheating, and in time/words the
        // duration can't be pumped for any advantage.
        if ($antiCheat->rejectsSoloResult($check['reasons'], $this->mainMode)) {
            // The recomputed numbers alongside the verdict: this is the gate that rejected a
            // genuine ~174 WPM run, and without net_wpm/consistency/sample size in the log
            // there was no way to tell an honest elite player from a bot after the fact.
            return $this->rejectSubmission('anti_cheat', [
                'reasons' => $check['reasons'],
                'net_wpm' => $finalNetWpm,
                'accuracy' => $finalAccuracy,
                'consistency' => $consistency,
                'wpm_samples' => count($session->wpmHistory),
                'duration_seconds' => round($duration, 2),
            ]);
        }

        // Survival's floor is a conservative physical invariant, not a longitudinal guess.
        // Leaving it pending would create a status no future run could honestly resolve: typing
        // the impossible claim again does not make it possible. Refuse it automatically and tell
        // the player through the normal rejection banner instead of creating permanent probation.
        if ($this->mainMode === 'survival') {
            $survivalReason = app(SurvivalPlausibility::class)->reviewReasonFor(
                (string) $this->subMode,
                $duration,
                $correctKeystrokes,
            );

            if ($survivalReason !== null) {
                return $this->rejectSubmission('survival_impossible', [
                    'duration_seconds' => round($duration, 2),
                    'correct_chars' => $correctKeystrokes,
                    'difficulty' => (string) $this->subMode,
                ]);
            }
        }

        // AFK: a session the player walked away from is not an attempt at typing. In `time`
        // the clock runs to zero and submits on its own, so "type two letters then leave"
        // lands in history as a 1-WPM row and drags the player's average down for good.
        //
        // The signal is the GAP, not the average speed. A genuinely slow beginner spreads
        // their keystrokes evenly; an abandoned run is one long silence. Throughput can't
        // tell those apart -- 25 characters in 60 seconds is both a 5-WPM beginner and an
        // idle tab -- which is why it stays reserved for survival (see AntiCheatService).
        $isAfk = $this->isAfkSession($session->maxIdleMs / 1000, $duration);

        $isPersonalBest = false;
        $previousBest = null;
        $levelData = null;
        $xpEarned = 0;
        $survivalPreviousBest = null;
        $isSurvivalPersonalBest = false;
        // Initialised here, not inside the auth gate: the session payload below is built for
        // guests and abandoned runs too, and both must carry an empty list rather than nothing.
        $newlyUnlocked = [];
        // What the war received from this run, or null if it received nothing (see
        // attachToWarClaim). Same reason as above for living outside the auth gate.
        $warScore = null;

        if (Auth::check() && $isAfk) {
            // Nothing is written for an abandoned run, but the result screen still renders
            // (from the session, never the DB) and needs the player's current level.
            $levelData = Auth::user()->levelData();
        }

        if (Auth::check() && ! $isAfk) {
            $user = Auth::user();

            // Read BEFORE the transaction below inserts this session's own row, or the
            // result would be compared against itself.
            [
                'previousBest' => $previousBest,
                'isPersonalBest' => $isPersonalBest,
                'survivalPreviousBest' => $survivalPreviousBest,
                'isSurvivalPersonalBest' => $isSurvivalPersonalBest,
            ] = $this->resolvePersonalBest($user->id, $duration, $finalNetWpm);

            // Review (§7.5): a run that clears every hard gate but still doesn't add up enters
            // automatic probation, not human review -- real players improve, and a wrong
            // rejection costs more trust than a delayed record. `pending` rows still save and
            // show on the player's own profile, but stay out of public records until later clean
            // sessions corroborate them. Computed BEFORE the transaction so it cannot compare
            // against itself.
            //
            // The two modes ask different questions, because they are scored on different
            // things. Time/words are scored on WPM, so the question is whether this speed fits
            // the player's own history. Survival is scored on DURATION, and its stamina
            // simulation runs entirely in the browser -- so the question is whether the player
            // typed enough to have stayed alive that long at all.
            $reviewDecision = $this->mainMode === 'survival'
                ? null
                : app(LongitudinalBaseline::class)->decisionFor(
                    $user->id,
                    $this->mainMode,
                    (string) $this->subMode,
                    $this->contentLang,
                    $finalNetWpm,
                    $timing,
                );
            $reviewReason = $reviewDecision?->reason;
            $reviewStatus = $reviewReason === null
                ? TypingResult::REVIEW_CLEAR
                : TypingResult::REVIEW_PENDING;

            if ($reviewStatus === TypingResult::REVIEW_PENDING) {
                // It may numerically beat the previous record, but it is not a PB until the
                // automatic resolver trusts it. Never celebrate a withheld public number.
                $isPersonalBest = false;
                $isSurvivalPersonalBest = false;
            }

            $typingResult = null;

            DB::transaction(function () use (
                &$xpEarned, &$warScore, $user, $duration, $finalNetWpm, $finalRawWpm, $finalAccuracy,
                $correctKeystrokes, $incorrectKeystrokes, $score, $reviewStatus, $reviewReason,
                $reviewDecision, $timing, $consistency, $session, &$typingResult

            ) {
                // XP based on volume + accuracy bonus. The formula is centralized in
                // User::addExp() -> one source of truth with multiplayer; it also accumulates
                // total_xp & saves.
                $xpEarned = $user->addExp($correctKeystrokes, $finalAccuracy);

                $typingResult = TypingResult::create([
                    'user_id' => $user->id,
                    'mode' => $this->mainMode, // 'time' | 'words' | 'survival'
                    // survival: difficulty ('easy'|'medium'|'hard', a leaderboard filter key).
                    'mode_config' => (string) $this->subMode,
                    // Language of the typed text (en|id) -- already normalized via TypingLanguage::resolve().
                    'language' => $this->contentLang,
                    'net_wpm' => $finalNetWpm,
                    'raw_wpm' => $finalRawWpm,
                    'accuracy' => $finalAccuracy,
                    'correct_chars' => $correctKeystrokes,
                    'incorrect_chars' => $incorrectKeystrokes,
                    'duration_seconds' => $duration,
                    'score' => $score, // net word count (survival), null for other modes
                    'xp_earned' => $xpEarned,
                    'ghost_data' => null, // filled selectively by ghost mode later
                    'review_status' => $reviewStatus,
                    'review_reason' => $reviewReason,
                    // tabKey is generated server-side and Locked in Livewire. Hashing keeps the
                    // raw identifier out of storage while the unique column prevents one issued
                    // session from becoming two pieces of corroborating evidence.
                    'session_fingerprint' => hash('sha256', $this->tabKey),
                    'integrity_meta' => [
                        'version' => 1,
                        'decision' => $reviewDecision?->context ?? [],
                        'baseline_state' => $reviewDecision?->state,
                        'timing' => $timing + [
                            'sample_count' => count($session->keyIntervals),
                            'claimed_keystrokes' => $session->keyStrokeCount,
                        ],
                        'consistency' => $consistency,
                        'consistency_samples' => count($session->wpmHistory),
                        // The server issued and consumed this exact tab session. Time mode also
                        // fixes duration outright; variable modes retain their bounded claim.
                        'server_session_confirmed' => true,
                    ],
                ]);

                // Link to the war claim if this session works one (fail-safe: a solo attempt
                // is still saved normally whatever the outcome).
                $warScore = $this->attachToWarClaim($typingResult);

                // What counts as a personal best is defined once, on the model: survival is
                // excluded, a probationary run does not advance the PB, and it has to beat the
                // current record. AutomaticResultResolver refreshes the record when a cluster
                // clears; the legacy ReviewQueue still delegates to this same model rule.
                $user->recordPersonalBest($typingResult);
            });

            // A pending Time/Words result is probation, not a human review ticket. The current
            // run may complete a corroborated cluster, in which case all rows are promoted in
            // one transaction and every downstream public number is refreshed immediately.
            $automaticResolution = $typingResult
                ? app(AutomaticResultResolver::class)->resolveFor($typingResult)
                : ['promoted_count' => 0, 'current_cleared' => false, 'promoted_ids' => []];

            if ($automaticResolution['current_cleared']) {
                $reviewStatus = TypingResult::REVIEW_CLEAR;
                $reviewReason = null;
                $typingResult->refresh();

                $isPersonalBest = $this->mainMode === 'survival'
                    ? $isSurvivalPersonalBest
                    : ($previousBest === null || $finalNetWpm > $previousBest);

                // attachToWarClaim reserved the slot with zero provisional points. Promotion
                // releases the real score; refresh what this result screen reports so it agrees
                // with the scoreboard written by AutomaticResultResolver.
                if ($warScore !== null) {
                    $claim = ClanWarModeClaim::where('typing_result_id', $typingResult->id)->first();
                    if ($claim) {
                        $warScore['points'] = (float) $claim->points;
                        $warScore['verification_pending'] = false;
                    }
                }
            }

            // Both clans' scoreboards just moved. Broadcast AFTER the transaction, never inside
            // it: ClanUpdated is ShouldBroadcastNow, so a listener re-querying before COMMIT
            // would render the pre-submit totals and then never hear about it again.
            if ($warScore !== null) {
                ClanWarBroadcast::refresh(ClanWarModel::findOrFail($warScore['war_id']));
            }

            // Snapshot after XP is applied: level & progress for the result page.
            $user = $user->fresh();
            $levelData = $user->levelData();

            // Record achievements HERE -- at the point the accomplishment actually happens.
            // Recording used to piggyback on rendering the Stats/Achievements page, so a
            // player who never opened it was never recorded, and a GET page ended up with
            // a write side effect.
            //
            // The return value is what the result screen announces. It is exactly the set
            // that crossed its threshold on THIS session -- syncUnlocks only inserts rows
            // that don't exist yet -- so the banner can never repeat itself.
            $newlyUnlocked = app(AchievementService::class)->syncUnlocks($user);
        }

        $ghostResult = $this->buildGhostResult(
            $session->ghostWpm,
            $session->ghostLabel,
            $session->ghostCharsAtFinish,
            $correctKeystrokes
        );

        // Per-character error stream: a PRESENTATION tier (session-only, never touches
        // score/XP/PB/leaderboard -- so no business with AntiCheatService). Still sanitized
        // like ghost: shape validated, values cast, length capped. Unlike missedChars,
        // which is raw but safe because it's only read via known-key lookups -- here
        // `actual` is genuinely RENDERED.
        $errorEvents = TypingErrorInspector::sanitize($session->errorEvents);

        session()->put('typing_result', [
            'wpm' => $finalNetWpm,
            'rawWpm' => $finalRawWpm,
            'accuracy' => $finalAccuracy,
            'time' => $duration,
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            // This session's text is saved so the result page can offer "Retry" -- replaying
            // the exact same word sequence (only meaningful for words mode).
            'textToType' => $this->textToType,
            'score' => $score, // survival: correct chars (side stat); null for other modes
            'totalKeystrokes' => $totalKeystrokes,
            'correctKeystrokes' => $correctKeystrokes,
            'incorrectKeystrokes' => $incorrectKeystrokes,
            'wpmHistory' => $session->wpmHistory,
            'rawHistory' => $session->rawHistory,
            'missedChars' => $session->missedChars,
            'xpEarned' => $xpEarned,
            'isPersonalBest' => $isPersonalBest,
            'previousBest' => $previousBest,
            'consistency' => $consistency,
            'levelData' => $levelData,
            'drainEventCount' => $session->drainEventCount,
            'survivalPreviousBest' => $survivalPreviousBest,
            'isSurvivalPersonalBest' => $isSurvivalPersonalBest,
            'ghostResult' => $ghostResult,
            // Abandoned run: the screen still shows every number, with a banner saying it
            // was not recorded. Silently redirecting (the anti-cheat reject path) would
            // read as the app eating the session.
            'afk' => $isAfk,
            // Never hide integrity state in the database. Guests/AFK runs have no saved row;
            // authenticated runs say whether this exact result is public or still probationary.
            'resultStatus' => $isAfk
                ? 'not_recorded'
                : (Auth::check() ? ($reviewStatus ?? TypingResult::REVIEW_CLEAR) : 'guest'),
            'resultReason' => $reviewReason ?? null,
            'autoClearedCount' => $automaticResolution['promoted_count'] ?? 0,
            // Achievement KEYS only, never titles: those live solely in the lang files, and
            // carrying a copy here would rebuild the duplication that was just removed.
            'newAchievements' => $newlyUnlocked,
            // Compact by design: indices only. The result page reconstructs words from
            // textToType (already above) rather than us storing the string twice.
            'errorEvents' => $errorEvents,
            // War context so the result screen can send the player BACK to Clan War instead
            // of into a fresh solo test. A war attempt is one-shot (the claim is now filled),
            // so "Next Test / Retry" makes no sense here -- the way forward is the war page.
            // Null on an ordinary solo run.
            'war' => $this->warLock !== null ? [
                'mode' => $this->warLock['mode'],
                'config' => $this->warLock['config'],
                // What the war actually received, or NULL when this attempt filled no claim
                // (a teammate submitted the slot first, the war ended mid-run, or the claim
                // stopped validating). The screen must say so rather than print points no
                // scoreboard will ever show. war_id is internal plumbing for the broadcast
                // above and has no business in a view.
                'score' => $warScore === null ? null : Arr::except($warScore, ['war_id']),
            ] : null,
        ]);
        session()->save();

        // Full page load (WITHOUT navigate:true): leaving /typing via SPA makes the Back
        // button restore the typing-engine snapshot -> @entangle undefined & stale $wire
        // (can't type / empty stats / finish hangs). A full load makes Back reload cleanly.
        $this->redirect(route('typing.result'));
    }

    /**
     * Refuse this submission: tell the player, then send them back to a fresh test.
     *
     * Five separate gates end exactly this way -- rate limit, mode/text mismatch, a duration
     * longer than the session was open, an impossible character count, and the anti-cheat
     * verdict -- and each used to repeat the same flash-and-redirect pair.
     *
     * $reason names WHICH gate fired. It exists because the single shared message made this
     * path undebuggable: a real ~174 WPM player was rejected by the consistency gate, and
     * from the outside that was indistinguishable from a rate limit or an expired session.
     * Every constant in this file is meant to be calibrated from real play, and none of that
     * is possible while the logs cannot say which check did the rejecting. Do not add a new
     * rejection path without a reason string.
     *
     * The reason also picks the player-facing message. Only genuine manipulation gets told
     * its result was "implausible"; a rate limit or a lapsed session is not cheating and
     * accusing an honest player of it is its own bug (see lang/en/typing.php).
     *
     * Full page load, WITHOUT navigate:true, on purpose: leaving /typing through the SPA
     * makes the Back button restore the typing-engine snapshot (@entangle undefined, stale
     * $wire -> can't type, empty stats, finish hangs). A full load makes Back reload cleanly.
     *
     * Clearing the session guard is deliberately left to the caller: only the gates that
     * have already consumed the issued text need it.
     *
     * @param  string  $reason  Machine-readable gate name, for the log.
     * @param  array<string, mixed>  $context  Extra numbers worth having when tuning.
     */
    private function rejectSubmission(string $reason, array $context = [])
    {
        Log::warning('Solo result rejected', array_merge([
            'reason' => $reason,
            'user_id' => Auth::id(),
            'mode' => $this->mainMode,
            'sub_mode' => (string) $this->subMode,
        ], $context));

        session()->flash('result_rejected', __(self::REJECTION_MESSAGES[$reason] ?? 'typing.result_rejected'));

        // Re-locking an EXPIRED attempt is a loop, not a courtesy: there is no time left to
        // retry in, so the player lands on a page that can only reject them again. Send them
        // back to the war instead. This is the cut point for the whole class of bug -- even if
        // some future path auto-submits into a spent attempt, it can never bounce twice.
        $claim = $this->resolveWarClaim();

        if ($claim && $this->warAttempt($claim)->isExpired()) {
            session()->flash('clan_war_claim_error', __('clan.error.attempt_expired'));

            return $this->redirect(route('clan-war.index'));
        }

        // A rejected WAR attempt never filled its claim, so the slot is still ours and
        // unplayed. Keep the war_claim on the redirect so the player lands back inside the
        // locked war attempt (resolveWarClaim re-validates it) rather than being dumped into
        // a solo session with no way back. On a solo run there's no param to carry.
        return $this->redirect(route('typing', $this->warClaimId ? ['war_claim' => $this->warClaimId] : []));
    }

    /**
     * The record this session is measured against, and whether it broke it.
     *
     * Survival is ranked by how long the player lasted, the standard modes by Net WPM, so
     * the two read different columns -- but both scope the record to this mode+config
     * bucket, and both treat an empty bucket as "first run sets it".
     *
     * That scoping is the point: users.highest_wpm is ONE cross-mode figure, so a `time 120`
     * result used to be measured against a `time 15` sprint and the screen showed a negative
     * delta almost every session. highest_wpm is not replaced by this -- it stays the career
     * best behind the profile card, friends list, ghost picker and WPM achievements.
     * See docs/features/typing-engine.md §3.4.a.
     *
     * @return array{previousBest: ?float, isPersonalBest: bool, survivalPreviousBest: ?float, isSurvivalPersonalBest: bool}
     */
    private function resolvePersonalBest(int $userId, float $duration, float $finalNetWpm): array
    {
        $blank = [
            'previousBest' => null,
            'isPersonalBest' => false,
            'survivalPreviousBest' => null,
            'isSurvivalPersonalBest' => false,
        ];

        if ($this->mainMode === 'survival') {
            // Through the model helper, which applies the same review gate as the standard
            // modes: a survival run held for review must not come back as the record it was
            // withheld from being.
            $best = TypingResult::bestSurvivalDurationFor($userId, (string) $this->subMode);

            return array_merge($blank, [
                'survivalPreviousBest' => $best === null ? null : (float) $best,
                'isSurvivalPersonalBest' => $best === null || $duration > (float) $best,
            ]);
        }

        $best = TypingResult::bestNetWpmFor($userId, $this->mainMode, (string) $this->subMode);

        return array_merge($blank, [
            'previousBest' => $best,
            'isPersonalBest' => $best === null || $finalNetWpm > $best,
        ]);
    }

    /**
     * The ephemeral ghost-vs-player comparison for the result screen: session-only, never
     * written to the DB -- the underlying attempt is saved normally like any other run.
     *
     * Server-side gate: a ghost only counts in time/words, whatever the client sends.
     */
    private function buildGhostResult($ghostWpm, $ghostLabel, $ghostCharsAtFinish, int $correctKeystrokes): ?array
    {
        if (! $this->isGhostEligibleMode() || $ghostWpm === null || (float) $ghostWpm <= 0) {
            return null;
        }

        $ghostCharsAtFinish = (int) $ghostCharsAtFinish;

        return [
            'label' => (string) $ghostLabel,
            'wpm' => round((float) $ghostWpm, 2),
            'playerWon' => $correctKeystrokes > $ghostCharsAtFinish,
            'charDelta' => $correctKeystrokes - $ghostCharsAtFinish,
        ];
    }

    /**
     * Was this run abandoned mid-session? Decided from the longest gap between keystrokes
     * (including the stretch after the last one, which is where a `time` run spends its
     * silence) measured against a threshold that scales with the session length.
     *
     * Only the client can supply that timing -- the server never sees individual
     * keystrokes. Trusting it here is safe in a way trusting WPM never is, because the
     * incentive runs backwards: hiding a gap only buys you a WORSE result, and a player who
     * wants a bad run thrown away can already just leave the page before the timer ends.
     *
     * War attempts are the one place where getting a result discarded WOULD pay: a rejected
     * result never fills the claim (that happens further down, inside the transaction), so
     * the slot would reopen and could be retried -- with the same fixed text in Words mode.
     * They are therefore exempt: one claim, one chance, however the run goes.
     */
    private function isAfkSession(float $maxIdleSeconds, float $duration): bool
    {
        if ($this->warLock !== null) {
            return false;
        }

        $threshold = max(self::AFK_MIN_IDLE_SECONDS, $duration * self::AFK_IDLE_FRACTION);

        return $maxIdleSeconds > $threshold;
    }

    // Consistency: how steady WPM was across the session (from per-second wpmHistory).
    // 100% = perfectly even speed. This is the DISPLAY figure: 2 samples is enough to show
    // a player a number, but nowhere near enough to accuse them with one -- for that see
    // consistencyForAntiCheat() below.
    private function computeConsistency(array $history): ?int
    {
        $values = array_values(array_filter($history, fn ($v) => is_numeric($v)));
        $n = count($values);
        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        if ($mean <= 0) {
            return null;
        }

        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;
        $sd = sqrt($variance);

        return (int) round(max(0, 1 - $sd / $mean) * 100);
    }

    /**
     * Minimum per-second WPM samples before consistency may REJECT a run.
     *
     * wpmHistory is sampled once per second, so the sample size is just the session length:
     * a `words/10` test at 174 WPM finishes in under four seconds and yields THREE samples.
     * Over three samples `1 - sd/mean` is not a measure of evenness, it is a measure of how
     * few samples there are -- a completely normal human curve of 170/174/176 scores 99, and
     * 168/173/175/176 scores 98. Both clear the 97 floor, so genuine fast players on short
     * tests were rejected as bots. That is the bug behind the reported 174 WPM rejection.
     *
     * 10 samples keeps the signal on runs long enough for the statistic to mean something
     * (time/15 and up, longer word tests) and gives up on the rest. Giving up is correct: a
     * three-second run cannot be told apart from a bot by steadiness alone, and the other
     * guards -- the character ceiling, the WPM ceiling, keystroke timing -- still cover it.
     */
    private const MIN_CONSISTENCY_SAMPLES = 10;

    /**
     * Consistency, but only when there are enough samples for it to be evidence.
     *
     * Returns null below MIN_CONSISTENCY_SAMPLES, and null is never flagged by
     * isImpossiblyConsistent(). Deliberately separate from computeConsistency() so the
     * player still SEES their consistency on a short run -- a noisy display stat is fine,
     * a noisy rejection is not.
     */
    private function consistencyForAntiCheat(array $history): ?int
    {
        $usable = count(array_filter($history, fn ($v) => is_numeric($v)));

        return $usable >= self::MIN_CONSISTENCY_SAMPLES
            ? $this->computeConsistency($history)
            : null;
    }

    public function render()
    {
        return view('livewire.typing-engine');
    }
}
