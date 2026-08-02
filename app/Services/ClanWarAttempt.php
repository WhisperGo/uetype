<?php

namespace App\Services;

use App\Models\ClanWarFixedText;
use App\Models\ClanWarModeClaim;
use App\Support\ClanWarAttemptState;
use Illuminate\Support\Facades\DB;

/**
 * Owns the server-side clock and frozen text of a single Clan War attempt.
 *
 * Why a service and not more methods on TypingEngine: the engine already carries solo policy
 * and is long enough that a war-shaped rule buried in it would be found by nobody. Keeping the
 * war clock here also leaves SoloSessionGuard doing its one job -- "what did the server issue
 * to THIS tab" -- so solo behaviour stays provably untouched by any of this.
 *
 * The invariant everything here exists to hold: re-entering a claim resumes the SAME attempt.
 * It never starts a new one, and the wall clock already burned is never given back.
 */
class ClanWarAttempt
{
    /**
     * Wall-clock allowance on top of a slot's nominal length.
     *
     * Covers the page load, Livewire settling, and the seconds a player spends READING before
     * the first keystroke -- the client's own clock starts on that keystroke, so without this
     * an honest single-session run would look like it overran. Deliberately the same magnitude
     * as SoloSessionGuard::DURATION_SLACK_SECONDS: one number to explain, not two.
     *
     * Re-tune from the rejection log (see anti-cheat-wpm.md 10.2b), not from intuition.
     */
    public const GRACE_SECONDS = 30.0;

    /**
     * Allowance on a `time` slot's COUNTDOWN, distinct from GRACE_SECONDS and much smaller.
     *
     * The two graces answer different questions. GRACE_SECONDS forgives the seconds a player
     * spent READING before their first keystroke, which is why it is generous. This one only
     * has to cover page load and Livewire settling -- because the moment it matters at all is
     * a RESUME, and somebody re-entering an attempt has already read the text. Reusing the
     * 30-second grace here would hand back the entire clock of a 30-second slot, turning the
     * refresh this class exists to price back into a free restart.
     */
    public const COUNTDOWN_GRACE_SECONDS = 10.0;

    /**
     * Total wall budget for a survival slot: wasted seconds plus credited seconds may not
     * exceed it.
     *
     * Survival is the one mode that scores ON the clock (duration/90), so a continuous anchored
     * clock would REWARD refreshing, while a per-session clock would allow unlimited retries
     * ("die at 20s, refresh, die at 25s, refresh, submit the 40s run"). Both fail, so survival
     * neither resumes nor restarts freely: it restarts inside a budget that keeps shrinking.
     *
     * 120 leaves ~30 seconds of waste before a 90-second run (the duration that already earns
     * the full ceiling) starts being clipped -- enough for a page load and one genuine mishap,
     * not enough for a second attempt. Runs longer than 90 seconds may be clipped and it costs
     * nothing: the performance ratio saturates at 90, so the points are identical either way.
     */
    public const SURVIVAL_BUDGET_SECONDS = 120.0;

    /**
     * An attempt opened this long ago and never submitted is dead, not in progress.
     *
     * Longer than any slot can take (time/120 = 2 min, survival budget = 2.5 min) so a slow
     * honest player is never declared stale mid-run. Read by ClanWarResolver to decide whether
     * a clan still has work outstanding.
     */
    public const STALE_MINUTES = 15;

    /**
     * Open the attempt for a claim, or re-open the one already running.
     *
     * The write happens on a GET, and that is deliberate: opening the page IS the act of
     * starting the attempt. Any "I started typing" ping would simply not be sent by the client
     * we are defending against, so the mount is the only anchor that cannot be skipped. It is
     * idempotent -- lockForUpdate plus a null check means concurrent tabs settle on one anchor.
     */
    public function open(ClanWarModeClaim $claim, string $contentLang): ClanWarAttemptState
    {
        $fresh = DB::transaction(function () use ($claim, $contentLang) {
            /** @var ClanWarModeClaim $locked */
            $locked = ClanWarModeClaim::whereKey($claim->id)->lockForUpdate()->first();

            if ($locked->attempt_started_at === null) {
                $locked->update([
                    'attempt_started_at' => now(),
                    'attempt_text' => $this->issueText($locked, $contentLang),
                ]);
            } elseif ($locked->attempt_text === null) {
                // A claim opened before this column existed. Freeze one now rather than
                // re-rolling on every mount -- the anchor is kept, so no clock is refunded.
                $locked->update(['attempt_text' => $this->issueText($locked, $contentLang)]);
            }

            return $locked;
        });

        $isResume = ! $fresh->wasChanged('attempt_started_at');
        $elapsed = $this->elapsedSeconds($fresh);

        return new ClanWarAttemptState(
            text: (string) $fresh->attempt_text,
            anchoredAt: $fresh->attempt_started_at,
            isResume: $isResume,
            resumeProgress: $fresh->mode === 'survival' ? 0 : (int) $fresh->attempt_progress,
            remainingSeconds: $this->remainingSeconds($fresh, $elapsed),
            survivalBudgetRemaining: $this->survivalBudgetRemaining($fresh, $elapsed),
            wallBudgetSeconds: $this->wallBudgetSeconds($fresh),
        );
    }

    /**
     * Persist a coarse resume position, bounded three ways.
     *
     * A resume position is client-reported, and that is new input -- but it is not a new attack
     * surface, because the server never grants credit for it. The restored characters exist
     * only in the browser; the scored numbers are still the keystroke counts submitted at
     * finish, bounded by the same character ceiling as always. The worst a forged value buys is
     * the ability to submit a large count, which a forged payload could always attempt, and the
     * physical bound below makes even that cost real wall time.
     */
    public function recordProgress(ClanWarModeClaim $claim, int $percent): void
    {
        $percent = max(0, min(100, $percent));

        $textLength = mb_strlen((string) $claim->attempt_text);

        if ($textLength === 0) {
            return;
        }

        // Physical bound: no more of the text can be confirmed than could have been typed in
        // the wall clock so far. Same shape as SoloSessionGuard's ceiling, applied at ping time
        // -- this is what stops `progress = 100` arriving at t = 0.
        $maxChars = $this->elapsedSeconds($claim) * SoloSessionGuard::MAX_CHARS_PER_SECOND;
        $percent = min($percent, (int) floor($maxChars / $textLength * 100));

        if ($percent <= (int) $claim->attempt_progress) {
            return;
        }

        // Conditional update: progress only ever rises. Accepting a decrease would let a client
        // rewind to replay the easy stretch of the text.
        ClanWarModeClaim::whereKey($claim->id)
            ->where('attempt_progress', '<', $percent)
            ->update(['attempt_progress' => $percent]);
    }

    /**
     * The duration a war submission is scored over, per mode.
     *
     * This is the gate that makes a refresh cost something. It only ever LENGTHENS a claimed
     * duration, which matters: anti-cheat-wpm.md 7.4 records that `min(claim, server elapsed)`
     * was tried for solo and was wrong, because server elapsed is an UPPER bound on an honest
     * session -- taking the min cut 30s to 3s and inflated WPM to 557. Taking the max is the
     * safe direction for exactly the same reason, and it can only ever lower WPM.
     */
    public function resolveDuration(ClanWarModeClaim $claim, float $claimedSeconds): float
    {
        // `time`: the slot IS n seconds of wall clock. Somebody who burned 20 of them types
        // fewer characters over the same denominator, so WPM falls on its own -- no adjustment.
        if ($claim->mode === 'time') {
            return (float) $claim->mode_config;
        }

        // `survival`: the claim is already bounded by SoloSessionGuard::claimsMoreTimeThanElapsed
        // against THIS session, and the war credit is capped separately (see scoredDuration).
        // Lengthening it here would raise the score, which is the one thing survival must never
        // reward.
        if ($claim->mode === 'survival') {
            return $claimedSeconds;
        }

        // `words`: no timer, so duration is the whole score and wasted wall clock must count.
        // The grace absorbs an honest player's reading time, so a clean single-session run
        // keeps its claim exactly; only genuinely burned minutes push past it.
        return max($claimedSeconds, $this->elapsedSeconds($claim) - self::GRACE_SECONDS);
    }

    /**
     * The duration Clan War credits a survival attempt with -- never more than its budget left.
     *
     * The rule is one sentence: WASTED seconds plus CREDITED seconds may not exceed the budget.
     * Wasted is whatever the anchor has seen that this run did not spend surviving, so a clean
     * first attempt wastes only its page load and is credited in full, while somebody on their
     * third retry has already spent the budget they would need.
     *
     * Applied ONLY when scoring the war claim, never to the stored TypingResult. Shortening a
     * duration RAISES wpm, and AntiCheatService::check() reads duration_seconds: a 900-char /
     * 55s pair would read as 196 WPM and trip the impossible-speed gate. The solo record stays
     * truthful ("you really did survive 90 seconds"); only the war credit is capped.
     */
    public function scoredDuration(ClanWarModeClaim $claim, float $durationSeconds): float
    {
        if ($claim->mode !== 'survival' || $claim->attempt_started_at === null) {
            return $durationSeconds;
        }

        $wasted = max(0.0, $this->elapsedSeconds($claim) - $durationSeconds);

        return max(0.0, min($durationSeconds, self::SURVIVAL_BUDGET_SECONDS - $wasted));
    }

    /** Seconds since the attempt was anchored; 0 when it was never opened. */
    public function elapsedSeconds(ClanWarModeClaim $claim): float
    {
        if ($claim->attempt_started_at === null) {
            return 0.0;
        }

        return max(0.0, (float) $claim->attempt_started_at->diffInMilliseconds(now()) / 1000);
    }

    /**
     * Total wall clock this attempt is allowed, the window the character ceiling spans.
     *
     * `words` has no natural length, so it gets none: its ceiling stays governed by the text
     * length rule, and its duration adjustment above is what prices a refresh.
     */
    public function wallBudgetSeconds(ClanWarModeClaim $claim): float
    {
        return match ($claim->mode) {
            'time' => (float) $claim->mode_config + self::GRACE_SECONDS,
            'survival' => self::SURVIVAL_BUDGET_SECONDS,
            default => INF,
        };
    }

    /** Pick the text for a fresh attempt: the frozen Words paper, else random assembly. */
    private function issueText(ClanWarModeClaim $claim, string $contentLang): string
    {
        if ($claim->mode === 'words') {
            $fixed = ClanWarFixedText::forWords($claim->mode_config);

            // Fall back to generation when no fixed row exists, so the screen is never blank.
            // The text is frozen onto the claim either way, so fairness within THIS attempt
            // holds even where cross-clan fairness cannot.
            if ($fixed !== null) {
                return $fixed;
            }
        }

        return app(TextGeneratorService::class)
            ->forSoloMode($claim->mode, (string) $claim->mode_config, $contentLang);
    }

    /** Seconds left on a `time` slot, clamped so it never exceeds the slot's own length. */
    private function remainingSeconds(ClanWarModeClaim $claim, float $elapsed): ?int
    {
        if ($claim->mode !== 'time') {
            return null;
        }

        $nominal = (int) $claim->mode_config;

        return max(0, min($nominal, (int) floor($nominal + self::COUNTDOWN_GRACE_SECONDS - $elapsed)));
    }

    private function survivalBudgetRemaining(ClanWarModeClaim $claim, float $elapsed): ?float
    {
        if ($claim->mode !== 'survival') {
            return null;
        }

        return max(0.0, self::SURVIVAL_BUDGET_SECONDS - $elapsed);
    }
}
