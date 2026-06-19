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
        $this->call([
            LanguageSeeder::class, // Pastikan ini jalan duluan
            TextSeeder::class,
            QuoteSeeder::class, // Kutipan untuk mode 'quote'
        ]);

        User::factory()->admin()->create([
            'username' => 'TestUser',
            'email' => 'test@example.com',
        ]);

        // User dummy + hasil typing untuk testing (login via /dev-login di env lokal).
        if (app()->environment('local')) {
            $this->call(DummyUserSeeder::class);
        }
    }
}
