<?php

use App\Models\TypingResult;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\AchievementService;
use App\Support\AchievementDefinitions;

use function Pest\Laravel\actingAs;

/**
 * Halaman /achievements sebelumnya tak punya satu pun feature test -- yang ada hanya
 * AchievementDefinitionsTest (unit, murni menguji closure check). Berkas ini menutup
 * jalur yang sesungguhnya: dari data di typing_results sampai status di layar.
 */

/** Satu baris hasil ketik. Pola sama dengan bestScoreRow() di PersonalBestScopeTest. */
function achievementResult(User $user, string $mode, string $config, float $wpm): TypingResult
{
    return TypingResult::create([
        'user_id' => $user->id,
        'mode' => $mode,
        'mode_config' => $config,
        'net_wpm' => $wpm,
        'raw_wpm' => $wpm + 5,
        'accuracy' => 96,
        'correct_chars' => 300,
        'incorrect_chars' => 10,
        'duration_seconds' => 30,
    ]);
}

/** Status earned satu achievement, lewat jalur yang dipakai halaman sungguhan. */
function achievementEarned(User $user, string $key): bool
{
    $items = app(AchievementService::class)->evaluate($user)['items'];

    foreach ($items as $item) {
        if ($item['key'] === $key) {
            return $item['earned'];
        }
    }

    throw new InvalidArgumentException("Unknown achievement: {$key}");
}

// ---- Kelengkapan terjemahan ----

/**
 * Judul & deskripsi HANYA hidup di file lang -- view memakai
 * __('achievements.defs.<key>.title'). Definisi baru tanpa entri lang tak akan
 * error, ia cuma menampilkan string mentah "achievements.defs.foo.title" ke pemain.
 * LangParityTest menjaga en dan id sejajar satu sama lain, tapi tak tahu apa-apa
 * tentang daftar definisi -- celah itu yang ditutup di sini.
 */
it('has a translated title and description for every definition', function () {
    foreach (['en', 'id'] as $locale) {
        $lang = require base_path("lang/{$locale}/achievements.php");

        foreach (AchievementDefinitions::all() as $def) {
            expect($lang['defs'][$def['key']]['title'] ?? null)
                ->toBeString("Missing {$locale} title for {$def['key']}")
                ->and($lang['defs'][$def['key']]['description'] ?? null)
                ->toBeString("Missing {$locale} description for {$def['key']}");
        }
    }
});

// ---- Sumber rekor WPM ----

/**
 * users.highest_wpm hanya pernah NAIK dan tak pernah turun, jadi ia bisa bertahan
 * di atas baris yang sudah dihapus (mis. hasil `typing:audit`). Sumber kebenaran
 * achievement harus data yang sebenarnya, sama seperti seluruh halaman Stats.
 */
it('derives the WPM record from typing_results, not the denormalised column', function () {
    $user = User::factory()->create(['highest_wpm' => 150]);

    // Kolomnya bilang 150, tapi tak ada satu pun hasil yang mendukungnya.
    expect(achievementEarned($user, 'speed_demon'))->toBeFalse();
});

it('unlocks a WPM achievement from a stored result alone', function () {
    $user = User::factory()->create(['highest_wpm' => 0]);

    achievementResult($user, 'time', '30', 120);

    expect(achievementEarned($user, 'speed_demon'))->toBeTrue();
});

/**
 * Mencerminkan aturan yang sudah dipakai TypingEngine saat menulis kolomnya:
 * survival diraih di bawah tekanan stamina, bukan perbandingan setara.
 */
it('leaves survival out of the WPM record', function () {
    $user = User::factory()->create(['highest_wpm' => 0]);

    achievementResult($user, 'survival', 'hard', 180);

    expect(achievementEarned($user, 'speed_demon'))->toBeFalse();
});

// ---- Sifat unlock ----

/**
 * Status dulu dihitung ULANG tiap render dan mengabaikan baris yang sudah tercatat,
 * jadi lencana bisa hilang sendiri saat data menyusut. Ini yang membuat pemindahan
 * sumber WPM di atas aman: tak ada pemain yang kehilangan yang sudah diraih.
 */
it('keeps an achievement earned once it has been recorded', function () {
    $user = User::factory()->create(['highest_wpm' => 0]);

    UserAchievement::create([
        'user_id' => $user->id,
        'achievement_key' => 'speed_demon',
        'unlocked_at' => now(),
    ]);

    // Tak ada satu pun hasil yang tersisa -- statnya nol.
    expect(achievementEarned($user, 'speed_demon'))->toBeTrue();
});

// ---- Budget query ----

/**
 * computeStats() sengaja mengambil semua agregat dalam SATU query. Menambahkan
 * rekor WPM ke dalamnya tak boleh diam-diam menjadi query kedua.
 */
it('computes every stat in a single query', function () {
    $user = User::factory()->create();
    achievementResult($user, 'time', '30', 80);

    $service = app(AchievementService::class);

    expect(countQueries(fn () => $service->computeStats($user)))->toBe(1);
});

// ---- Kontrak markup ----

/**
 * KONTRAK MARKUP, bukan bukti visual: PEST tak bisa mengukur piksel maupun tinggi
 * kartu. Deskripsi terpanjang butuh 158px di ruang 127px, jadi `truncate` membuatnya
 * terpotong permanen -- halaman ini tak punya tooltip, sehingga teksnya benar-benar
 * hilang. Tingginya dipesan agar kartu tetap seragam saat teks memakai dua baris.
 */
it('lets long descriptions wrap instead of cutting them off', function () {
    $view = file_get_contents(resource_path('views/achievements/index.blade.php'));

    expect($view)->toContain('min-h-[2rem]')->not->toContain('text-muted truncate');
});

it('renders the achievements page with every definition listed', function () {
    $user = User::factory()->create();
    app()->setLocale('en');

    $response = actingAs($user)->get(route('achievements.index'))->assertOk();

    foreach (AchievementDefinitions::all() as $def) {
        $response->assertSee(__('achievements.defs.'.$def['key'].'.title'), false);
    }
});
