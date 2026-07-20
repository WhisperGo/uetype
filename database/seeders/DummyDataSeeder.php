<?php

namespace Database\Seeders;

use App\Models\TypingResult;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class DummyDataSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        // 1. Buat 15 User Dummy Acak
        $users = [];
        for ($i = 0; $i < 15; $i++) {
            $users[] = User::create([
                'username' => $faker->unique()->userName,
                'email' => $faker->unique()->safeEmail,
                'google_id' => $faker->unique()->numerify('10#############'),
                'avatar' => 'https://api.dicebear.com/7.x/bottts/svg?seed='.$faker->word,
                'highest_wpm' => 0, // Akan di-update otomatis nanti
                'total_xp' => $faker->numberBetween(500, 25000),
            ]);
        }

        // Kumpulan konfigurasi game yang akan diisi datanya
        $modes = [
            ['mode' => 'time', 'configs' => ['15', '30', '60', '120']],
            ['mode' => 'words', 'configs' => ['10', '25', '50', '100']],
            ['mode' => 'survival', 'configs' => ['easy', 'medium', 'hard']],
        ];

        // 2. Generasikan Riwayat Typing Results untuk setiap user
        foreach ($users = User::all() as $user) {
            // Setiap user disimulasikan bermain antara 5 sampai 15 kali sesi ketik
            $sessionCount = rand(5, 15);
            $maxWpmRecorded = 0;

            for ($j = 0; $j < $sessionCount; $j++) {
                // Pilih mode dan sub-mode secara acak
                $selectedMode = $modes[array_rand($modes)];
                $selectedConfig = $selectedMode['configs'][array_rand($selectedMode['configs'])];

                // Tentukan performa dasar user secara acak (biar ada variasi peringkat)
                $baseWpm = rand(40, 110);
                $accuracy = rand(85, 100);

                $rawWpm = $baseWpm + rand(5, 15);
                $netWpm = (int) ($rawWpm * ($accuracy / 100));

                if ($netWpm > $maxWpmRecorded) {
                    $maxWpmRecorded = $netWpm;
                }

                $duration = $selectedMode['mode'] === 'survival'
                    ? rand(45, 300) // Detik bertahan jika survival
                    : (int) ($selectedConfig === 'words' ? rand(15, 45) : $selectedConfig);

                $correctChars = (int) (($netWpm * 5) * ($duration / 60));
                $incorrectChars = rand(0, 12);

                TypingResult::create([
                    'user_id' => $user->id,
                    'mode' => $selectedMode['mode'],
                    'mode_config' => $selectedConfig,
                    // Bobot ke en agar realistis; ID mengisi papan berbahasa Indonesia.
                    'language' => $faker->randomElement(['en', 'en', 'id']),
                    'net_wpm' => $netWpm,
                    'raw_wpm' => $rawWpm,
                    'accuracy' => $accuracy,
                    'correct_chars' => $correctChars,
                    'incorrect_chars' => $incorrectChars,
                    'duration_seconds' => $duration,
                    'xp_earned' => $correctChars * 2,
                    // Tarik timestamp acak (sebagian hari ini, sebagian beberapa hari lalu)
                    'created_at' => $faker->dateTimeBetween('-3 days', 'now'),
                ]);
            }

            // Update rekor tertinggi user di tabel profil
            $user->update(['highest_wpm' => $maxWpmRecorded]);
        }

        $this->command->info('Sukses menggenerasikan 15 users dan ratusan riwayat skor ketik!');
    }
}
