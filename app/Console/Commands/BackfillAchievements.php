<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Console\Command;

/**
 * Record every achievement a player already qualifies for but has no unlock row for.
 *
 * Needed because the result screen now announces whatever syncUnlocks() reports as new.
 * Plenty of accounts meet thresholds that were never written down -- from before
 * syncUnlocks() existed, and from levelling up in races, which until now recorded nothing.
 * Without this pass, the first solo session after the announcement ships would dump all of
 * them at once, telling a player they "just unlocked" things they earned weeks ago.
 *
 * Safe to re-run: syncUnlocks() only inserts rows that don't exist yet, so a second run
 * reports nothing.
 */
class BackfillAchievements extends Command
{
    protected $signature = 'achievements:backfill';

    protected $description = 'Record achievements players already qualify for, so they are not announced as new later.';

    public function handle(AchievementService $achievements): int
    {
        $users = 0;
        $recorded = 0;

        // Chunked: this walks every user and runs a couple of aggregates each, which is
        // fine as a one-off but should never be loaded into memory all at once.
        User::query()->chunkById(200, function ($chunk) use ($achievements, &$users, &$recorded) {
            foreach ($chunk as $user) {
                $users++;
                $recorded += count($achievements->syncUnlocks($user));
            }
        });

        $this->info("Checked {$users} user(s); recorded {$recorded} achievement(s).");

        if ($recorded === 0) {
            $this->line('Nothing was missing -- every qualifying achievement was already recorded.');
        }

        return self::SUCCESS;
    }
}
