<?php

namespace App\Services;

use App\Models\TypingResult;
use App\Models\User;
use App\Models\UserAchievement;
use App\Support\AchievementDefinitions;
use Illuminate\Support\Carbon;

/**
 * Evaluates achievements lazily: computed from existing data (typing_results +
 * users) when a page loads, not a continuously running rule engine. Definitions
 * live in code (AchievementDefinitions); the user_achievements table only records
 * the first unlock (who earned what, and when).
 */
class AchievementService
{
    /**
     * Compute every metric the rules need in one cheap query pass.
     *
     * Ketiga agregat ditarik dalam SATU query (dulu: satu query per angka, padahal
     * tabel & filternya sama persis).
     *
     * @return array{highest_wpm:float, level:int, total_tests:int, total_chars:int, perfect_runs:int}
     */
    public function computeStats(User $user): array
    {
        $agg = TypingResult::where('user_id', $user->id)
            ->selectRaw('
                COUNT(*) as total_tests,
                COALESCE(SUM(correct_chars), 0) as total_chars,
                COALESCE(SUM(CASE WHEN accuracy >= 100 THEN 1 ELSE 0 END), 0) as perfect_runs
            ')
            ->first();

        return [
            'highest_wpm' => (float) ($user->highest_wpm ?? 0),
            'level' => $user->levelData()['level'],
            'total_tests' => (int) $agg->total_tests,
            'total_chars' => (int) $agg->total_chars,
            'perfect_runs' => (int) $agg->perfect_runs,
        ];
    }

    /**
     * List all achievements with earned status + unlock date. READ-ONLY.
     *
     * Dulu method ini juga MENULIS (UserAchievement::create) di tengah render halaman.
     * Dua akibatnya: (1) request GET jadi punya efek samping tulis, dan Livewire
     * me-render ulang tiap interaksi kecil, sehingga jalur tulis itu ikut dipanggil
     * berkali-kali; (2) achievement baru "terbuka" saat halaman kebetulan dibuka --
     * pemain yang tak pernah membuka /stats atau /achievements tak pernah tercatat.
     *
     * Sekarang pencatatan dilakukan di titik prestasinya benar-benar terjadi, lewat
     * syncUnlocks() (dipanggil setelah hasil ketik/balapan tersimpan).
     *
     * @return array{
     *   items: array<int, array{key:string,title:string,description:string,category:string,icon_value:string,icon_unit:string,earned:bool,unlocked_at:?Carbon}>,
     *   earned_count: int,
     *   total: int
     * }
     */
    public function evaluate(User $user): array
    {
        $stats = $this->computeStats($user);
        $definitions = AchievementDefinitions::all();

        // Catatan unlock yang sudah ada, untuk tanggal "unlocked at".
        $existing = UserAchievement::where('user_id', $user->id)
            ->get()
            ->keyBy('achievement_key');

        $items = [];
        $earnedCount = 0;

        foreach ($definitions as $def) {
            $earned = (bool) ($def['check'])($stats);

            if ($earned) {
                $earnedCount++;
            }

            $items[] = [
                'key' => $def['key'],
                'title' => $def['title'],
                'description' => $def['description'],
                'category' => $def['category'],
                'icon_value' => $def['icon_value'],
                'icon_unit' => $def['icon_unit'],
                'earned' => $earned,
                // null kalau syarat terpenuhi tapi belum sempat tercatat (mis. dicapai
                // sebelum perubahan ini). Tampilan tetap benar; syncUnlocks() akan
                // mencatatnya pada sesi berikutnya.
                'unlocked_at' => $earned ? $existing->get($def['key'])?->unlocked_at : null,
            ];
        }

        return [
            'items' => $items,
            'earned_count' => $earnedCount,
            'total' => count($definitions),
        ];
    }

    /**
     * Catat achievement yang BARU terpenuhi. Ini satu-satunya jalur tulis, dan
     * dipanggil di titik prestasinya terjadi (sesudah hasil ketik/balapan tersimpan),
     * bukan saat halaman dirender.
     *
     * Idempoten: yang sudah tercatat dilewati, jadi aman dipanggil berkali-kali.
     *
     * @return array<int, string> kunci achievement yang baru terbuka
     */
    public function syncUnlocks(User $user): array
    {
        $stats = $this->computeStats($user);

        $existing = UserAchievement::where('user_id', $user->id)
            ->pluck('achievement_key')
            ->all();

        $newlyUnlocked = [];
        $now = now();

        foreach (AchievementDefinitions::all() as $def) {
            if (in_array($def['key'], $existing, true)) {
                continue;
            }

            if (! ($def['check'])($stats)) {
                continue;
            }

            UserAchievement::create([
                'user_id' => $user->id,
                'achievement_key' => $def['key'],
                'unlocked_at' => $now,
            ]);

            $newlyUnlocked[] = $def['key'];
        }

        return $newlyUnlocked;
    }
}
