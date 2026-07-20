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
        // LanguageSeeder & TextSeeder ikut dihapus bersama tabel `languages`/`texts`:
        // teks latihan kini dirakit TextGeneratorService dari wordlist JSON, bukan
        // dibaca dari DB, jadi keduanya mengisi tabel yang tak pernah dibaca siapa pun.
        $this->call([
            DummyDataSeeder::class,
        ]);

        User::factory()->admin()->create([
            'username' => 'TestUser',
            'email' => 'test@example.com',
        ]);

        // User dummy + hasil typing untuk testing (login via /dev-login di env lokal).
        if (app()->environment('local')) {
            $this->call(DummyUserSeeder::class);
            $this->call(DummyMultiplayerSeeder::class);
            $this->call(DummyClanSeeder::class);
        }
    }
}
