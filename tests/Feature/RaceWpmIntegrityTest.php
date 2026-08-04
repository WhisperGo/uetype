<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * Integritas WPM multiplayer: server TIDAK boleh percaya angka WPM dari client.
 * Ia menghitung ulang Net WPM sendiri (karakter benar / waktu) via AntiCheatService,
 * selaras dengan mode solo (TypingEngine::saveResult). Ini menutup celah "ketik
 * ngasal secepat mungkin" -> WPM tinggi palsu masuk rekor.
 *
 * text_to_type dibuat 100 karakter agar progress% == jumlah karakter benar.
 */
function integrityRoom(User $host, int $startedSecondsAgo = 60): Room
{
    return Room::create([
        'code' => 'INT100',
        'host_id' => $host->id,
        'status' => 'racing',
        'text_to_type' => str_repeat('ab cde ', 14).'ab', // 100 karakter
        'race_starts_at' => now()->subSeconds($startedSecondsAgo),
    ]);
}

function integrityMember(Room $room, User $user, int $progress = 0): RoomMember
{
    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'is_ready' => true,
        'progress_percent' => $progress,
        'wpm' => 0,
    ]);
}

/**
 * Jam dibekukan untuk SETIAP test di berkas ini, bukan per test.
 *
 * Semua yang diperiksa di sini adalah WPM, dan server menghitungnya sebagai
 * karakter / (now() - race_starts_at): tiap detik NYATA yang lewat antara penyiapan room dan
 * pemanggilan ikut masuk ke penyebut. Toleransinya sempit -- pada kasus 60 detik cukup ~3
 * detik untuk menjatuhkan 10 menjadi 9, dan pada kasus "1 detik lalu" beberapa detik saja
 * mengubah klaim mustahil menjadi wajar sehingga test berhenti membuktikan apa pun.
 *
 * Di beforeEach karena sifat itu milik BERKAS ini, bukan milik satu test: menaruhnya per test
 * berarti test berikutnya yang ditambahkan orang lain akan lahir tanpa perlindungan yang sama.
 */
beforeEach(fn () => test()->freezeTime());

it('ignores the client wpm and stores the server-computed net wpm instead', function () {
    $user = User::factory()->create();
    $room = integrityRoom($user, startedSecondsAgo: 60);
    $member = integrityMember($room, $user);

    // Client mengaku 250 WPM padahal baru 50% dalam 60 detik.
    Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', 'INT100')->set('step', 'racing')
        ->call('updateRaceProgress', 50, 250, 100);

    // 50% dari 100 char = 50 char benar; 60 dtk = 1 menit -> (50/5)/1 = 10 Net WPM.
    expect($member->fresh()->wpm)->toBe(10);
});

it('does not let a fast-but-sloppy typist inflate wpm beyond what correct chars justify', function () {
    $user = User::factory()->create();
    // Baru 20% benar dalam 60 detik, tapi client klaim 180 WPM (ngetik ngasal cepat).
    $room = integrityRoom($user, startedSecondsAgo: 60);
    $member = integrityMember($room, $user);

    Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', 'INT100')->set('step', 'racing')
        ->call('updateRaceProgress', 20, 180, 35);

    // 20 char benar / 1 menit -> (20/5)/1 = 4 Net WPM. Akurasi 35% tampil apa adanya.
    $member->refresh();
    expect($member->wpm)->toBe(4)
        ->and((int) $member->accuracy)->toBe(35);
});

it('rejects an impossible wpm via the same anti-cheat gate as solo mode', function () {
    $user = User::factory()->create();
    // race_starts_at 1 detik lalu + progress 100% (100 char) -> raw 6000 WPM,
    // jauh di atas MAX_HUMAN_WPM (300). Gate menolak -> WPM disimpan 0, bukan angka gila.
    $room = integrityRoom($user, startedSecondsAgo: 1);
    $member = integrityMember($room, $user);

    Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', 'INT100')->set('step', 'racing')
        ->call('updateRaceProgress', 100, 9999, 100);

    expect($member->fresh()->wpm)->toBe(0);
});

it('records net wpm on the finishing tick, not the client number', function () {
    $user = User::factory()->create();
    $room = integrityRoom($user, startedSecondsAgo: 60);
    $member = integrityMember($room, $user, progress: 90);

    Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', 'INT100')->set('step', 'racing')
        ->call('updateRaceProgress', 100, 300, 100);

    // Finish: 100 char benar / 1 menit -> (100/5)/1 = 20 Net WPM, bukan 300.
    $member->refresh();
    expect($member->wpm)->toBe(20)
        ->and($member->finished_time_seconds)->not->toBeNull()
        ->and($member->progress_percent)->toBe(100);
});
