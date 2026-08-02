<?php

use App\Models\User;

/**
 * Tombol Tab di layar hasil harus memindahkan fokus ke AKSI UTAMA layar itu -- apa pun
 * cabangnya.
 *
 * Dulu handler-nya memanggil preventDefault() lalu getElementById('restartButton').focus()
 * tanpa guard. Di hasil Clan War aksi utamanya <x-result-back-to-war />, yang tak ber-id, jadi
 * tiap tekan Tab melempar TypeError SETELAH tombolnya sudah ditelan: fokus tak ke mana-mana
 * dan tak ada satu pun elemen yang bisa dicapai keyboard.
 *
 * Proyek ini tak menjalankan JS di test, jadi yang dikunci adalah KONTRAK MARKUP-nya
 * (pola yang sama dengan TypingEngineAssetTest & MobileTypingInputTest).
 */

/** Isi session hasil ketik yang minimal tapi cukup untuk merender /result. */
function tabResultSession(string $mode, ?array $war): array
{
    return [
        'wpm' => 60, 'rawWpm' => 65, 'accuracy' => 95, 'time' => 30,
        'mode' => $mode, 'subMode' => $mode === 'survival' ? 'hard' : '30',
        'score' => $mode === 'survival' ? 120 : null,
        'totalKeystrokes' => 300, 'correctKeystrokes' => 290, 'incorrectKeystrokes' => 10,
        'war' => $war,
    ];
}

it('mencari target lebih dulu dan hanya menelan Tab kalau targetnya ada', function () {
    $blade = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/typing-result.blade.php')));

    expect(preg_match('/@keydown\.window="(.*?)"\s*>/s', $blade, $m))->toBe(1);
    $handler = $m[1];

    expect($handler)->toContain('data-result-primary')
        // Bentuk lama yang sempat ter-ship: tanpa guard, tanpa optional chaining.
        ->and($handler)->not->toContain("getElementById('restartButton').focus()");

    // URUTAN adalah inti perbaikannya: cari target DULU, baru boleh menelan tombolnya.
    // Menambal `?.` saja akan menyisakan Tab yang tertelan diam-diam -- bug yang sama
    // berbaju lain.
    expect(strpos($handler, 'querySelector'))->toBeLessThan(strpos($handler, 'preventDefault'));
});

it('memberi tepat satu target Tab di setiap cabang layar hasil', function (string $mode, ?array $war) {
    $user = User::factory()->create();

    session()->put('typing_result', tabResultSession($mode, $war));

    $html = $this->actingAs($user)->get('/result')->assertOk()->getContent();

    // Dihitung sebagai ATRIBUT (didahului spasi, diakhiri spasi/`>`), bukan sekadar substring:
    // selektor di dalam handler-nya sendiri berbunyi `[data-result-primary]` dan akan ikut
    // terhitung, membuat test ini gagal atas markup yang justru sudah benar.
    // toBe(1), bukan toBeGreaterThan(0): querySelector mengambil yang PERTAMA, jadi dua target
    // berarti Tab diam-diam memfokuskan yang salah.
    expect(preg_match_all('/\sdata-result-primary[\s>]/', $html))->toBe(1);
})->with([
    'solo time' => ['time', null],
    'solo survival' => ['survival', null],
    'war time' => ['time', ['mode' => 'time', 'config' => '30']],
    'war survival' => ['survival', ['mode' => 'survival', 'config' => 'hard']],
]);

it('menjadikan tautan kembali-ke-war sebagai target Tab pada hasil war', function () {
    $user = User::factory()->create();

    session()->put('typing_result', tabResultSession('time', ['mode' => 'time', 'config' => '30']));

    $html = $this->actingAs($user)->get('/result')->assertOk()->getContent();

    // Anchor + ikon panah = komponen result-back-to-war, bukan elemen nyasar lain.
    expect($html)->toMatch('/<a[^>]*data-result-primary[^>]*>\s*<svg/');
});
