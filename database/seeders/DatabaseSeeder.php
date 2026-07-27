<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // LanguageSeeder & TextSeeder were removed along with the `languages`/`texts`
        // tables: practice text is now assembled by TextGeneratorService from a JSON
        // wordlist, not read from the DB, so both filled tables nobody ever read.
        $this->call([
            DummyDataSeeder::class,
        ]);

        // Non-local only: this is an ADMIN account with a guessable email, and it used to be
        // created unconditionally -- so seeding production handed out a privileged login to
        // anyone who tried test@example.com. Nothing here needs it outside development.
        if (! app()->environment('production')) {
            User::factory()->admin()->create([
                'username' => 'TestUser',
                'email' => 'test@example.com',
            ]);
        }

        // Dummy user + typing results for testing (log in via /dev-login in the local env).
        if (app()->environment('local')) {
            $this->call(DummyUserSeeder::class);
            $this->call(DummyMultiplayerSeeder::class);
            $this->call(DummyClanSeeder::class);
        }

        // Demo leaderboard roster: fake players so the public board is not empty during a
        // presentation. Runs in every environment INCLUDING production -- deliberately, and
        // the seeder itself asks for confirmation there (or takes --force).
        //
        // Remove it once the demo is over:
        //   php artisan db:seed --class=RemoveLeaderboardRosterSeeder --force
        $this->call(LeaderboardRosterSeeder::class);
    }
}
