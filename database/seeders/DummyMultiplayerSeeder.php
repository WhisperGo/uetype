<?php

namespace Database\Seeders;

use App\Models\MultiplayerMatchHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Seeder untuk keperluan TESTING di environment lokal.
 *
 * Mengisi multiplayer_match_history milik user dummy (sama dengan DummyUserSeeder:
 * dummy@uetype.test) supaya tab Multiplayer di halaman /stats langsung terisi tanpa
 * harus menjalankan balapan sungguhan berkali-kali.
 *
 * Login user ini lewat route dev: /dev-login (hanya aktif saat APP_ENV=local).
 */
class DummyMultiplayerSeeder extends Seeder
{
    public function run(): void
    {
        // Pakai user dummy yang sama dengan DummyUserSeeder (idempotent by email).
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

        // Bersihkan riwayat lama milik user ini agar seeder bisa diulang dengan bersih.
        MultiplayerMatchHistory::where('user_id', $user->id)->delete();

        // ~18 balapan tersebar 20 hari ke belakang; WPM perlahan naik (biar terlihat progres).
        $races = 18;

        for ($i = 0; $i < $races; $i++) {
            // Jumlah lawan bervariasi 2-5 pemain.
            $playerCount = mt_rand(2, 5);

            // Peringkat realistis: menang cukup sering tapi tak selalu; sesekali DNF.
            $place = mt_rand(1, $playerCount);
            $gaveUp = mt_rand(1, 10) === 1; // ~10% menyerah/DNF.

            // WPM cenderung naik seiring waktu + sedikit noise (selaras DummyUserSeeder).
            $baseWpm = 45 + ($i * 1.3);
            $wpm = (int) round(max(20, $baseWpm + mt_rand(-7, 7)));

            $accuracy = round(mt_rand(870, 995) / 10, 2); // 87.0% - 99.5%

            // Menyerah -> pakai sentinel 999 (selaras giveUp() di MultiplayerLobby);
            // selesai -> waktu tempuh wajar berdasarkan panjang teks & WPM.
            $finishedSeconds = $gaveUp ? 999 : mt_rand(18, 55);

            // EXP selaras rumus User::addExp(): correctChars diperkirakan dari WPM & waktu.
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

        $this->command->info("Riwayat multiplayer dummy siap: {$user->email} ({$races} balapan). Login via /dev-login lalu buka /stats tab Multiplayer.");
    }
}
