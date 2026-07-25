<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\ClanWarFixedText;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Services\AchievementService;
use App\Services\AntiCheatService;
use App\Services\ClanWarScorer;
use App\Services\GhostResolver;
use App\Services\SoloSessionGuard;
use App\Services\TextGeneratorService;
use App\Services\TypingErrorInspector;
use App\Support\SoloSessionPayload;
use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
     * Result submissions allowed per minute. The shortest test is 15 seconds, so even
     * back-to-back honest play stays well under this; the limit exists to stop a script
     * from sweeping payloads until one clears the anti-cheat checks.
     */
    private const MAX_RESULTS_PER_MINUTE = 10;

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

    public int $typingSessionKey = 0;

    // Active-ghost flag; server is the source of truth. Ghost is valid only for
    // time/words -- switching to survival forces false (an invariant, not just a client event).
    public bool $ghostActive = false;

    // Clan War: id of the ClanWarModeClaim being worked (from ?war_claim=). If valid, the
    // mode is locked to the claim's mode/config and the typing result becomes a war attempt
    // automatically. Null = solo session.
    #[Url(as: 'war_claim')]
    public ?int $warClaimId = null;

    // War claim mode detail (mode+config) for the view banner. Null when not war-locked.
    public ?array $warLock = null;

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
        // War-lock is checked first: valid -> mode is forced to the claim; invalid ->
        // reset to null and behave as a normal solo session (fail-safe).
        $claim = $this->resolveWarClaim();

        if ($claim) {
            [$this->mainMode, $this->subMode] = $this->normalizeMode($claim->mode, $claim->mode_config);
            $this->warLock = ['mode' => $this->mainMode, 'config' => $this->subMode];
        } else {
            $this->warClaimId = null;

            // Restore previous preferences if any (normal solo session only). Through
            // normalizeMode so wild values / survival-for-guest are rejected too.
            if (session()->has('typing_preferences')) {
                $prefs = session('typing_preferences');
                [$this->mainMode, $this->subMode] = $this->normalizeMode($prefs['mode'] ?? 'time', $prefs['subMode'] ?? '30');
            }
        }

        // Content language is restored separately: war only locks mode/config, not language.
        if (session()->has('typing_preferences')) {
            $this->contentLang = TypingLanguage::resolve(session('typing_preferences')['contentLang'] ?? null);
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

    /**
     * Link the typing result to the war claim (re-validated, never trusting the client's
     * $warClaimId). The conditional update `whereNull('typing_result_id')` prevents two
     * parallel submits from filling the same claim. Called inside saveResult()'s DB::transaction.
     */
    private function attachToWarClaim(TypingResult $typingResult): void
    {
        $claim = $this->resolveWarClaim();

        if (! $claim) {
            return;
        }

        $points = ClanWarScorer::score($claim->mode, $claim->mode_config, $typingResult);

        // Conditional update: only fill if not yet submitted (idempotent, race-safe).
        ClanWarModeClaim::where('id', $claim->id)
            ->whereNull('typing_result_id')
            ->update([
                'typing_result_id' => $typingResult->id,
                'user_id' => Auth::id(),
                'points' => $points,
            ]);
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
    }

    /**
     * Produce the text for the current session (retry text, fixed war text, or random),
     * then record it server-side so the submitted result can be validated against what
     * was actually issued.
     */
    public function generateText()
    {
        $this->typingSessionKey++;
        $this->assembleText();

        // Every path above lands here: the guard always sees the text the player really
        // got, along with the moment it was handed over (see SoloSessionGuard).
        app(SoloSessionGuard::class)->start(
            $this->mainMode,
            (string) $this->subMode,
            (string) $this->textToType
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

        // Clan War Words mode uses FIXED text (identical for all players on the same config)
        // for fairness, not random assembly. Time/Survival war just have restart blocked.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            if ($this->mainMode === 'words') {
                $fixed = ClanWarFixedText::forWords($this->subMode);

                // Fall back to normal generation if no fixed row exists (screen is never blank).
                if ($fixed !== null) {
                    $this->textToType = $fixed;

                    return;
                }
            }
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
            return $this->rejectSubmission();
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
        if (! $guard->matchesIssuedSession($this->mainMode, (string) $this->subMode)) {
            $guard->clear();

            return $this->rejectSubmission();
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
        if ($this->mainMode !== 'time' && $guard->claimsMoreTimeThanElapsed($claimedDuration)) {
            $guard->clear();

            return $this->rejectSubmission();
        }

        // Duration comes from the SERVER, not the payload. In `time` mode the sub-mode
        // fixes it outright; the variable-length modes keep their (now bounded) claim.
        $duration = $guard->resolveDuration(
            $claimedDuration,
            $this->mainMode,
            (string) $this->subMode
        );

        // Character counts are bounded by what the issued text could physically produce in
        // that time, so an inflated count can no longer buy WPM, XP or a leaderboard slot.
        $maxChars = $guard->maxPlausibleChars($this->mainMode, $duration);

        if ($maxChars !== null && $totalKeystrokes > $maxChars) {
            // A payload this far past the physical ceiling is fabricated, not merely noisy.
            // Silently clamping it would still hand the sender a valid result; refuse it
            // instead, the same way an impossible WPM is refused below.
            $guard->clear();

            return $this->rejectSubmission();
        }

        // Correct can never exceed total; clamp so the pair stays coherent.
        $correctKeystrokes = min($correctKeystrokes, $totalKeystrokes);
        $incorrectKeystrokes = max(0, $totalKeystrokes - $correctKeystrokes);

        // One issued text = one submission. Without this, the same finished session could
        // be replayed to farm XP and records.
        $guard->clear();

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

        // Reject only what genuinely deserves it (cheating / empty session / stalling in
        // survival). The gate used to be `! $check['valid']`, which also threw away real
        // SLOW-TYPER results -- low throughput is slow, not cheating, and in time/words the
        // duration can't be pumped for any advantage.
        if ($antiCheat->rejectsSoloResult($check['reasons'], $this->mainMode)) {
            return $this->rejectSubmission();
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

        $consistency = $this->computeConsistency($session->wpmHistory);

        $isPersonalBest = false;
        $previousBest = null;
        $levelData = null;
        $xpEarned = 0;
        $survivalPreviousBest = null;
        $isSurvivalPersonalBest = false;

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

            DB::transaction(function () use (
                &$xpEarned, $user, $duration, $finalNetWpm, $finalRawWpm, $finalAccuracy,
                $correctKeystrokes, $incorrectKeystrokes, $score

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
                ]);

                // Link to the war claim if this session works one (fail-safe: a solo attempt
                // is still saved normally whatever the outcome).
                $this->attachToWarClaim($typingResult);

                // WPM record only from the measured time/words modes; survival is excluded
                // (achieved under stamina pressure, not apples-to-apples, just a side stat).
                if ($this->mainMode !== 'survival' && $finalNetWpm > (float) $user->highest_wpm) {
                    $user->highest_wpm = $finalNetWpm;
                    $user->save();
                }
            });

            // Snapshot after XP is applied: level & progress for the result page.
            $user = $user->fresh();
            $levelData = $user->levelData();

            // Record achievements HERE -- at the point the accomplishment actually happens.
            // Recording used to piggyback on rendering the Stats/Achievements page, so a
            // player who never opened it was never recorded, and a GET page ended up with
            // a write side effect.
            app(AchievementService::class)->syncUnlocks($user);
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
            // Compact by design: indices only. The result page reconstructs words from
            // textToType (already above) rather than us storing the string twice.
            'errorEvents' => $errorEvents,
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
     * Full page load, WITHOUT navigate:true, on purpose: leaving /typing through the SPA
     * makes the Back button restore the typing-engine snapshot (@entangle undefined, stale
     * $wire -> can't type, empty stats, finish hangs). A full load makes Back reload cleanly.
     *
     * Clearing the session guard is deliberately left to the caller: only the gates that
     * have already consumed the issued text need it.
     */
    private function rejectSubmission()
    {
        session()->flash('result_rejected', __('typing.result_rejected'));

        return $this->redirect(route('typing'));
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
            $best = TypingResult::where('user_id', $userId)
                ->where('mode', 'survival')
                ->where('mode_config', (string) $this->subMode)
                ->max('duration_seconds');

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
    // 100% = perfectly even speed. Presentation only, not anti-cheat. Needs >= 2 samples & mean > 0.
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

    public function render()
    {
        return view('livewire.typing-engine');
    }
}
