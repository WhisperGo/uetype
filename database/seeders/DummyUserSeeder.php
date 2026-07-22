<?php

namespace Database\Seeders;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeder for local-environment TESTING.
 *
 * Creates one dummy user + a varied set of typing results (typing_results) so the profile
 * page, history, progress chart, and heatmap are populated right away without typing many
 * sessions by hand.
 *
 * Log in as this user via the dev-only route: /dev-login (only active when APP_ENV=local).
 */
class DummyUserSeeder extends Seeder
{
    public function run(): void
    {
        // Create / fetch the dummy user (idempotent by email).
        $user = User::firstOrCreate(
            [
                'email' => 'dummy@uetype.test',
            ],
            [
                'username' => 'DummyTyper',
                'google_id' => null,
                'avatar' => null,
                'highest_wpm' => 0,
                'total_xp' => 0,
                'is_admin' => false,
                'preferences' => null,
            ],
        );

        // Clear this user's old results so the seeder can be re-run cleanly.
        TypingResult::where('user_id', $user->id)->delete();

        $modes = [
            ['mode' => 'time', 'config' => '30'],
            ['mode' => 'time', 'config' => '60'],
            ['mode' => 'words', 'config' => '25'],
            ['mode' => 'words', 'config' => '50'],
            ['mode' => 'survival', 'config' => 'easy'],
            ['mode' => 'survival', 'config' => 'medium'],
            ['mode' => 'survival', 'config' => 'hard'],
        ];

        $totalXp = 0;
        $highestWpm = 0.0;

        // ~20 sessions spread over the last 20 days, WPM slowly rising (so the progress chart shows).
        $sessions = 20;

        for ($i = 0; $i < $sessions; $i++) {
            $pick = $modes[array_rand($modes)];

            // WPM tends to rise over time + a little random noise.
            $baseWpm = 45 + ($i * 1.2);
            $netWpm = round($baseWpm + mt_rand(-6, 6), 2);
            $netWpm = max(20, $netWpm);

            $accuracy = round(mt_rand(880, 995) / 10, 2); // 88.0% - 99.5%
            $rawWpm = round($netWpm / ($accuracy / 100), 2); // raw always >= net

            // Survival is scored by how long you survived (seconds), harder = shorter;
            // time uses the config duration; words gets a reasonable random duration.
            $duration = match ($pick['mode']) {
                'time' => (float) $pick['config'],
                'survival' => (float) match ($pick['config']) {
                    'hard' => mt_rand(30, 90),
                    'medium' => mt_rand(60, 150),
                    default => mt_rand(90, 240),
                },
                default => (float) mt_rand(20, 70),
            };

            // Estimated character count from net wpm & duration (1 word = 5 characters).
            $correctChars = (int) round(($netWpm / 60) * $duration * 5);
            $incorrectChars = (int) round($correctChars * ((100 - $accuracy) / 100));

            // Survival stores the clean word count in the score column; other modes null.
            $score = $pick['mode'] === 'survival' ? (int) round($correctChars / 5) : null;

            // EXP based on volume + a slight accuracy bonus — matches the TypingEngine formula.
            $accuracyMultiplier = 0.5 + 0.5 * ($accuracy / 100);
            $xpEarned = (int) round($correctChars * 0.1 * $accuracyMultiplier);
            $totalXp += $xpEarned;

            // The WPM record excludes survival (achieved under stamina pressure), matching
            // TypingEngine.
            if ($pick['mode'] !== 'survival') {
                $highestWpm = max($highestWpm, $netWpm);
            }

            TypingResult::create([
                'user_id' => $user->id,
                'mode' => $pick['mode'],
                'mode_config' => $pick['config'],
                // Weighted toward en for realism; id populates the Indonesian-language board.
                'language' => fake()->randomElement(['en', 'en', 'id']),
                'net_wpm' => $netWpm,
                'raw_wpm' => $rawWpm,
                'accuracy' => $accuracy,
                'correct_chars' => $correctChars,
                'incorrect_chars' => $incorrectChars,
                'duration_seconds' => $duration,
                'score' => $score,
                'xp_earned' => $xpEarned,
                'ghost_data' => null,
                'created_at' => Carbon::now()->subDays($sessions - $i)->subMinutes(mt_rand(0, 600)),
            ]);
        }

        // Sync the user's aggregates with the freshly seeded results.
        $user->total_xp = $totalXp;
        $user->highest_wpm = $highestWpm;
        $user->save();

        $this->command->info("Dummy user ready: {$user->email} ({$sessions} typing results). Log in via /dev-login.");
    }
}
