<?php

use App\Services\TypingErrorInspector;

/**
 * Seluruh keputusan yang bisa salah di fitur error marker ada di kelas ini: sanitasi
 * payload klien, pemetaan detik -> index grafik, dan rekonstruksi kata dari teks target.
 * Sisanya di view cuma kabel.
 */

// ---- sanitize(): batas kepercayaan terhadap payload klien ----

it('drops a payload that is not an array', function () {
    expect(TypingErrorInspector::sanitize('nope'))->toBe([])
        ->and(TypingErrorInspector::sanitize(null))->toBe([])
        ->and(TypingErrorInspector::sanitize(42))->toBe([]);
});

it('casts numeric strings to ints and keeps a well-formed event', function () {
    // Livewire mengirim angka JSON apa adanya, tapi bentuknya tak dijamin -- cast, jangan percaya.
    $clean = TypingErrorInspector::sanitize([
        ['second' => '3', 'index' => '17', 'actual' => 'r'],
    ]);

    expect($clean)->toBe([
        ['second' => 3, 'index' => 17, 'actual' => 'r'],
    ]);
});

it('keeps a skip event with a null actual', function () {
    // actual null = karakter DILEWATI (user menekan spasi, tak pernah ada tuts untuk char ini).
    // Ini state yang sah, bukan event cacat -- jangan ikut dibuang.
    $clean = TypingErrorInspector::sanitize([
        ['second' => 2, 'index' => 5, 'actual' => null],
    ]);

    expect($clean)->toBe([
        ['second' => 2, 'index' => 5, 'actual' => null],
    ]);
});

it('truncates a long actual down to one character', function () {
    // `actual` satu-satunya string dari klien yang benar-benar DIRENDER. handleInput
    // menjamin e.key.length === 1, jadi apa pun yang lebih panjang itu bukan dari
    // jalur normal. Potong ke 1 char: menutup payload raksasa sekaligus string sembarang.
    $clean = TypingErrorInspector::sanitize([
        ['second' => 1, 'index' => 0, 'actual' => '<script>alert(1)</script>'],
    ]);

    expect($clean[0]['actual'])->toBe('<');
});

it('drops events with negative coordinates', function () {
    $clean = TypingErrorInspector::sanitize([
        ['second' => -1, 'index' => 5, 'actual' => 'a'],
        ['second' => 2, 'index' => -5, 'actual' => 'b'],
    ]);

    expect($clean)->toBe([]);
});

it('drops entries that are not arrays', function () {
    $clean = TypingErrorInspector::sanitize([
        ['second' => 1, 'index' => 2, 'actual' => 'r'],
        'garbage',
        42,
        null,
    ]);

    expect($clean)->toHaveCount(1)
        ->and($clean[0]['index'])->toBe(2);
});

it('drops events missing required keys', function () {
    $clean = TypingErrorInspector::sanitize([
        ['index' => 2, 'actual' => 'r'],            // tanpa second
        ['second' => 1, 'actual' => 'r'],           // tanpa index
        ['second' => 'x', 'index' => 2],            // second bukan angka
    ]);

    expect($clean)->toBe([]);
});

it('caps the number of events it accepts', function () {
    $flood = array_fill(0, 900, ['second' => 1, 'index' => 0, 'actual' => 'a']);

    expect(TypingErrorInspector::sanitize($flood))
        ->toHaveCount(TypingErrorInspector::MAX_EVENTS);
});

// ---- inspect(): pemetaan sumbu-x + rekonstruksi kata ----

it('buckets errors into per-second counts aligned with the wpm samples', function () {
    // second yang ditangkap SUDAH merupakan index wpmHistory -- tanpa konversi.
    $result = TypingErrorInspector::inspect(
        [
            ['second' => 0, 'index' => 0, 'actual' => 'x'],
            ['second' => 0, 'index' => 1, 'actual' => 'y'],
            ['second' => 2, 'index' => 4, 'actual' => 'z'],
        ],
        'the quick',
        3,
    );

    expect($result['counts'])->toBe([2, 0, 1])
        ->and($result['counts'])->toHaveCount(3);
});

it('clamps an error from the unpushed final second onto the last sample', function () {
    // Tes `time` 30 detik cuma menghasilkan ~29 sampel: tick yang memicu finish() men-set
    // isFinished SEBELUM baris push jalan. Error di detik terakhir yang tak ter-push
    // di-clamp ke sampel terakhir, BUKAN dibuang -- membuangnya merusak invarian
    // "titik grafik === heatmap", dan detik terakhir justru tempat error kelelahan menumpuk.
    $result = TypingErrorInspector::inspect(
        [['second' => 3, 'index' => 0, 'actual' => 'x']],
        'the quick',
        3,
    );

    expect($result['counts'])->toBe([0, 0, 1])
        ->and($result['events'][0]['second'])->toBe(2)
        ->and($result['events'][0]['label'])->toBe(3);
});

it('keeps the marker total equal to the event total', function () {
    // KONTRAK KEJUJURAN: jumlah titik di grafik wajib sama dengan jumlah error, karena
    // heatmap tepat di bawahnya menghitung hal yang sama. Termasuk event yang ter-clamp.
    $events = [
        ['second' => 0, 'index' => 0, 'actual' => 'x'],
        ['second' => 1, 'index' => 1, 'actual' => null],
        ['second' => 1, 'index' => 2, 'actual' => 'y'],
        ['second' => 99, 'index' => 4, 'actual' => 'z'],   // ter-clamp
    ];

    $result = TypingErrorInspector::inspect($events, 'the quick', 3);

    expect(array_sum($result['counts']))->toBe(count($events))
        ->and($result['events'])->toHaveCount(count($events));
});

it('resolves the word, offset, expected and actual for a typo', function () {
    // 'the quick' -> index 5 = 'u', kata 'quick' mulai di 4, jadi offset 1.
    $result = TypingErrorInspector::inspect(
        [['second' => 1, 'index' => 5, 'actual' => 'r']],
        'the quick',
        3,
    );

    expect($result['events'][0])->toMatchArray([
        'second' => 1,
        'label' => 2,
        'word' => 'quick',
        'offset' => 1,
        'expected' => 'u',
        'actual' => 'r',
    ]);
});

it('marks a skipped character with a null actual but still resolves its word', function () {
    $result = TypingErrorInspector::inspect(
        [['second' => 1, 'index' => 6, 'actual' => null]],
        'the quick',
        3,
    );

    expect($result['events'][0])->toMatchArray([
        'word' => 'quick',
        'offset' => 2,
        'expected' => 'i',
        'actual' => null,
    ]);
});

it('degrades to counts and keystrokes when the text is missing', function () {
    // Sesi lama / test yang tak menyertakan textToType tetap harus merender: titik
    // tetap muncul & bisa diklik, panel cuma kehilangan konteks katanya.
    $result = TypingErrorInspector::inspect(
        [['second' => 1, 'index' => 5, 'actual' => 'r']],
        null,
        3,
    );

    expect($result['counts'])->toBe([0, 1, 0])
        ->and($result['events'][0])->toMatchArray([
            'second' => 1,
            'label' => 2,
            'word' => null,
            'offset' => null,
            'expected' => null,
            'actual' => 'r',
        ]);
});

it('returns empty counts when there are no wpm samples', function () {
    // Tanpa sampel tak ada sumbu x untuk ditempeli -- grafiknya memang kosong.
    $result = TypingErrorInspector::inspect(
        [['second' => 0, 'index' => 0, 'actual' => 'x']],
        'the quick',
        0,
    );

    expect($result['counts'])->toBe([])
        ->and($result['events'])->toBe([]);
});

it('returns zeroed counts for a clean run', function () {
    $result = TypingErrorInspector::inspect([], 'the quick', 3);

    expect($result['counts'])->toBe([0, 0, 0])
        ->and($result['events'])->toBe([]);
});

it('resolves both the first and the last word of the text', function () {
    // Kata terakhir tak diakhiri spasi -- cabang ekor wordBounds(). Inilah risiko
    // "error di kata terakhir" yang sesungguhnya (bukan completeWord() yang survival-only).
    $result = TypingErrorInspector::inspect(
        [
            ['second' => 0, 'index' => 0, 'actual' => 'r'],
            ['second' => 1, 'index' => 8, 'actual' => 'l'],
        ],
        'the quick',
        3,
    );

    expect($result['events'][0])->toMatchArray([
        'word' => 'the', 'offset' => 0, 'expected' => 't',
    ]);
    expect($result['events'][1])->toMatchArray([
        'word' => 'quick', 'offset' => 4, 'expected' => 'k',
    ]);
});

it('yields no word for an index past the end of the text', function () {
    $result = TypingErrorInspector::inspect(
        [['second' => 0, 'index' => 999, 'actual' => 'r']],
        'the quick',
        3,
    );

    expect($result['events'][0])->toMatchArray([
        'word' => null, 'offset' => null, 'expected' => null, 'actual' => 'r',
    ]);
});

it('yields no word for an index landing on a space', function () {
    // index 3 di 'the quick' adalah spasi. Tak pernah terjadi lewat jalur normal
    // (spasi dikecualikan dari missedChars), tapi wordAt() tak boleh mengarang kata.
    $result = TypingErrorInspector::inspect(
        [['second' => 0, 'index' => 3, 'actual' => 'r']],
        'the quick',
        3,
    );

    expect($result['events'][0]['word'])->toBeNull()
        ->and($result['events'][0]['offset'])->toBeNull();
});
