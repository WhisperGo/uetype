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
     *
     * NOTE: this no longer prices a refresh. It once did -- `words` duration was
     * `max(claim, anchor elapsed - GRACE)` -- and that was a guess standing in for a
     * measurement. Thirty seconds of forgiveness is right for reading time and wrong for typing
     * time, and a reload turned the second into the first: refresh 25 seconds in and 25 real
     * typing seconds were erased, so the same characters landed on a shorter clock and WPM rose.
     * The ledger below measures each session instead, and this constant went back to meaning
     * only what its name says.
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
     *
     * Granted ONCE per attempt, not once per mount -- see `attempt_grace_used`. It used to be
     * added by remainingSeconds() on every call, and remainingSeconds() runs on every page
     * load, so ten refreshes on a 60-second slot handed back 100 seconds. An allowance for a
     * page load has to be spent the way a page load is: once.
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
        $resumable = $fresh->mode !== 'survival';

        return new ClanWarAttemptState(
            text: (string) $fresh->attempt_text,
            anchoredAt: $fresh->attempt_started_at,
            isResume: $isResume,
            resumeChars: $resumable ? (int) $fresh->attempt_chars : 0,
            carriedMs: $resumable ? (int) $fresh->attempt_carried_ms : 0,
            carriedCorrectChars: $resumable ? (int) $fresh->attempt_carried_correct_chars : 0,
            carriedTotalChars: $resumable ? (int) $fresh->attempt_carried_total_chars : 0,
            remainingSeconds: $this->remainingSeconds($fresh, $elapsed),
            survivalBudgetRemaining: $this->survivalBudgetRemaining($fresh, $elapsed),
            wallBudgetSeconds: $this->wallBudgetSeconds($fresh),
        );
    }

    /**
     * Seal the session that just ended into the carried ledger, and clear the live slot.
     *
     * Called from mount() and nowhere else, because a page load is exactly what ends a session
     * -- it is the one event that means "whatever was running is not running any more". open()
     * cannot do this: it also runs during saveResult(), and the submission there already carries
     * the live session in full, so folding at that moment would count it twice.
     *
     * Idempotent in the way that matters: sealing a live slot that is already zero adds zero.
     * A player who opens the page and types nothing carries nothing.
     */
    public function sealLiveSession(ClanWarModeClaim $claim): void
    {
        if ($claim->mode === 'survival') {
            return;
        }

        DB::transaction(function () use ($claim) {
            /** @var ClanWarModeClaim $locked */
            $locked = ClanWarModeClaim::whereKey($claim->id)->lockForUpdate()->first();

            if ((int) $locked->attempt_live_ms === 0 && (int) $locked->attempt_live_total_chars === 0) {
                return;
            }

            $locked->update([
                'attempt_carried_ms' => (int) $locked->attempt_carried_ms + (int) $locked->attempt_live_ms,
                'attempt_carried_correct_chars' => (int) $locked->attempt_carried_correct_chars + (int) $locked->attempt_live_correct_chars,
                'attempt_carried_total_chars' => (int) $locked->attempt_carried_total_chars + (int) $locked->attempt_live_total_chars,
                'attempt_live_ms' => 0,
                'attempt_live_correct_chars' => 0,
                'attempt_live_total_chars' => 0,
            ]);
        });

        $claim->refresh();
    }

    /** Work banked by sessions this attempt has already abandoned. Zero for survival. */
    public function carried(ClanWarModeClaim $claim): array
    {
        if ($claim->mode === 'survival') {
            return ['ms' => 0, 'correct' => 0, 'total' => 0];
        }

        return [
            'ms' => (int) $claim->attempt_carried_ms,
            'correct' => (int) $claim->attempt_carried_correct_chars,
            'total' => (int) $claim->attempt_carried_total_chars,
        ];
    }

    /**
     * Persist where the running session has got to AND what it has spent getting there.
     *
     * The position was always client-reported and was never worth credit: the restored
     * characters live only in the browser. The ledger IS worth credit, so it is bounded the
     * same way everything else in this project is -- against a clock the client cannot move.
     *
     * Three bounds, each closing a different forgery:
     *
     *  - Characters cannot exceed what the anchor's wall clock could physically produce
     *    (SoloSessionGuard::MAX_CHARS_PER_SECOND). This is what stops `chars = 4000` at t = 0.
     *  - Typing time cannot exceed the wall clock either. Over-reporting time would be the
     *    honest direction (it lowers WPM), but an attempt that claims more time than has
     *    passed is still describing something that did not happen.
     *  - Nothing may fall. A session's counters only rise, so a decrease is either a stale
     *    packet arriving late or a rewind to replay the easy stretch of the text; both are
     *    served correctly by keeping the higher value.
     *
     * Under-reporting stays safe for a reason worth stating: a dropped ping loses the position
     * AND the time AND the characters together, so what survives is still an internally
     * consistent run. The player simply resumes a little further back than they really were.
     */
    public function recordProgress(ClanWarModeClaim $claim, int $chars, int $typedMs, int $totalChars, int $correctChars): void
    {
        $textLength = mb_strlen((string) $claim->attempt_text);

        if ($textLength === 0) {
            return;
        }

        $elapsed = $this->elapsedSeconds($claim);
        $physicalChars = (int) floor($elapsed * SoloSessionGuard::MAX_CHARS_PER_SECOND);

        $chars = max(0, min($chars, $textLength, $physicalChars));
        $totalChars = max(0, min($totalChars, $physicalChars));
        $correctChars = max(0, min($correctChars, $totalChars));
        $typedMs = max(0, min($typedMs, (int) round($elapsed * 1000)));

        $updates = [];

        if ($chars > (int) $claim->attempt_chars) {
            $updates['attempt_chars'] = $chars;
        }

        // The ledger moves as one row, keyed on its total: correct and elapsed belong to the
        // same snapshot, and letting them advance independently would let a client send its
        // best correct count and its shortest clock from two different moments.
        if ($totalChars > (int) $claim->attempt_live_total_chars) {
            $updates['attempt_live_total_chars'] = $totalChars;
            $updates['attempt_live_correct_chars'] = $correctChars;
            $updates['attempt_live_ms'] = max($typedMs, (int) $claim->attempt_live_ms);
        }

        if ($updates === []) {
            return;
        }

        // Conditional update rather than a lock: the ping is the hottest path here (one per
        // finished word) and the condition below is the same monotonic rule, enforced by the
        // database instead of by holding a row.
        ClanWarModeClaim::whereKey($claim->id)
            ->where(function ($query) use ($updates) {
                $query->where('attempt_chars', '<', $updates['attempt_chars'] ?? 0)
                    ->orWhere('attempt_live_total_chars', '<', $updates['attempt_live_total_chars'] ?? 0);
            })
            ->update($updates);
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
        // `survival`: never resumes, so it has no ledger. The claim is already bounded by
        // SoloSessionGuard::claimsMoreTimeThanElapsed against THIS session, and the war credit
        // is capped separately (see scoredDuration). Lengthening it here would raise the score,
        // which is the one thing survival must never reward.
        if ($claim->mode === 'survival') {
            return $claimedSeconds;
        }

        // Every second of typing this attempt has seen: the sessions it abandoned, plus the one
        // submitting now. This is the whole fix -- the numerator below counts characters from
        // the entire attempt, so the denominator must span it too.
        $typedSeconds = ((int) $claim->attempt_carried_ms / 1000) + $claimedSeconds;

        // `time`: the slot IS n seconds, and a single-session run is scored over exactly that
        // however fast it finished. But a resumed run can exceed it -- COUNTDOWN_GRACE_SECONDS
        // deliberately hands back a few seconds for the page load -- and scoring 65 seconds of
        // typing over 60 is the same inflation in miniature. Take whichever is longer.
        if ($claim->mode === 'time') {
            return max((float) $claim->mode_config, $typedSeconds);
        }

        // `words`: no timer, so duration is the whole score.
        //
        // This used to read `max(claim, anchor elapsed - GRACE_SECONDS)` -- the anchor's wall
        // clock, discounted by a guess. The guess was the leak: 30 seconds is a fair allowance
        // for reading a text you have not seen, and a catastrophic one for a reload, where it
        // erases half a minute of typing that genuinely happened. Summing the sessions needs no
        // allowance at all, because time nobody was typing was never in the sum to begin with.
        return $typedSeconds;
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

    /**
     * Whether this claim's attempt has run out of clock. THE answer, for every caller.
     *
     * This predicate exists because four places used to derive it independently and disagreed:
     * the war grid never asked at all (so it offered Resume on a spent slot), the client
     * restarted its countdown from zero, ClanWarAttemptState::isExpired() answered only for a
     * mounted attempt, and ClanWarResolver used a 15-minute proxy. Anything that needs to know
     * "is this attempt still alive" asks here now, so the four can no longer drift apart.
     *
     * Reads the claim alone -- no mounted state, no ClanWarAttemptState -- precisely so the
     * grid and the resolver can call it over a plain query result.
     *
     * `words` is never expired, and that is a real answer rather than a gap: the mode has no
     * countdown and no budget, so there is no clock for it to run out of. STALE_MINUTES stays
     * the safety net there (see ClanWarResolver).
     */
    public function isClaimExpired(ClanWarModeClaim $claim): bool
    {
        if ($claim->attempt_started_at === null) {
            return false;
        }

        $elapsed = $this->elapsedSeconds($claim);

        return match ($claim->mode) {
            'time' => $this->remainingSeconds($claim, $elapsed) <= 0,
            'survival' => $this->survivalBudgetRemaining($claim, $elapsed) <= 0,
            default => false,
        };
    }

    /**
     * Mark this attempt's one countdown grace as spent, returning whether it was still unspent.
     *
     * Separated from open() because only a real MOUNT may burn it: open() also runs during
     * saveResult(), and consuming the allowance there would charge a player for the page load
     * they are in the middle of leaving.
     */
    public function consumeCountdownGrace(ClanWarModeClaim $claim): void
    {
        if ($claim->attempt_grace_used) {
            return;
        }

        ClanWarModeClaim::whereKey($claim->id)->update(['attempt_grace_used' => true]);

        $claim->attempt_grace_used = true;
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

    /**
     * Seconds left on a `time` slot, clamped so it never exceeds the slot's own length.
     *
     * The grace is added only while it is UNSPENT. Adding it unconditionally was the leak:
     * this runs on every mount, so every refresh renewed the allowance and the countdown the
     * slot is defined by stopped being a bound at all.
     */
    private function remainingSeconds(ClanWarModeClaim $claim, float $elapsed): ?int
    {
        if ($claim->mode !== 'time') {
            return null;
        }

        $nominal = (int) $claim->mode_config;
        $grace = $claim->attempt_grace_used ? 0.0 : self::COUNTDOWN_GRACE_SECONDS;

        return max(0, min($nominal, (int) floor($nominal + $grace - $elapsed)));
    }

    private function survivalBudgetRemaining(ClanWarModeClaim $claim, float $elapsed): ?float
    {
        if ($claim->mode !== 'survival') {
            return null;
        }

        return max(0.0, self::SURVIVAL_BUDGET_SECONDS - $elapsed);
    }
}
