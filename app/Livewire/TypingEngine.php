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
use App\Services\TextGeneratorService;
use App\Services\TypingErrorInspector;
use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
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

    /** Produce the text for the current session (retry text, fixed war text, or random). */
    public function generateText()
    {
        $this->typingSessionKey++;

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
    public function saveResult(
        $durationMs,
        $totalKeystrokes,
        $correctKeystrokes,
        $wpmHistory = [],
        $rawHistory = [],
        $missedChars = [],
        $drainEventCount = 0,
        $ghostWpm = null,
        $ghostLabel = null,
        $ghostCharsAtFinish = null,
        $errorEvents = []
    ) {
        // Mode gate: normalize against the whitelist before it's used for score/mode_config,
        // so a wild difficulty/sub-mode can't reach the DB and pollute leaderboard filters.
        [$this->mainMode, $this->subMode] = $this->normalizeMode($this->mainMode, $this->subMode);

        // Client WPM/accuracy is NOT accepted -- the server always recomputes it (anti-cheat).
        $totalKeystrokes = (int) $totalKeystrokes;
        $correctKeystrokes = (int) $correctKeystrokes;
        $incorrectKeystrokes = max(0, $totalKeystrokes - $correctKeystrokes);

        // Survival: the leaderboard metric is duration_seconds, not score. The score column
        // becomes a side stat (correct chars while surviving); other modes don't use it.
        $score = $this->mainMode === 'survival' ? $correctKeystrokes : null;

        // Client duration is in milliseconds; store in seconds (fractional allowed) so the
        // server WPM == the WPM the user saw while typing.
        $duration = max(0.0, (float) $durationMs / 1000);

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
            session()->flash('result_rejected', __('typing.result_rejected'));

            // Full-load (see note at the result redirect): avoid the broken SPA restore.
            return $this->redirect(route('typing'));
        }

        $consistency = $this->computeConsistency($wpmHistory);

        $isPersonalBest = false;
        $previousBest = null;
        $levelData = null;
        $xpEarned = 0;
        $survivalPreviousBest = null;
        $isSurvivalPersonalBest = false;

        if (Auth::check()) {
            $user = Auth::user();

            // Captured before the transaction overwrites highest_wpm. Survival is excluded from the WPM record.
            $previousBest = (float) $user->highest_wpm;
            $isPersonalBest = $this->mainMode !== 'survival' && $finalNetWpm > $previousBest;

            if ($this->mainMode === 'survival') {
                $survivalPreviousBest = TypingResult::where('user_id', $user->id)
                    ->where('mode', 'survival')
                    ->where('mode_config', (string) $this->subMode)
                    ->max('duration_seconds');

                $isSurvivalPersonalBest = $survivalPreviousBest === null
                    || $duration > (float) $survivalPreviousBest;
            }

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

        // Ghost Mode: an ephemeral ghost-vs-player comparison (session-only), not written to
        // the DB -- the underlying attempt is still saved normally like any mode.
        // Server-side gate: ghost is ONLY valid for time/words, whatever the client sends.
        $ghostResult = null;
        if ($this->isGhostEligibleMode() && $ghostWpm !== null && (float) $ghostWpm > 0) {
            $ghostCharsAtFinish = (int) $ghostCharsAtFinish;
            $ghostResult = [
                'label' => (string) $ghostLabel,
                'wpm' => round((float) $ghostWpm, 2),
                'playerWon' => $correctKeystrokes > $ghostCharsAtFinish,
                'charDelta' => $correctKeystrokes - $ghostCharsAtFinish,
            ];
        }

        // Per-character error stream: a PRESENTATION tier (session-only, never touches
        // score/XP/PB/leaderboard -- so no business with AntiCheatService). Still sanitized
        // like ghost: shape validated, values cast, length capped. Unlike missedChars,
        // which is raw but safe because it's only read via known-key lookups -- here
        // `actual` is genuinely RENDERED.
        $errorEvents = TypingErrorInspector::sanitize($errorEvents);

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
            'wpmHistory' => $wpmHistory,
            'rawHistory' => $rawHistory,
            'missedChars' => $missedChars,
            'xpEarned' => $xpEarned,
            'isPersonalBest' => $isPersonalBest,
            'previousBest' => $previousBest,
            'consistency' => $consistency,
            'levelData' => $levelData,
            'drainEventCount' => (int) $drainEventCount,
            'survivalPreviousBest' => $survivalPreviousBest !== null ? (float) $survivalPreviousBest : null,
            'isSurvivalPersonalBest' => $isSurvivalPersonalBest,
            'ghostResult' => $ghostResult,
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
