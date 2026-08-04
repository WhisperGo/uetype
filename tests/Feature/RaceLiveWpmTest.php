<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * WPM adalah fungsi waktu, bukan hanya ketukan: kalau pemain berhenti mengetik,
 * penyebutnya (menit berlalu) terus tumbuh sehingga WPM-nya turun. Angka WPM di
 * lane harus ikut hidup, sementara bar progres & maskot TIDAK boleh bergerak.
 */
/** Markup + modul JS arena; helper bersama ada di tests/Pest.php. */
function arenaJs(): string
{
    return arenaSourceAll();
}

function racingMember(User $user): RoomMember
{
    $room = Room::create([
        'code' => 'WPM123',
        'host_id' => $user->id,
        'status' => 'racing',
        // Tepat 100 karakter -> progress% == jumlah karakter benar (matematika bersih).
        'text_to_type' => str_repeat('ab cde ', 14).'ab', // 14*7 + 2 = 100
        'race_starts_at' => now()->subSeconds(60),
    ]);

    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'is_ready' => true,
        'progress_percent' => 40,
        'wpm' => 100,
    ]);
}

it('updates wpm on the server without moving progress', function () {
    // Jam dibekukan: server menghitung Net WPM dari (karakter / (now() - race_starts_at)), jadi
    // tiap detik nyata yang lewat selama test ikut menambah penyebutnya. Room ini mulai 60
    // detik lalu dan hasilnya diassert persis 8 -- cukup ~4 detik saja untuk menjatuhkannya ke
    // 7 dan membuat test gagal karena kecepatan mesin, bukan karena kodenya.
    $this->freezeTime();

    $user = User::factory()->create();
    $member = racingMember($user);

    // Ticker mengirim progres yang SEDANG BERLAKU (40). WPM client (60) DIABAIKAN;
    // server menghitung ulang Net WPM sendiri dari progres + durasi race.
    Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', 'WPM123')->set('step', 'racing')
        ->call('updateRaceProgress', 40, 60, 98);

    $member->refresh();

    // 100 char text, 40% -> 40 correct chars; race mulai 60 dtk lalu -> 1 menit.
    // Net WPM = (40 / 5) / 1 = 8. Bukan 60 (angka client).
    expect($member->wpm)->toBe(8)
        ->and($member->progress_percent)->toBe(40);
});

it('ignores wpm updates from a player who already finished', function () {
    $user = User::factory()->create();
    $member = racingMember($user);
    $member->update(['finished_time_seconds' => 12, 'wpm' => 90]);

    Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', 'WPM123')->set('step', 'racing')
        ->call('updateRaceProgress', 100, 5, 98);

    // WPM final pemain yang sudah finish tak boleh ikut meluruh.
    expect($member->fresh()->wpm)->toBe(90);
});

it('lets every viewer derive an opponent wpm instead of waiting for that opponent to send it', function () {
    $js = arenaJs();

    // Ini inti perbaikannya: tab lawan yang tidak aktif dibekukan browser, jadi
    // lawan yang berhenti mengetik takkan pernah menyiarkan WPM-nya yang meluruh.
    // Tiap penonton menghitungnya sendiri dari progres + waktu berlalu.
    expect($js)->toContain('const correctChars = (this.liveProgress / 100) * textLength;')
        ->and($js)->toContain('const minutes = ($store.race.now - raceStartMs) / 60000;')
        ->and($js)->toContain('return Math.floor((correctChars / 5) / minutes);');
});

it('drives every lane from one shared clock rather than a timer per player', function () {
    $js = arenaJs();

    expect($js)->toContain('now: Date.now(),')
        ->and($js)->toContain('startClock()')
        ->and($js)->toContain('$store.race.startClock();')
        // Jam dihentikan saat keluar room supaya interval tak bocor.
        ->and($js)->toContain('this.stopClock();');
});

it('anchors the race start on the client clock so a clock skew cannot distort wpm', function () {
    $js = arenaJs();

    // raceStartsInMs (sisa waktu dari server) + Date.now() = titik mulai di jam klien.
    expect($js)->toContain('raceStartMs = raceStartsInMs === null ? null : Date.now() + raceStartsInMs;');
});

it('freezes the wpm of a player who has finished', function () {
    $js = arenaJs();

    // Tanpa ini, WPM final pemain yang sudah menyentuh garis finis akan terus meluruh.
    expect($js)->toContain('if (this.liveFinished || raceStartMs === null) return reported;');
});

it('does not flood the network with a wpm request every second', function () {
    $js = arenaJs();

    // Ticker lokal hanya menyegarkan liveWpm; ia TIDAK memanggil emitProgress,
    // karena tiap penonton sudah menghitung WPM lawan sendiri.
    $ticker = substr($js, strpos($js, 'startWpmTicker() {'));
    $ticker = substr($ticker, 0, strpos($ticker, 'currentAccuracy()'));

    expect($ticker)->not->toContain('emitProgress')
        ->and($ticker)->not->toContain('publishLocal')
        ->and($ticker)->toContain('this.liveWpm = this.currentWpm();');
});

it('stops the ticker when the player finishes or the race locks', function () {
    $js = arenaJs();

    // WPM final berhenti di angka terakhir, tak terus meluruh setelah selesai.
    expect(substr_count($js, 'clearInterval(this._wpmInterval);'))->toBe(2); // lockRace + destroy
    expect($js)->toContain('if (this.isFinished || this.lockedByTimeout || !this.raceStarted) return;');
});

it('derives wpm and accuracy from one shared formula', function () {
    $js = arenaJs();

    // Rumusnya tak boleh diduplikasi antara checkInput(), handleSpace(), dan ticker,
    // kalau tidak angkanya bisa berbeda tergantung jalur mana yang menghitung.
    expect($js)->toContain('correctCharsSoFar()')
        ->and($js)->toContain('currentWpm()')
        ->and($js)->toContain('currentAccuracy()');

    // Rumus lama yang inline sudah tak ada lagi.
    expect($js)->not->toContain('(totalCorrectChars / 5) / timePassedMinutes')
        ->and($js)->not->toContain('(this.correctCharsFromPastWords / 5) / timePassedMinutes');
});
