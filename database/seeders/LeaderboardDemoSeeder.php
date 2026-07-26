<?php

namespace Database\Seeders;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeder for DEMONSTRATING the leaderboard eligibility gate (TypingResult::LEADERBOARD_MIN_TYPING_SECONDS).
 *
 * Creates one user whose ACCUMULATED typing time comfortably clears the 30-minute (1800s)
 * threshold, so their results actually appear on the public board -- unlike DummyUserSeeder,
 * whose short sessions sum to well under the gate and stay held back ("keep typing").
 *
 * Use this to show the leaderboard working end-to-end: a real, eligible player at the top of
 * the time/words boards. Log in as this user via /dev-login (only active when APP_ENV=local).
 */
class LeaderboardDemoSeeder extends Seeder
{
    public function run(): void
    {
        // Create / fetch the demo user (idempotent by email).
        $user = User::firstOrCreate(
            [
                'email' => 'leaderboard@uetype.test',
            ],
            [
                'username' => 'LeaderboardPro',
                'google_id' => null,
                'avatar' => null,
                'highest_wpm' => 0,
                'total_xp' => 0,
                'is_admin' => false,
                'preferences' => null,
                'last_seen_at' => now(),
            ],
        );

        // Clear this user's old results so the seeder can be re-run cleanly.
        TypingResult::where('user_id', $user->id)->delete();

        // Each entry is a real, full-length session. The durations are chosen so their SUM
        // clears 1800s with margin: 40 sessions averaging ~60s = ~2400s of validated typing.
        $modes = [
            ['mode' => 'time', 'config' => '30', 'duration' => 30.0],
            ['mode' => 'time', 'config' => '60', 'duration' => 60.0],
            ['mode' => 'words', 'config' => '25', 'duration' => 45.0],
            ['mode' => 'words', 'config' => '50', 'duration' => 90.0],
        ];

        $sessions = 40;
        $totalXp = 0;
        $highestWpm = 0.0;
        $totalDuration = 0.0;

        for ($i = 0; $i < $sessions; $i++) {
            $pick = $modes[$i % count($modes)];
            $duration = $pick['duration'];
            $totalDuration += $duration;

            // A strong, believable pace that climbs a little over time (so the progress chart
            // reads as genuine improvement rather than a flat, script-like line).
            $baseWpm = 78 + ($i * 0.5);
            $netWpm = round($baseWpm + mt_rand(-5, 5), 2);

            $accuracy = round(mt_rand(940, 995) / 10, 2); // 94.0% - 99.5%
            $rawWpm = round($netWpm / ($accuracy / 100), 2);

            $correctChars = (int) round(($netWpm / 60) * $duration * 5);
            $incorrectChars = (int) round($correctChars * ((100 - $accuracy) / 100));

            $accuracyMultiplier = 0.5 + 0.5 * ($accuracy / 100);
            $xpEarned = (int) round($correctChars * 0.1 * $accuracyMultiplier);
            $totalXp += $xpEarned;

            $highestWpm = max($highestWpm, $netWpm);

            TypingResult::create([
                'user_id' => $user->id,
                'mode' => $pick['mode'],
                'mode_config' => $pick['config'],
                'language' => 'en',
                'net_wpm' => $netWpm,
                'raw_wpm' => $rawWpm,
                'accuracy' => $accuracy,
                'correct_chars' => $correctChars,
                'incorrect_chars' => $incorrectChars,
                'duration_seconds' => $duration,
                'score' => null,
                'xp_earned' => $xpEarned,
                'ghost_data' => null,
                // review_status defaults to 'clear' -> eligible for the board.
                'created_at' => Carbon::now()->subDays($sessions - $i)->subMinutes(mt_rand(0, 600)),
            ]);
        }

        $user->total_xp = $totalXp;
        $user->highest_wpm = $highestWpm;
        $user->save();

        $minutes = round($totalDuration / 60, 1);
        $this->command->info("Leaderboard demo user ready: {$user->email} ({$sessions} sessions, {$minutes} min of typing -- clears the 30-min gate). Log in via /dev-login.");
    }
}
