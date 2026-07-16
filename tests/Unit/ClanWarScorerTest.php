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
    $result = new TypingResult();
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
