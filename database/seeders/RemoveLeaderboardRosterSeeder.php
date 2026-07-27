<?php

namespace Database\Seeders;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Removes the demo roster created by LeaderboardRosterSeeder.
 *
 * Exists because that seeder can now run in production: anything that puts fixtures on a live
 * leaderboard needs a way back off it, or "we'll clean it up later" becomes a permanent set of
 * fake players nobody remembers the origin of.
 *
 * Safe by construction. The delete is scoped to accounts whose email carries the roster's
 * marker domain, so it can only ever remove rows this project's own seeder created -- a real
 * player's account cannot match, and no result belonging to one is touched.
 */
class RemoveLeaderboardRosterSeeder extends Seeder
{
    /** Must stay in step with LeaderboardRosterSeeder::EMAIL_DOMAIN. */
    private const EMAIL_DOMAIN = '@roster.uetype.test';

    public function run(): void
    {
        $users = User::where('email', 'like', '%'.self::EMAIL_DOMAIN)->get(['id', 'username']);

        if ($users->isEmpty()) {
            $this->command->info('No demo roster found -- nothing to remove.');

            return;
        }

        $ids = $users->pluck('id');
        $resultCount = TypingResult::whereIn('user_id', $ids)->count();

        // Results first, then the accounts: one transaction so a failure cannot leave orphaned
        // rows pointing at users that no longer exist.
        DB::transaction(function () use ($ids) {
            TypingResult::whereIn('user_id', $ids)->delete();
            User::whereIn('id', $ids)->delete();
        });

        $this->command->info("Removed {$users->count()} demo players and {$resultCount} results.");
        $this->command->info('The leaderboard now shows real players only.');
    }
}
