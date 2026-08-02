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
 *
 * PER TAB, not per browser. Every method takes a $tabKey identifying one open component
 * instance (TypingEngine::$tabKey, server-assigned and #[Locked]). It used to be a single
 * record for the whole session, which meant opening a second tab destroyed the first tab's
 * issued text and its honest result was refused as "implausible" -- one of the false
 * rejections behind the 2026-07-27 anti-cheat fixes. The keying costs nothing in strictness:
 * clear() still consumes the submitted session, so one issued text still buys one result.
 */
class SoloSessionGuard
{
    /** Session key holding the server-side facts of every open tab's session. */
    private const SESSION_KEY = 'solo_session_guard';

    /**
     * How many concurrent tab sessions to remember.
     *
     * This used to be ONE session for the whole browser, which meant a second tab silently
     * destroyed the first tab's record: the first tab then failed matchesIssuedSession() and
     * an entirely honest run was refused as "implausible". Leaving a test open in a background
     * tab and starting another is ordinary browsing, not an attack.
     *
     * Bounded rather than unlimited because this lives in the session payload and a client can
     * open tabs all day; without a cap that is unbounded growth in the session store. Eight is
     * far more than anyone types in at once, and the eviction is oldest-first, so the honest
     * worst case is a player with nine tabs losing the one they opened first.
     *
     * The cap is NOT a security control -- replay protection comes from clear() removing the
     * session on submit, per tab, exactly as before.
     */
    private const MAX_TRACKED_SESSIONS = 8;

    /**
     * How far total keystrokes may exceed the issued text length, as a multiplier.
     *
     * Typing is not one keystroke per character: mistakes, backspaces and retyped words
     * all add real keystrokes, so an honest run routinely overshoots the text. A 25-word
     * text is ~130 characters and 150+ keystrokes is a normal result for it. The factor
     * is deliberately generous -- this cap exists to stop fabricated counts (thousands of
     * characters against a 130-character text), not to police sloppy typing.
     *
     * Tightened 3.0 -> 2.5 (§7.3): 2.5x still permits heavy typo/backspace correction (a
     * ~130-char text allows ~375 keystrokes) but narrows the room a short-duration `words`
     * claim had to inflate WPM arithmetically. Conservative on purpose -- see the caveat on
     * MAX_CHARS_PER_SECOND about calibrating from real data before tightening further.
     */
    private const TEXT_LENGTH_TOLERANCE_FACTOR = 2.5;

    /** Flat allowance on top of the factor, so very short texts aren't over-constrained. */
    private const CHAR_TOLERANCE = 50;

    /**
     * Peak human typing speed in characters per second, used as the physical ceiling for
     * "how many characters could possibly have been typed in this many seconds".
     *
     * 20 cps = 240 WPM sustained, the SAME ceiling AntiCheatService::MAX_RACE_WPM applies to
     * races. One definition of "beyond human" across the app: a player who is accepted in a
     * race must not be refused for the identical pace in solo.
     *
     * Raised 13 -> 20 cps (2026-07-27), on evidence. 13 cps is 156 WPM, and the previous note
     * here justified it with "near-nobody sustains even 156" -- an assumption, not data. A
     * real player reported repeated rejections at ~185 WPM with 98% accuracy, which lands at
     * 15.4 cps: honest typing, refused, and told the session was "implausible".
     *
     * The failure was also invisible in the obvious place. CHAR_TOLERANCE (+50) covers short
     * tests, so 10- and 25-word runs slipped through and only the LONGER ones were rejected:
     * same player, same speed, accepted at 25 words and refused on a 30s test. That is why it
     * read as random rather than as a speed limit.
     *
     * What the ceiling still stops: this bounds characters by ELAPSED SERVER TIME, so a
     * "patient bot" (sleep out the duration, then post a full-length payload) is capped at
     * 240 WPM instead of anything it likes. The forged-payload defence is intact; only the
     * band between honest-elite and impossible moved.
     *
     * CAVEAT (unchanged, and now overdue): the right value comes from THIS install's own
     * net_wpm distribution, not from a document or an estimate. When real play data exists,
     * query the p99.9 of net_wpm and re-tune. Do NOT tighten this again on intuition -- that
     * is exactly what produced the bug above.
     */
    public const MAX_CHARS_PER_SECOND = 20.0;

    /**
     * Slack (seconds) allowed between the server's own elapsed clock and the duration a
     * client claims, covering latency and the gap between the last keystroke and the
     * request landing. Beyond this the claim describes time that never passed.
     *
     * Capped further by SLACK_FRACTION so it stays proportional to the session.
     */
    private const DURATION_SLACK_SECONDS = 30.0;

    /**
     * Slack may never exceed this share of the session's nominal length.
     *
     * A flat allowance is fine for a two-minute test but is most of a 30-second one, which
     * is what left ~500 characters (a forged 200 WPM) claimable on short sessions. At 0.35
     * a genuine player still clears easily -- by the time they submit, the clock really has
     * run -- while a result posted the instant the text is issued tops out near 83 WPM,
     * below the 150 WPM that maxes out Clan War scoring.
     */
    private const SLACK_FRACTION = 0.35;

    /**
     * Remember that a session just started, with the text the server actually issued.
     *
     * $tabKey scopes the record to ONE open tab (see MAX_TRACKED_SESSIONS). It is a
     * server-assigned identifier, never a client-supplied one: a client that could choose
     * its own key could point a forged submission at whichever issued session suited it,
     * which is precisely the check this class exists to make.
     */
    public function start(string $mode, string $subMode, string $text, string $tabKey = 'default'): void
    {
        $sessions = $this->all();

        $sessions[$tabKey] = [
            'mode' => $mode,
            'sub_mode' => $subMode,
            'text_length' => mb_strlen($text),
            'started_at' => microtime(true),
        ];

        // Oldest-first eviction once past the cap. Sorting by started_at rather than trusting
        // insertion order, because a tab that merely restarts rewrites its own entry in place.
        if (count($sessions) > self::MAX_TRACKED_SESSIONS) {
            uasort($sessions, fn ($a, $b) => $a['started_at'] <=> $b['started_at']);
            $sessions = array_slice($sessions, -self::MAX_TRACKED_SESSIONS, null, true);
        }

        session()->put(self::SESSION_KEY, $sessions);
    }

    /** Every tracked tab session, normalised to an array of well-formed per-tab entries. */
    private function all(): array
    {
        $data = session()->get(self::SESSION_KEY);

        if (! is_array($data)) {
            return [];
        }

        // Keep ONLY well-formed per-tab entries (an array carrying 'started_at'). This is the
        // one place every consumer reads through, so filtering here protects all of them --
        // in particular start()'s eviction uasort($a['started_at']), which assumed every value
        // was an array. A leftover record from the pre-per-tab format (a single flat record
        // keyed by 'mode'/'sub_mode'/... whose VALUES are scalars), or any corruption, would
        // otherwise reach that sort as a string and crash with
        // "Cannot access offset of type string on string". Dropping such garbage is correct:
        // it isn't a valid tab session, and start() writes the cleaned map back, self-healing.
        return array_filter(
            $data,
            static fn ($entry) => is_array($entry) && isset($entry['started_at']),
        );
    }

    /** This tab's session facts, or null when none was ever started. */
    public function current(string $tabKey = 'default'): ?array
    {
        $data = $this->all()[$tabKey] ?? null;

        return is_array($data) && isset($data['started_at']) ? $data : null;
    }

    /**
     * Backdate the active session's start time by N seconds.
     *
     * For tests only. A real player spends the session actually typing, so by the time the
     * result arrives the server clock has genuinely advanced; a test calls saveResult()
     * immediately, which otherwise looks exactly like an automated forgery. This lets a
     * test simulate the time a human would really have spent.
     */
    public function backdate(float $seconds, ?string $tabKey = null): void
    {
        $sessions = $this->all();

        // No key given: backdate every tracked tab. Tests call this without knowing the key
        // the component generated, and a test that opened two tabs means both of them.
        $keys = $tabKey === null ? array_keys($sessions) : [$tabKey];

        foreach ($keys as $key) {
            if (isset($sessions[$key]['started_at'])) {
                $sessions[$key]['started_at'] -= $seconds;
            }
        }

        session()->put(self::SESSION_KEY, $sessions);
    }

    /**
     * Drop THIS TAB's session so one issued text can only be submitted once (no replay).
     *
     * Scoped to the tab on purpose: clearing every session here would re-introduce the bug
     * this keying fixes, with one tab's submission invalidating another tab's open test.
     */
    public function clear(string $tabKey = 'default'): void
    {
        $sessions = $this->all();

        unset($sessions[$tabKey]);

        if ($sessions === []) {
            session()->forget(self::SESSION_KEY);

            return;
        }

        session()->put(self::SESSION_KEY, $sessions);
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
     *  - physical: seconds x peak human cps;
     *  - textual: the issued text length (+ tolerance) for the fixed-length modes, since
     *    `words` and `survival` end when the text does and cannot exceed it.
     *
     * `time` mode loops its text, so only the physical ceiling applies there.
     *
     * The physical ceiling is measured against however long the server has ACTUALLY held
     * the session open, not the nominal duration. Otherwise a 120-second war slot submitted
     * the instant it opened would allow 1800 characters "typed" in no real time at all --
     * exactly 150 WPM, which is the ratio that maxes out a Clan War point ceiling.
     *
     * $elapsedOverride and $windowSeconds exist for Clan War, where a session may legitimately
     * span several mounts. This class measures elapsed per TAB, and a refresh mints a new tab
     * key -- so on a resumed attempt the tab clock reads near zero while the player carries
     * hundreds of genuinely typed characters, and an honest resumer would be refused. The war
     * path passes the seconds since the ATTEMPT was anchored (which only ever grows) and that
     * attempt's total wall budget in place of the nominal duration. Solo passes neither and is
     * byte-for-byte unaffected.
     */
    public function maxPlausibleChars(
        string $mode,
        float $durationSeconds,
        string $tabKey = 'default',
        ?float $elapsedOverride = null,
        ?float $windowSeconds = null,
    ): ?int {
        $session = $this->current($tabKey);

        if ($session === null) {
            return null;
        }

        $window = $windowSeconds ?? $durationSeconds;

        // The window is bounded by real elapsed time PLUS slack, so an automated client
        // cannot claim a full-length session that never actually ran. The slack keeps
        // honest submissions safe when the request lands right after the last keystroke,
        // but it is capped at a FRACTION of the session: a flat 30 seconds is most of a
        // 30-second test, which left ~500 characters claimable and a forged 200 WPM
        // reachable. Proportional slack keeps short sessions tight and long ones forgiving.
        // Slack stays proportional to the SCORED duration, never to the window: the window is
        // only a cap on how much wall clock the attempt may span, and letting it widen the
        // slack too would hand a war slot a looser ceiling than the same solo test gets.
        $elapsed = $elapsedOverride ?? $this->elapsedSeconds($tabKey);
        $slack = min(self::DURATION_SLACK_SECONDS, $durationSeconds * self::SLACK_FRACTION);

        $realSeconds = $elapsed === null
            ? $window
            : min($window, $elapsed + $slack);

        $physical = (int) ceil($realSeconds * self::MAX_CHARS_PER_SECOND) + self::CHAR_TOLERANCE;

        if ($mode === 'time') {
            return $physical;
        }

        $textual = (int) ceil($session['text_length'] * self::TEXT_LENGTH_TOLERANCE_FACTOR)
            + self::CHAR_TOLERANCE;

        return min($physical, $textual);
    }

    /**
     * Does the client claim more time than has actually passed since the text was issued?
     *
     * An over-claimed duration normally hurts the sender (it lowers WPM), which is why the
     * claim is otherwise taken at face value. Clan War survival inverts that: points there
     * scale with `duration_seconds`, so "I survived 9999 seconds" buys the full 150-point
     * ceiling. Bounding the claim by the server's own clock removes that payoff without
     * touching honest sessions, which can only ever claim LESS time than has elapsed.
     */
    public function claimsMoreTimeThanElapsed(float $claimedSeconds, string $tabKey = 'default'): bool
    {
        $elapsed = $this->elapsedSeconds($tabKey);

        if ($elapsed === null) {
            return false;
        }

        return $claimedSeconds > $elapsed + self::DURATION_SLACK_SECONDS;
    }

    /** Seconds the server has actually held this session open, or null if none is active. */
    public function elapsedSeconds(string $tabKey = 'default'): ?float
    {
        $session = $this->current($tabKey);

        if ($session === null) {
            return null;
        }

        return max(0.0, microtime(true) - (float) $session['started_at']);
    }

    /**
     * Does the submitted session match the one the server issued?
     *
     * Guards against a client that switches mode/sub-mode after receiving the text (for
     * instance taking an easy 15-second test and submitting it as a 120-second result).
     */
    public function matchesIssuedSession(string $mode, string $subMode, string $tabKey = 'default'): bool
    {
        $session = $this->current($tabKey);

        if ($session === null) {
            return false;
        }

        return $session['mode'] === $mode && (string) $session['sub_mode'] === (string) $subMode;
    }
}
