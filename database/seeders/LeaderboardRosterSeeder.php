<?php

namespace Database\Seeders;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fills the public leaderboard with a believable roster, so a demo does not open onto an
 * empty board.
 *
 * Why this exists next to LeaderboardDemoSeeder: that one creates a SINGLE eligible player,
 * which proves the eligibility gate works but still leaves the board looking like one row
 * and nine blanks. Presenting needs a populated top-10 on whichever tab gets clicked, and
 * the board filters by mode + config + LANGUAGE + timeframe -- so a roster that only covers
 * `time/30/en` is empty the moment someone clicks `words` or `ID`.
 *
 * What "believable" means here, concretely:
 *  - Every player clears TypingResult::LEADERBOARD_MIN_TYPING_SECONDS (30 min accumulated),
 *    because the board's own subquery filters on it. Seeded rows that miss it are invisible,
 *    which historically read as "the seeder is broken".
 *  - Speeds sit in a human band and are derived from each player's own skill level, so the
 *    board reads as a plausible spread rather than ten identical rows.
 *  - Every result satisfies the same arithmetic the app enforces elsewhere: correct_chars is
 *    derived FROM net_wpm and duration (not invented independently), accuracy stays under
 *    100, and raw_wpm >= net_wpm. A demo board full of internally-contradictory rows is the
 *    kind of thing someone notices mid-presentation.
 *
 * Deliberately NOT a bypass of anti-cheat: these rows are written straight to the table by an
 * offline seeder, the way any fixture is. Nothing here posts to saveResult(), no guard is
 * loosened, and every seeded speed stays inside the human band the validators already accept
 * -- so nothing on the demo board is a score the live path would have refused.
 *
 * Local/demo only. Guarded in run() and idempotent: re-running replaces this roster instead
 * of stacking a second copy on top of it.
 */
class LeaderboardRosterSeeder extends Seeder
{
    /** Marks the accounts this seeder owns, so re-running can clear exactly them. */
    private const EMAIL_DOMAIN = '@roster.uetype.test';

    /**
     * Board dimensions, mirrored from resources/views/livewire/leaderboard.blade.php.
     *
     * Kept as one map because the board is a MATRIX: a roster covering only the default tab
     * leaves every other tab empty, which is exactly the "kosong" this seeder exists to fix.
     */
    private const CONFIGS = [
        'time' => ['15', '30', '60', '120'],
        'words' => ['10', '25', '50', '100'],
        'survival' => ['easy', 'medium', 'hard'],
    ];

    /** Both typed-text languages: the board filters on it, so `ID` needs its own rows. */
    private const LANGUAGES = ['en', 'id'];

    /** How long a survival run lasts at each difficulty (seconds) -- survival ranks by TIME. */
    private const SURVIVAL_DURATIONS = [
        'easy' => [95, 240],
        'medium' => [70, 170],
        'hard' => [45, 110],
    ];

    /**
     * The roster. Names are obviously fictional handles, and `skill` is the player's centre
     * of gravity in WPM -- every result is drawn around it, so each account keeps a
     * recognisable identity across tabs instead of being random noise.
     *
     * The spread (58 to 132) is intentional: a board where everyone types 120 looks seeded.
     * The top of the range stays well under the ~180+ band that would raise eyebrows, and
     * far under the anti-cheat ceilings.
     */
    private const ROSTER = [
        ['username' => 'kanaya', 'skill' => 132],
        ['username' => 'rifqi_dev', 'skill' => 124],
        ['username' => 'MochaKeys', 'skill' => 118],
        ['username' => 'tigapagi', 'skill' => 112],
        ['username' => 'Elang', 'skill' => 106],
        ['username' => 'nadia.pw', 'skill' => 101],
        ['username' => 'bayu_type', 'skill' => 96],
        ['username' => 'Cendana', 'skill' => 90],
        ['username' => 'putri_ao', 'skill' => 84],
        ['username' => 'gilangg', 'skill' => 79],
        ['username' => 'Senja', 'skill' => 73],
        ['username' => 'dimasrk', 'skill' => 68],
        ['username' => 'anindya', 'skill' => 62],
        ['username' => 'rekaaa', 'skill' => 58],
    ];

    public function run(): void
    {
        // Production is allowed, but never by accident.
        //
        // These accounts are indistinguishable from real players once they are on the public
        // board -- that is the entire point of the fixture, and also its risk. Running it on a
        // live board is a judgement call the operator has to make deliberately, so production
        // requires an explicit confirmation (or --force for a scripted deploy) rather than
        // inheriting the same one-liner that is harmless locally.
        if (app()->environment('production') && ! $this->confirmedForProduction()) {
            $this->command->warn('LeaderboardRosterSeeder cancelled.');

            return;
        }

        // Deterministic output: the same board every run, so a rehearsed demo does not
        // reshuffle between the rehearsal and the presentation.
        mt_srand(20260727);

        $this->clearPreviousRoster();

        $created = 0;
        $rows = 0;

        foreach (self::ROSTER as $index => $person) {
            $user = $this->createUser($person, $index);
            $rows += $this->seedResultsFor($user, $person['skill']);
            $created++;
        }

        $this->command->info("Leaderboard roster ready: {$created} players, {$rows} results across all tabs/configs/languages.");
        $this->command->info('Every player clears the '.(TypingResult::LEADERBOARD_MIN_TYPING_SECONDS / 60).'-minute eligibility gate, so the board is populated on every tab.');

        if (app()->environment('production')) {
            $this->command->warn('These are DEMO accounts on the live leaderboard. Remove them when the demo is over:');
            $this->command->warn('  php artisan db:seed --class=RemoveLeaderboardRosterSeeder --force');
        }
    }

    /**
     * Explicit go-ahead before writing fixtures to a live board.
     *
     * `--force` is honoured because that is the flag Laravel already uses for "yes, in
     * production, I mean it", and a deploy script cannot answer a prompt.
     */
    private function confirmedForProduction(): bool
    {
        if ($this->command->option('force')) {
            return true;
        }

        $this->command->warn('You are about to add 14 FAKE players to the PRODUCTION leaderboard.');
        $this->command->warn('They are visible to every visitor and rank alongside real players.');

        return $this->command->confirm('Continue?', false);
    }

    /**
     * Remove a previous run of THIS seeder (accounts + their results), so re-running refreshes
     * the roster rather than doubling it.
     *
     * Scoped by the marker domain: other seeders' users and any real account are untouched.
     */
    private function clearPreviousRoster(): void
    {
        $ids = User::where('email', 'like', '%'.self::EMAIL_DOMAIN)->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($ids) {
            TypingResult::whereIn('user_id', $ids)->delete();
            User::whereIn('id', $ids)->delete();
        });
    }

    private function createUser(array $person, int $index): User
    {
        return User::create([
            'username' => $person['username'],
            'email' => 'roster'.$index.self::EMAIL_DOMAIN,
            'google_id' => null,
            'avatar' => null,
            'highest_wpm' => 0,
            'total_xp' => 0,
            'is_admin' => false,
            'preferences' => null,
            // Staggered so the "last seen" column is not a wall of identical timestamps.
            'last_seen_at' => now()->subMinutes(mt_rand(1, 4320)),
        ]);
    }

    /**
     * Write one player's whole history: every mode, every config, both languages.
     *
     * Returns the number of rows written. Also updates the user's highest_wpm/total_xp so the
     * profile agrees with the board -- a demo where the two disagree invites the one question
     * nobody wants mid-presentation.
     */
    private function seedResultsFor(User $user, int $skill): int
    {
        $results = [];
        $totalXp = 0;
        $highestWpm = 0.0;
        $now = Carbon::now();

        foreach (self::LANGUAGES as $language) {
            foreach (self::CONFIGS as $mode => $configs) {
                foreach ($configs as $config) {
                    // Several attempts per bucket: the board takes each player's BEST, and a
                    // single attempt per bucket makes "personal best" meaningless.
                    for ($attempt = 0; $attempt < 3; $attempt++) {
                        $row = $this->buildResult($user->id, $mode, $config, $language, $skill, $now);

                        $results[] = $row;
                        $totalXp += $row['xp_earned'];

                        // Survival WPM is excluded from the headline record: it is typed under
                        // stamina pressure, matching how the app itself treats it.
                        if ($mode !== 'survival') {
                            $highestWpm = max($highestWpm, $row['net_wpm']);
                        }
                    }
                }
            }
        }

        // One insert per player rather than ~84 -- the seeder stays quick to re-run.
        TypingResult::insert($results);

        $user->update([
            'highest_wpm' => $highestWpm,
            'total_xp' => $totalXp,
        ]);

        return count($results);
    }

    /**
     * One result row, with every derived number consistent with the others.
     *
     * The ordering matters: net WPM and duration are chosen first, and correct_chars is then
     * derived FROM them. Inventing a character count independently is how seeded rows end up
     * describing a speed they do not actually represent.
     */
    private function buildResult(int $userId, string $mode, string $config, string $language, int $skill, Carbon $now): array
    {
        $duration = $this->durationFor($mode, $config);

        // Per-attempt variation around the player's skill, plus a small penalty on the
        // longest tests (stamina) and on survival (typed under pressure).
        $netWpm = $skill + (mt_rand(-70, 70) / 10);

        if (in_array($config, ['120', '100'], true)) {
            $netWpm -= mt_rand(2, 6);
        }

        if ($mode === 'survival') {
            $netWpm -= mt_rand(4, 9);
        }

        $netWpm = round(max(20.0, $netWpm), 2);

        // 100% is reserved for genuinely flawless runs; a board where everyone is perfect
        // reads as fake, so the band stops just short of it.
        $accuracy = round(mt_rand(913, 991) / 10, 2);

        // Raw >= net always: raw counts every keystroke, net only the correct ones.
        $rawWpm = round($netWpm / ($accuracy / 100), 2);

        // Derived from the pace actually claimed above (1 word = 5 chars).
        $correctChars = (int) round(($netWpm / 60) * $duration * 5);
        $incorrectChars = (int) round($correctChars * ((100 - $accuracy) / 100));

        $accuracyMultiplier = 0.5 + 0.5 * ($accuracy / 100);
        $xpEarned = (int) round($correctChars * 0.1 * $accuracyMultiplier);

        return [
            'user_id' => $userId,
            'mode' => $mode,
            'mode_config' => $config,
            'language' => $language,
            'net_wpm' => $netWpm,
            'raw_wpm' => $rawWpm,
            'accuracy' => $accuracy,
            'correct_chars' => $correctChars,
            'incorrect_chars' => $incorrectChars,
            'duration_seconds' => $duration,
            // Survival ranks by duration; score is the side stat (correct chars while alive).
            'score' => $mode === 'survival' ? (int) round($correctChars / 5) : null,
            'xp_earned' => $xpEarned,
            'ghost_data' => null,
            // 'clear' = publicly visible. The board hides pending/rejected rows, so anything
            // else here would seed an invisible board.
            'review_status' => TypingResult::REVIEW_CLEAR,
            'review_reason' => null,
            // No updated_at: typing_results carries created_at only (the row is written once
            // and never edited), so naming it here is a column that does not exist.
            'created_at' => $this->timestampFor($now),
        ];
    }

    /**
     * When this result was recorded.
     *
     * Roughly a third of every player's rows land TODAY, the rest across the past ~30 days.
     * That split is the point, not decoration: the board's "Daily" timeframe filters on
     * created_at >= startOfDay(), so a purely historical spread leaves Daily showing two or
     * three rows -- an empty-looking board, which is the exact thing this seeder exists to
     * prevent. Spreading today's rows over the past few hours keeps them from all sharing a
     * single suspicious timestamp.
     */
    private function timestampFor(Carbon $now): Carbon
    {
        if (mt_rand(1, 3) === 1) {
            // Earlier today: bounded by how far into the day we actually are, so a morning
            // demo cannot produce results timestamped in the afternoon.
            $minutesSinceMidnight = $now->diffInMinutes($now->copy()->startOfDay());

            return $now->copy()->subMinutes(mt_rand(0, max(1, (int) $minutesSinceMidnight)));
        }

        // 1 to 30 days back. Starting at one full day keeps history clear of the Daily window.
        return $now->copy()
            ->subDays(mt_rand(1, 30))
            ->subMinutes(mt_rand(0, 1439));
    }

    /** How long a run in this bucket lasted, in seconds. */
    private function durationFor(string $mode, string $config): float
    {
        if ($mode === 'time') {
            // Fixed by the mode itself.
            return (float) $config;
        }

        if ($mode === 'survival') {
            [$min, $max] = self::SURVIVAL_DURATIONS[$config];

            return (float) mt_rand($min, $max);
        }

        // Words: length is fixed, so duration is what varies with the typist. Indonesian
        // words run a little longer than English, but one blended estimate is enough for a
        // fixture -- ~5.9 characters per word including the space.
        $chars = ((int) $config) * 5.9;

        // Seconds at a mid-roster pace, jittered so equal-length runs are not identical.
        return round($chars / 5 / (mt_rand(75, 115) / 60), 1);
    }
}
