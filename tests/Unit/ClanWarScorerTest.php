<?php

use App\Models\TypingResult;
use App\Services\ClanWarModeCatalog;
use App\Services\ClanWarScorer;

/*
|--------------------------------------------------------------------------
| score() = ceiling[mode] x performanceRatio x accuracyMultiplier. Fungsi
| murni: kita rakit TypingResult di memori (tanpa DB) lalu cek angkanya.
| points = ceiling * min(1, wpm/150) * (0.5 + 0.5*acc/100), dibulatkan 2 desimal.
|--------------------------------------------------------------------------
*/

function scoreResult(float $accuracy, float $netWpm, float $durationSeconds): TypingResult
{
    $result = new TypingResult;
    $result->accuracy = $accuracy;
    $result->net_wpm = $netWpm;
    $result->duration_seconds = $durationSeconds;

    return $result;
}

it('memberi 0 poin untuk mode/config yang bukan war mode', function () {
    $result = scoreResult(100.0, 150.0, 60.0);

    expect(ClanWarScorer::score('words', '999', $result))->toBe(0.0);
});

it('memberi poin ceiling penuh saat WPM mencapai skala dan akurasi sempurna', function () {
    // time/60 ceiling 100; WPM 150 (= WPM_SCALE) -> ratio 1.0; akurasi 100 -> mult 1.0.
    $result = scoreResult(100.0, ClanWarModeCatalog::WPM_SCALE, 60.0);

    expect(ClanWarScorer::score('time', '60', $result))->toBe(100.0);
});

it('menjepit performanceRatio di 1.0 saat WPM melampaui skala', function () {
    // WPM 300 (2x skala) tak boleh menggandakan poin -- tetap di ceiling.
    $result = scoreResult(100.0, 300.0, 60.0);

    expect(ClanWarScorer::score('time', '60', $result))->toBe(100.0);
});

it('menskalakan poin secara linear terhadap WPM di bawah skala', function () {
    // WPM 75 = separuh skala -> ratio 0.5; ceiling 100, akurasi 100 -> 50 poin.
    $result = scoreResult(100.0, 75.0, 60.0);

    expect(ClanWarScorer::score('time', '60', $result))->toBe(50.0);
});

it('memakai durasi bertahan (bukan WPM) untuk mode survival', function () {
    // survival/hard ceiling 150; bertahan 90 detik (= SURVIVAL_SECONDS_SCALE) -> ratio 1.0.
    $result = scoreResult(100.0, 0.0, ClanWarModeCatalog::SURVIVAL_SECONDS_SCALE);

    expect(ClanWarScorer::score('survival', 'hard', $result))->toBe(150.0);
});

it('menerapkan pengali akurasi 0.5x pada akurasi nol', function () {
    // ceiling 100, WPM penuh (ratio 1.0), akurasi 0 -> mult 0.5 -> 50 poin.
    $result = scoreResult(0.0, ClanWarModeCatalog::WPM_SCALE, 60.0);

    expect(ClanWarScorer::score('time', '60', $result))->toBe(50.0);
});

it('membulatkan poin ke dua desimal', function () {
    $result = scoreResult(93.0, 77.0, 60.0);

    $score = ClanWarScorer::score('time', '60', $result);

    expect($score)->toBe(round($score, 2));
});

/*
|--------------------------------------------------------------------------
| breakdown(): faktor-faktor yang DIKALIKAN score(), supaya layar hasil bisa
| menjelaskan kenapa sebuah attempt bernilai segitu tanpa menghitung ulang
| rumusnya sendiri (yang akan jadi sumber kebenaran kedua, bebas menyimpang
| dari angka yang benar-benar ditulis ke clan_war_mode_claims.points).
|--------------------------------------------------------------------------
*/

it('membeberkan faktor yang menghasilkan poin', function () {
    // ceiling 100; WPM 75 = separuh skala -> 0.5; akurasi 96 -> 0.98.
    $b = ClanWarScorer::breakdown('time', '60', scoreResult(96.0, 75.0, 60.0));

    expect($b['ceiling'])->toBe(100)
        ->and($b['basis'])->toBe('wpm')
        ->and($b['basis_value'])->toBe(75.0)
        ->and($b['basis_scale'])->toBe(150)
        ->and($b['performance_ratio'])->toBe(0.5)
        ->and($b['accuracy_multiplier'])->toBe(0.98)
        ->and($b['points'])->toBe(49.0);
});

it('memakai durasi, bukan wpm, sebagai dasar rincian survival', function () {
    // Kalau layar hasil menulis "kecepatan ... wpm" untuk survival, ia menyatakan sesuatu
    // yang salah tentang mode ber-ceiling tertinggi di game ini.
    $b = ClanWarScorer::breakdown('survival', 'hard', scoreResult(100.0, 0.0, 45.0));

    expect($b['basis'])->toBe('duration')
        ->and($b['basis_value'])->toBe(45.0)
        ->and($b['basis_scale'])->toBe(90)
        ->and($b['performance_ratio'])->toBe(0.5);
});

it('mengembalikan null untuk mode yang bukan war mode', function () {
    expect(ClanWarScorer::breakdown('words', '999', scoreResult(100.0, 150.0, 60.0)))->toBeNull();
});

it('menjaga score() dan breakdown() tak pernah berbeda', function () {
    $result = scoreResult(93.0, 77.0, 60.0);

    expect(ClanWarScorer::score('time', '60', $result))
        ->toBe(ClanWarScorer::breakdown('time', '60', $result)['points']);
});
