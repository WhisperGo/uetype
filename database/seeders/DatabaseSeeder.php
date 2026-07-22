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

        User::factory()->admin()->create([
            'username' => 'TestUser',
            'email' => 'test@example.com',
        ]);

        // Dummy user + typing results for testing (log in via /dev-login in the local env).
        if (app()->environment('local')) {
            $this->call(DummyUserSeeder::class);
            $this->call(DummyMultiplayerSeeder::class);
            $this->call(DummyClanSeeder::class);
        }
    }
}
