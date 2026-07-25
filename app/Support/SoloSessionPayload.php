<?php

namespace App\Support;

/**
 * One finished solo typing session, exactly as the client reported it.
 *
 * These twelve values arrive together, describe a single event, and are meaningless
 * apart -- the textbook case for one object instead of a parameter list.
 * TypingEngine::saveResult() used to take them positionally, which meant a call site
 * reading `saveResult(60000, 40, 40, [], [], [], 0, null, null, null, [], 40000)`:
 * padding nobody could decode without counting commas against the signature, and a
 * thirteenth field would have shifted every existing caller.
 *
 * This is a REPORT, not a verdict. Nothing here is trusted: the server recomputes
 * WPM/accuracy from the character counts (AntiCheatService), bounds the duration and the
 * counts against what it actually issued (SoloSessionGuard), and decides on its own
 * whether the run was abandoned. The only job of this class is to turn a loose array
 * into predictable types -- so that normalisation lives in ONE place instead of being
 * scattered across the top of a 260-line method.
 */
final class SoloSessionPayload
{
    private function __construct(
        public readonly float $durationMs,
        public readonly int $totalKeystrokes,
        public readonly int $correctKeystrokes,
        public readonly array $wpmHistory,
        public readonly array $rawHistory,
        public readonly array $missedChars,
        public readonly int $drainEventCount,
        public readonly ?float $ghostWpm,
        public readonly ?string $ghostLabel,
        public readonly ?int $ghostCharsAtFinish,
        /** Left `mixed`: TypingErrorInspector::sanitize() is the one that validates its shape. */
        public readonly mixed $errorEvents,
        public readonly float $maxIdleMs,
    ) {}

    /**
     * Build from the raw client array, filling in defaults for anything omitted.
     *
     * Every field is optional on purpose: an older cached bundle may not send the newest
     * ones (maxIdleMs was added after AFK detection), and a missing field must degrade
     * quietly rather than break the submission. Counts are floored at 0 here because a
     * negative keystroke count is not a smaller number, it is a nonsense one.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            durationMs: (float) ($data['durationMs'] ?? 0),
            totalKeystrokes: max(0, (int) ($data['totalKeystrokes'] ?? 0)),
            correctKeystrokes: max(0, (int) ($data['correctKeystrokes'] ?? 0)),
            wpmHistory: $data['wpmHistory'] ?? [],
            rawHistory: $data['rawHistory'] ?? [],
            missedChars: $data['missedChars'] ?? [],
            drainEventCount: (int) ($data['drainEventCount'] ?? 0),
            // isset() (not ??) so an explicit null stays null: "no ghost" and "a ghost
            // pacing 0 wpm" are different things, and only the latter is a number.
            ghostWpm: isset($data['ghostWpm']) ? (float) $data['ghostWpm'] : null,
            ghostLabel: isset($data['ghostLabel']) ? (string) $data['ghostLabel'] : null,
            ghostCharsAtFinish: isset($data['ghostCharsAtFinish']) ? (int) $data['ghostCharsAtFinish'] : null,
            errorEvents: $data['errorEvents'] ?? [],
            maxIdleMs: (float) ($data['maxIdleMs'] ?? 0),
        );
    }
}
