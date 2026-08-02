<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Everything the typing engine needs to know about the Clan War attempt it is rendering.
 *
 * A value object rather than loose properties on TypingEngine because these fields only make
 * sense together: `remainingSeconds` without `anchoredAt` is a number the client could have
 * invented, and `resumeProgress` without `isResume` reads as "start here" on a fresh attempt.
 * Built in one place (ClanWarAttempt::open) so there is exactly one derivation of each.
 */
final class ClanWarAttemptState
{
    public function __construct(
        /** The frozen text issued for this attempt -- identical on every re-entry. */
        public readonly string $text,

        /** When the server first opened this attempt. The clock the client cannot reset. */
        public readonly Carbon $anchoredAt,

        /** True when this mount is a re-entry (refresh, Back, or the grid's Resume button). */
        public readonly bool $isResume,

        /** Percent of the text already confirmed typed; 0 for a fresh attempt and survival. */
        public readonly int $resumeProgress,

        /** Seconds left on a `time` slot, counted from the anchor. Null for other modes. */
        public readonly ?int $remainingSeconds,

        /** Wall-clock seconds a survival slot has left. Null for other modes. */
        public readonly ?float $survivalBudgetRemaining,

        /** Total wall budget of this attempt, the window the character ceiling is measured over. */
        public readonly float $wallBudgetSeconds,
    ) {}

    /**
     * Whether the attempt has run out of wall clock and should submit whatever it has.
     *
     * Deliberately NOT a reason to drop the war lock: releasing it would drop the player into
     * a free solo session on a `?war_claim=` URL, which is the very reroll being closed. The
     * client reads this and finishes immediately instead, banking the progress actually earned.
     */
    public function isExpired(): bool
    {
        if ($this->remainingSeconds !== null) {
            return $this->remainingSeconds <= 0;
        }

        if ($this->survivalBudgetRemaining !== null) {
            return $this->survivalBudgetRemaining <= 0;
        }

        return false;
    }

    /**
     * The shape handed to the client (Livewire property -> Alpine config).
     *
     * Carries no timestamps: the client never needs the anchor itself, only what the server
     * derived from it, and shipping the anchor would invite a client to do its own arithmetic.
     *
     * @return array{mode: string, config: string, resume: bool, progress: int,
     *               remaining: int|null, budget: float|null, expired: bool}
     */
    public function toLockPayload(string $mode, string $config): array
    {
        return [
            'mode' => $mode,
            'config' => $config,
            'resume' => $this->isResume,
            'progress' => $this->resumeProgress,
            'remaining' => $this->remainingSeconds,
            'budget' => $this->survivalBudgetRemaining,
            'expired' => $this->isExpired(),
        ];
    }
}
