<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\TextSeeder;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
        RoleSeeder::class,
        LanguageSeeder::class, // Pastikan ini jalan duluan
        TextSeeder::class,
        QuoteSeeder::class, // Kutipan untuk mode 'quote'
        ]);

        User::factory()->create([
            'username' => 'TestUser', // Ganti 'name' jadi 'username'
            'email' => 'test@example.com',
            'role_id' => 1, // Berikan role admin untuk user test ini
        ]);
    }
}
