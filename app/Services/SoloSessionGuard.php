<?php

namespace App\Services;

/**
 * Server-side record of a solo typing session, used to validate the result the client
 * later submits.
 *
 * Why this exists: saveResult() receives duration and keystroke counts FROM the client.
 * Recomputing WPM from those numbers still trusts them, so a forged payload (e.g. 1495
 * correct chars in 60s = 299 WPM) passed every check and reached the leaderboard.
 * Livewire properties are no safer -- the client can set textToType too -- so the
 * reference values live in the SESSION, which the browser cannot write to.
 *
 * The session stores which mode/sub-mode was issued and how long the text is. On submit,
 * `time` mode takes its duration from the sub-mode rather than the payload, and every mode
 * has its character count bounded by what the issued text could physically produce. A
 * submission that no longer matches the issued session, or that blows past the character
 * ceiling, is refused rather than quietly clamped.
 */
class SoloSessionGuard
{
    /** Session key holding the active session's server-side facts. */
    private const SESSION_KEY = 'solo_session_guard';

    /**
     * How far total keystrokes may exceed the issued text length, as a multiplier.
     *
     * Typing is not one keystroke per character: mistakes, backspaces and retyped words
     * all add real keystrokes, so an honest run routinely overshoots the text. A 25-word
     * text is ~130 characters and 150+ keystrokes is a normal result for it. The factor
     * is deliberately generous -- this cap exists to stop fabricated counts (thousands of
     * characters against a 130-character text), not to police sloppy typing.
     */
    private const TEXT_LENGTH_TOLERANCE_FACTOR = 3.0;

    /** Flat allowance on top of the factor, so very short texts aren't over-constrained. */
    private const CHAR_TOLERANCE = 50;

    /**
     * Peak human typing speed in characters per second, used as the physical ceiling for
     * "how many characters could possibly have been typed in this many seconds".
     * ~15 cps ≈ 180 WPM sustained, comfortably above any real player while still
     * rejecting the fabricated numbers this guard exists to catch.
     */
    private const MAX_CHARS_PER_SECOND = 15.0;

    /** Remember that a session just started, with the text the server actually issued. */
    public function start(string $mode, string $subMode, string $text): void
    {
        session()->put(self::SESSION_KEY, [
            'mode' => $mode,
            'sub_mode' => $subMode,
            'text_length' => mb_strlen($text),
            'started_at' => microtime(true),
        ]);
    }

    /** The active session's facts, or null when none was ever started. */
    public function current(): ?array
    {
        $data = session()->get(self::SESSION_KEY);

        return is_array($data) && isset($data['started_at']) ? $data : null;
    }

    /** Drop the session so one issued text can only be submitted once (no replay). */
    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * The duration to actually store, given what the client claimed.
     *
     * `time` mode has a fixed length, so the sub-mode wins outright -- a client claiming
     * 5 seconds for a 30-second test cannot inflate its WPM.
     *
     * For `words` and `survival` the duration is genuinely variable, so the claim is kept
     * as-is. The server clock is NOT used to shrink it: elapsed time is an upper bound on
     * an honest session (you cannot have typed longer than the server has been waiting),
     * never a lower one, so replacing the claim with elapsed would slash the duration and
     * INFLATE the WPM -- the opposite of the goal. Over-claiming is separately harmless
     * here because a longer duration only lowers WPM; the direction that pays, claiming
     * too LITTLE time, is caught by the character ceiling in maxPlausibleChars().
     */
    public function resolveDuration(float $claimedSeconds, string $mode, string $subMode): float
    {
        if ($mode === 'time') {
            return (float) $subMode;
        }

        return max(0.0, $claimedSeconds);
    }

    /**
     * The highest character count this session could plausibly have produced.
     *
     * Two independent ceilings, whichever is lower:
     *  - physical: elapsed seconds x peak human cps;
     *  - textual: the issued text length (+ tolerance) for the fixed-length modes, since
     *    `words` and `survival` end when the text does and cannot exceed it.
     *
     * `time` mode loops its text, so only the physical ceiling applies there.
     */
    public function maxPlausibleChars(string $mode, float $durationSeconds): ?int
    {
        $session = $this->current();

        if ($session === null) {
            return null;
        }

        $physical = (int) ceil($durationSeconds * self::MAX_CHARS_PER_SECOND) + self::CHAR_TOLERANCE;

        if ($mode === 'time') {
            return $physical;
        }

        $textual = (int) ceil($session['text_length'] * self::TEXT_LENGTH_TOLERANCE_FACTOR)
            + self::CHAR_TOLERANCE;

        return min($physical, $textual);
    }

    /**
     * Does the submitted session match the one the server issued?
     *
     * Guards against a client that switches mode/sub-mode after receiving the text (for
     * instance taking an easy 15-second test and submitting it as a 120-second result).
     */
    public function matchesIssuedSession(string $mode, string $subMode): bool
    {
        $session = $this->current();

        if ($session === null) {
            return false;
        }

        return $session['mode'] === $mode && (string) $session['sub_mode'] === (string) $subMode;
    }
}
