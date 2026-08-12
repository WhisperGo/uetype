<?php

use App\Support\AchievementDefinitions;

/*
|--------------------------------------------------------------------------
| Sumber kebenaran achievement ada di kode (bukan DB). Tiap definisi punya
| closure check(array $stats): bool. Test menjaga bentuk struktur dan
| mengevaluasi tiap gerbang terhadap stat pemain yang lolos & yang belum.
|--------------------------------------------------------------------------
*/

/** Stat pemain lengkap dengan semua metrik di nol (belum membuka apa pun). */
function zeroStats(): array
{
    return [
        'highest_wpm' => 0,
        'total_tests' => 0,
        'level' => 1,
        'perfect_runs' => 0,
        'total_chars' => 0,
    ];
}

function definition(string $key): array
{
    foreach (AchievementDefinitions::all() as $def) {
        if ($def['key'] === $key) {
            return $def;
        }
    }

    throw new InvalidArgumentException("Unknown achievement: {$key}");
}

// Judul & deskripsi SENGAJA tak ada di sini -- keduanya hanya hidup di file lang,
// karena itulah satu-satunya tempat yang dibaca view. Kelengkapannya dijaga
// AchievementsPageTest, yang bisa membaca lang (test Unit tak punya aplikasi Laravel).
it('memberi tiap definisi field wajib dan check yang bisa dipanggil', function () {
    foreach (AchievementDefinitions::all() as $def) {
        expect($def)->toHaveKeys(['key', 'category', 'icon_value', 'icon_unit', 'check'])
            ->and($def['check'])->toBeCallable();
    }
});

it('menjaga key achievement tetap unik', function () {
    $keys = array_column(AchievementDefinitions::all(), 'key');

    expect($keys)->toHaveCount(count(array_unique($keys)));
});

it('memakai kategori yang terdaftar di daftar kategori UI', function () {
    $validCategories = array_column(AchievementDefinitions::categories(), 'key');

    foreach (AchievementDefinitions::all() as $def) {
        expect($validCategories)->toContain($def['category']);
    }
});

it('mengunci semua achievement untuk pemain baru (stat nol)', function () {
    $stats = zeroStats();

    foreach (AchievementDefinitions::all() as $def) {
        expect($def['check']($stats))->toBeFalse("{$def['key']} tak boleh terbuka pada stat nol");
    }
});

it('membuka Speed Demon tepat di ambang 100 WPM, bukan sebelumnya', function () {
    $check = definition('speed_demon')['check'];

    expect($check(['highest_wpm' => 99] + zeroStats()))->toBeFalse()
        ->and($check(['highest_wpm' => 100] + zeroStats()))->toBeTrue();
});

it('membuka Perfectionist setelah satu run sempurna', function () {
    $check = definition('perfectionist')['check'];

    expect($check(zeroStats()))->toBeFalse()
        ->and($check(['perfect_runs' => 1] + zeroStats()))->toBeTrue();
});

it('membuka achievement karakter sesuai ambang kumulatifnya', function () {
    $wordSmith = definition('word_smith')['check'];

    expect($wordSmith(['total_chars' => 49999] + zeroStats()))->toBeFalse()
        ->and($wordSmith(['total_chars' => 50000] + zeroStats()))->toBeTrue();
});

it('membuka achievement level sesuai ambangnya', function () {
    $legend = definition('legend')['check'];

    expect($legend(['level' => 49] + zeroStats()))->toBeFalse()
        ->and($legend(['level' => 50] + zeroStats()))->toBeTrue();
});
