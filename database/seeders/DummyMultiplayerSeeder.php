<?php

namespace Database\Seeders;

use App\Models\MultiplayerMatchHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeder for local-environment TESTING.
 *
 * Fills the dummy user's multiplayer_match_history (same as DummyUserSeeder:
 * dummy@uetype.test) so the Multiplayer tab on /stats is populated right away without
 * running many real races.
 *
 * Log in as this user via the dev route: /dev-login (only active when APP_ENV=local).
 */
class DummyMultiplayerSeeder extends Seeder
{
    public function run(): void
    {
        // Use the same dummy user as DummyUserSeeder (idempotent by email).
        $user = User::firstOrCreate(
            ['email' => 'dummy@uetype.test'],
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

        // Clear this user's old history so the seeder can be re-run cleanly.
        MultiplayerMatchHistory::where('user_id', $user->id)->delete();

        // ~18 races spread over the last 20 days; WPM slowly rising (so progress shows).
        $races = 18;

        for ($i = 0; $i < $races; $i++) {
            // Opponent count varies 2-5 players.
            $playerCount = mt_rand(2, 5);

            // Realistic placement: wins fairly often but not always; occasional DNF.
            $place = mt_rand(1, $playerCount);
            $gaveUp = mt_rand(1, 10) === 1; // ~10% give up/DNF.

            // WPM tends to rise over time + a little noise (matches DummyUserSeeder).
            $baseWpm = 45 + ($i * 1.3);
            $wpm = (int) round(max(20, $baseWpm + mt_rand(-7, 7)));

            $accuracy = round(mt_rand(870, 995) / 10, 2); // 87.0% - 99.5%

            // Gave up -> use the 999 sentinel (matches giveUp() in MultiplayerLobby);
            // finished -> a reasonable elapsed time based on text length & WPM.
            $finishedSeconds = $gaveUp ? 999 : mt_rand(18, 55);

            // EXP matches the User::addExp() formula: correctChars estimated from WPM & time.
            $correctChars = $gaveUp ? mt_rand(20, 80) : (int) round(($wpm / 60) * $finishedSeconds * 5);
            $accuracyMultiplier = 0.5 + 0.5 * ($accuracy / 100);
            $xpEarned = (int) round($correctChars * 0.1 * $accuracyMultiplier);

            MultiplayerMatchHistory::create([
                'user_id' => $user->id,
                'room_code' => strtoupper(Str::random(6)),
                'place' => $place,
                'player_count' => $playerCount,
                'wpm' => $wpm,
                'accuracy' => $accuracy,
                'finished_time_seconds' => $finishedSeconds,
                'xp_earned' => $xpEarned,
                'created_at' => Carbon::now()->subDays($races - $i)->subMinutes(mt_rand(0, 600)),
                'updated_at' => Carbon::now()->subDays($races - $i),
            ]);
        }

        $this->command->info("Dummy multiplayer history ready: {$user->email} ({$races} races). Log in via /dev-login then open /stats, Multiplayer tab.");
    }
}
