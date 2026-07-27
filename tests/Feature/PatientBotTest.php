<?php

use App\Livewire\TypingEngine;
use App\Models\TypingResult;
use App\Models\User;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

/**
 * The "patient bot" (report §4.1): the attack that survived the SoloSessionGuard fix. It
 * waits out the real duration (a free sleep()), then forges a full-length payload. Before
 * §7.3 + §7.5 it landed ~200 WPM on the leaderboard. These tests pin that it no longer does
 * -- caught first by the tightened char ceiling, and whatever slips under it is held for
 * review, never public.
 */
function playPatient(User $user, string $sub, int $durationMs, int $chars, array $extra = [])
{
    $c = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', $sub);
    app(SoloSessionGuard::class)->backdate($durationMs / 1000);

    return $c->call('saveResult', array_merge([
        'durationMs' => $durationMs,
        'totalKeystrokes' => $chars,
        'correctKeystrokes' => $chars,
        'maxIdleMs' => 100,
    ], $extra));
}

/*
|--------------------------------------------------------------------------
| Pembagian tugas setelah plafon dinaikkan 13 -> 20 cps (2026-07-27)
|--------------------------------------------------------------------------
| Plafon 13 cps (156 WPM) MENOLAK PEMAIN JUJUR: seorang pemain ~185 WPM dengan
| akurasi 98% ditolak berulang kali. Plafonnya dinaikkan ke 20 cps (240 WPM),
| menyamakannya dengan MAX_RACE_WPM supaya ada satu definisi "di luar batas
| manusia" di seluruh aplikasi.
|
| Konsekuensinya jujur: payload §4.1 (500 char / 30s = 200 WPM) kini LOLOS plafon.
| Ia tidak lolos begitu saja -- ia jatuh ke lapisan kedua (§7.5): run >= 150 WPM
| tanpa riwayat, atau > 40% di atas rata-rata pemain sendiri, DITAHAN untuk review.
| Baris `pending` tetap tersimpan tapi tak pernah masuk leaderboard publik dan tak
| menaikkan highest_wpm.
|
| Jadi yang berubah bukan "bot menang", melainkan SIAPA yang menangkapnya: dulu
| plafon menolak di depan, sekarang review menahan di belakang. Itu trade yang
| disengaja -- menolak pemain jujur lebih mahal daripada menahan bot satu lapis
| lebih dalam, karena pemain jujur tak punya jalan banding sementara bot tak
| mendapat apa-apa dari baris yang tak pernah publik.
*/
it('holds the §4.1 payload (500 chars in 30s = 200 WPM) instead of publishing it', function () {
    $user = User::factory()->create();

    // 500 char / 30s = 16,7 cps: di bawah plafon 20 cps yang baru, jadi ia TIDAK ditolak
    // di depan lagi. Yang dijaga sekarang: ia tak pernah jadi angka publik.
    playPatient($user, '30', 30000, 500);

    $r = TypingResult::where('user_id', $user->id)->latest('id')->first();

    expect($r)->not->toBeNull()
        ->and($r->review_status)->toBe(TypingResult::REVIEW_PENDING)
        // Inilah jaminan yang sebenarnya penting: leaderboard & PB tak tersentuh.
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('still refuses outright a payload above the human ceiling itself', function () {
    // Plafonnya dinaikkan, bukan dibuang. 20 cps = 240 WPM; di atas itu ditolak sebelum
    // apa pun disimpan, sama seperti sebelumnya.
    $user = User::factory()->create();

    // 800 char / 30s = 26,7 cps (~320 WPM): jauh di atas plafon -> ditolak, tak tersimpan.
    playPatient($user, '30', 30000, 800)->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('holds a patient bot that paces UNDER the char ceiling for review (never public)', function () {
    $user = User::factory()->create();

    // The smart patient bot aims just under the ceiling: 430 chars / 30s ~= 172 WPM, which
    // clears the char guard. But with no history it's a >=150 debut -> held `pending`, so it
    // stays off the leaderboard and doesn't become the player's PB.
    playPatient($user, '30', 30000, 430);

    $r = TypingResult::where('user_id', $user->id)->latest('id')->first();

    expect($r)->not->toBeNull()
        ->and($r->review_status)->toBe(TypingResult::REVIEW_PENDING)
        ->and((float) $user->fresh()->highest_wpm)->toBe(0.0);
});

it('caps the best a patient bot can even CLAIM at the human ceiling', function () {
    // Sanity pin: plafon karakter membatasi pemalsuan durasi-penuh di 240 WPM (20 cps),
    // bukan angka bebas. Batas ini mengikat waktu yang BENAR-BENAR berlalu di server, jadi
    // "tidur lalu kirim payload penuh" tetap terkunci -- itu inti pertahanan §7.3 dan ia
    // masih berlaku, hanya di angka yang tak lagi menabrak pemain jujur.
    $user = User::factory()->create();

    // 700 char / 30s ~= 280 WPM: di atas plafon -> ditolak sebelum diberi skor.
    playPatient($user, '30', 30000, 700)->assertRedirect(route('typing'));

    expect(TypingResult::where('user_id', $user->id)->count())->toBe(0);
});
