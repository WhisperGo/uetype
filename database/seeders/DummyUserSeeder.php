<?php

namespace Database\Seeders;

use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeder untuk keperluan TESTING di environment lokal.
 *
 * Membuat satu user dummy + sejumlah hasil typing (typing_results) yang variatif
 * supaya halaman profil, riwayat, grafik progres, dan heatmap langsung terisi
 * tanpa perlu mengetik manual berkali-kali.
 *
 * Login user ini lewat route khusus dev: /dev-login (hanya aktif saat APP_ENV=local).
 */
class DummyUserSeeder extends Seeder
{
    public function run(): void
    {
        // Buat / ambil user dummy (idempotent berdasarkan email).
        $user = User::firstOrCreate(
            [
                'email' => 'dummy@uetype.test',
            ],
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

        // Bersihkan hasil lama milik user ini agar seeder bisa dijalankan ulang dengan bersih.
        TypingResult::where('user_id', $user->id)->delete();

        $modes = [
            ['mode' => 'time', 'config' => '30'],
            ['mode' => 'time', 'config' => '60'],
            ['mode' => 'words', 'config' => '25'],
            ['mode' => 'words', 'config' => '50'],
            ['mode' => 'survival', 'config' => 'easy'],
            ['mode' => 'survival', 'config' => 'medium'],
            ['mode' => 'survival', 'config' => 'hard'],
        ];

        $totalXp = 0;
        $highestWpm = 0.0;

        // ~20 sesi tersebar 20 hari ke belakang, WPM perlahan naik (biar grafik progres terlihat).
        $sessions = 20;

        for ($i = 0; $i < $sessions; $i++) {
            $pick = $modes[array_rand($modes)];

            // WPM cenderung naik seiring waktu + sedikit noise acak.
            $baseWpm = 45 + ($i * 1.2);
            $netWpm = round($baseWpm + mt_rand(-6, 6), 2);
            $netWpm = max(20, $netWpm);

            $accuracy = round(mt_rand(880, 995) / 10, 2); // 88.0% - 99.5%
            $rawWpm = round($netWpm / ($accuracy / 100), 2); // raw selalu >= net

            // Survival dinilai dari lama bertahan (detik), makin sulit makin pendek;
            // time pakai durasi config; words durasi acak wajar.
            $duration = match ($pick['mode']) {
                'time' => (float) $pick['config'],
                'survival' => (float) match ($pick['config']) {
                    'hard' => mt_rand(30, 90),
                    'medium' => mt_rand(60, 150),
                    default => mt_rand(90, 240),
                },
                default => (float) mt_rand(20, 70),
            };

            // Perkiraan jumlah karakter dari net wpm & durasi (1 kata = 5 karakter).
            $correctChars = (int) round(($netWpm / 60) * $duration * 5);
            $incorrectChars = (int) round($correctChars * ((100 - $accuracy) / 100));

            // Survival menyimpan jumlah kata bersih di kolom score; mode lain null.
            $score = $pick['mode'] === 'survival' ? (int) round($correctChars / 5) : null;

            // EXP berbasis volume + bonus akurasi tipis — selaras dengan rumus di TypingEngine.
            $accuracyMultiplier = 0.5 + 0.5 * ($accuracy / 100);
            $xpEarned = (int) round($correctChars * 0.1 * $accuracyMultiplier);
            $totalXp += $xpEarned;

            // Rekor WPM tak mencakup survival (dicapai di bawah tekanan stamina),
            // selaras dengan TypingEngine.
            if ($pick['mode'] !== 'survival') {
                $highestWpm = max($highestWpm, $netWpm);
            }

            TypingResult::create([
                'user_id' => $user->id,
                'text_id' => null,
                'mode' => $pick['mode'],
                'mode_config' => $pick['config'],
                // Bobot ke en agar realistis; ID mengisi papan berbahasa Indonesia.
                'language' => fake()->randomElement(['en', 'en', 'id']),
                'net_wpm' => $netWpm,
                'raw_wpm' => $rawWpm,
                'accuracy' => $accuracy,
                'correct_chars' => $correctChars,
                'incorrect_chars' => $incorrectChars,
                'duration_seconds' => $duration,
                'score' => $score,
                'xp_earned' => $xpEarned,
                'ghost_data' => null,
                'created_at' => Carbon::now()->subDays($sessions - $i)->subMinutes(mt_rand(0, 600)),
            ]);
        }

        // Selaraskan agregat user dengan hasil yang baru disemai.
        $user->total_xp = $totalXp;
        $user->highest_wpm = $highestWpm;
        $user->save();

        $this->command->info("User dummy siap: {$user->email} ({$sessions} hasil typing). Login via /dev-login.");
    }
}
