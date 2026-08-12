<?php

namespace App\Services;

/**
 * Could a survival run of this length have been survived by typing this little?
 *
 * Survival's stamina simulation runs entirely in the browser (resources/js/typing-game.js):
 * the server is told how long the player lasted, never watched them stay alive. Everywhere
 * else the project holds the line that the server recomputes what matters, and this was the
 * one scored quantity where it could not — so a client that simply removed its own death
 * condition could report any duration it liked.
 *
 * In solo that buys little (a longer run mostly lowers WPM). In CLAN WAR it is the whole
 * prize: `survival/hard` carries the highest ceiling on the grid (150) and its points scale
 * with duration up to 90 seconds. The cheapest points in the game sat behind the one number
 * the server could not check.
 *
 * The check here is a PHYSICAL FLOOR, not a simulation. Stamina drains with time and refills
 * per correct character, so surviving longer demands having typed more — and the least a
 * player could possibly have typed is computable, whatever they did in between. A run below
 * that floor did not happen as described.
 *
 * Deliberately conservative in the player's favour at every step (see minimumCorrectChars),
 * and deliberately NOT a rejection: like LongitudinalBaseline, an implausible run is held for
 * review, still saved and still visible on the player's own profile. A physical bound that
 * fires wrongly on one honest player costs more trust than the points it protects.
 */
class SurvivalPlausibility
{
    /**
     * The stamina presets, mirrored from SURVIVAL_PRESETS in resources/js/typing-game.js.
     *
     * A copy, and copies drift — but the alternative is worse. The client needs these values
     * every frame, and the server needs them to bound a claim; shipping them from one side to
     * the other would mean the client telling the server which rules to judge it by. They are
     * pinned by SurvivalPlausibilityTest, which reads the JS file and compares, so a change on
     * either side turns the suite red instead of silently loosening the floor.
     */
    private const PRESETS = [
        'easy' => ['sStart' => 120.0, 'graceSec' => 4.0, 'dStart' => 3.0, 'dAccel' => 0.11, 'refill' => 2.4],
        'medium' => ['sStart' => 100.0, 'graceSec' => 3.0, 'dStart' => 3.8, 'dAccel' => 0.20, 'refill' => 1.9],
        'hard' => ['sStart' => 70.0, 'graceSec' => 0.0, 'dStart' => 5.5, 'dAccel' => 0.40, 'refill' => 1.3],
    ];

    /**
     * The strongest drain reduction the burst shield can give (SHIELD_DRAIN_FACTOR in the JS).
     *
     * Applied to the WHOLE run here, which no real player achieves: holding the shield up
     * continuously needs ~7.1 correct characters every second (about 85 WPM sustained), and
     * any gap means undiscounted drain. Assuming it anyway is the point — the floor must be
     * one nobody honest can fall below.
     */
    private const BEST_CASE_DRAIN_FACTOR = 0.35;

    /**
     * Extra headroom under the computed floor before anything is flagged.
     *
     * The floor is already generous; this is for the parts that are not modelled at all
     * (rounding, the last partial second, a refill landing just before the clock stops).
     */
    private const TOLERANCE = 0.9;

    /**
     * A review reason when the run is below the physical floor, or null when it is possible.
     *
     * @param  string  $difficulty  easy|medium|hard
     */
    public function reviewReasonFor(string $difficulty, float $durationSeconds, int $correctChars): ?string
    {
        $required = $this->minimumCorrectChars($difficulty, $durationSeconds);

        if ($required === null) {
            return null;
        }

        return $correctChars < $required * self::TOLERANCE
            ? 'survival_impossible'
            : null;
    }

    /**
     * The fewest correct characters that could keep a player alive for this long.
     *
     * Every term leans towards the player:
     *   - drain during the opening grace period is ignored entirely, not merely softened;
     *   - drain for the rest of the run is taken at its best-case shielded rate;
     *   - starting stamina is treated as fully spendable;
     *   - the stamina CAP is ignored, though in play it wastes refill above sMax and so makes
     *     the real requirement higher than this.
     *
     * Returns null for an unknown difficulty rather than guessing — a mode this doesn't
     * understand must not be judged by it.
     */
    public function minimumCorrectChars(string $difficulty, float $durationSeconds): ?float
    {
        $preset = self::PRESETS[$difficulty] ?? null;

        if ($preset === null || $durationSeconds <= 0) {
            return null;
        }

        $grace = min($preset['graceSec'], $durationSeconds);

        // Total drain over (grace, duration] for a rate that rises linearly with time:
        // the integral of dStart + dAccel*t, evaluated between the two bounds.
        $drain = $preset['dStart'] * ($durationSeconds - $grace)
            + $preset['dAccel'] * (($durationSeconds ** 2) - ($grace ** 2)) / 2;

        $spendable = $drain * self::BEST_CASE_DRAIN_FACTOR - $preset['sStart'];

        // Short runs are covered by the starting bar alone; nothing had to be typed at all.
        return $spendable <= 0 ? 0.0 : $spendable / $preset['refill'];
    }
}
