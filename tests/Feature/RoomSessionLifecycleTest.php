<?php

use App\Events\RoomPresenceChanged;
use App\Events\RoomUpdated;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\RoomMembershipService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * ===== KEANGGOTAAN ROOM TAK BOLEH MELAMPAUI SESI LOGIN =====
 *
 * Bug aslinya: buka web, biarkan lama, tutup browser; besoknya buka lagi, harus login ulang,
 * tekan Multiplayer — dan mendarat kembali di dalam room kemarin.
 *
 * DUA mekanisme menyembunyikan keusangan itu, dan MASING-MASING sendirian sudah cukup
 * menyebabkannya:
 *
 *  1. sweepOfflineMembers() menerima $exceptUserId dan tak pernah menilai si pemanggil.
 *     Pengecualian itu benar untuk tujuan aslinya (heartbeat yang belum mendarat, hitungan
 *     detik), tapi efek sampingnya: baris milik sendiri yang berumur SEHARI ikut kebal.
 *  2. Heartbeat presence nge-ping segera saat halaman dimuat, jadi `last_seen_at` sudah
 *     segar sebelum mount() sempat menilainya. Bukti keabsenannya dihancurkan sebelum dibaca.
 *
 * Jadi perbaikannya TIDAK BISA berupa aturan last_seen_at yang lain — sinyal itu sudah lenyap
 * saat dibutuhkan. Batas sesi login adalah satu-satunya penanda yang benar-benar berarti
 * "browser yang memegang room itu sudah tidak ada".
 */
beforeEach(function () {
    Event::fake([RoomUpdated::class, RoomPresenceChanged::class]);
});

function sessionRoom(string $code, User $host, string $status = 'waiting'): Room
{
    return Room::create([
        'code' => $code,
        'host_id' => $host->id,
        'status' => $status,
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
    ]);
}

function sessionMember(Room $room, User $user): RoomMember
{
    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true,
        'progress_percent' => 0,
        'wpm' => 0,
        'accuracy' => 100,
    ]);
}

it('releases the room when the user logs in again', function () {
    $kembali = User::factory()->create();
    $lain = User::factory()->create();

    $room = sessionRoom('SES001', $lain);
    sessionMember($room, $lain);
    sessionMember($room, $kembali);

    Auth::login($kembali);

    expect(RoomMember::where('user_id', $kembali->id)->exists())->toBeFalse()
        // Room-nya sendiri bertahan karena masih ada anggota lain.
        ->and(Room::whereKey($room->id)->exists())->toBeTrue();
});

it('releases the room on logout too', function () {
    $keluar = User::factory()->create();
    $lain = User::factory()->create();

    $room = sessionRoom('SES002', $lain);
    sessionMember($room, $lain);
    sessionMember($room, $keluar);

    Auth::login($keluar);          // sesi baru: baris lama dilepas
    sessionMember($room, $keluar); // masuk lagi dalam sesi ini
    Auth::logout();

    expect(RoomMember::where('user_id', $keluar->id)->exists())->toBeFalse();
});

/**
 * Skenario yang dilaporkan, dari ujung ke ujung: login ulang lalu membuka lobby harus mendarat
 * di layar pilih, bukan di room kemarin.
 */
it('lands a returning player on the choose screen, not inside yesterday’s room', function () {
    $kembali = User::factory()->create();
    $lain = User::factory()->create();

    $room = sessionRoom('SES003', $lain);
    sessionMember($room, $lain);
    sessionMember($room, $kembali);

    Auth::login($kembali);

    Livewire::actingAs($kembali)->test(MultiplayerLobby::class)
        ->assertSet('step', 'choose')
        ->assertSet('roomCode', '');
});

/** Host yang login ulang menyerahkan room-nya, bukan meninggalkannya jadi room hantu. */
it('hands the room over when the returning player was its host', function () {
    $host = User::factory()->create();
    $anggota = User::factory()->create();

    $room = sessionRoom('SES004', $host);
    sessionMember($room, $host);
    sessionMember($room, $anggota);

    Auth::login($host);

    expect($room->fresh()->host_id)->toBe($anggota->id);
});

it('deletes the room when its last member logs in again', function () {
    $sendiri = User::factory()->create();

    $room = sessionRoom('SES005', $sendiri);
    sessionMember($room, $sendiri);

    Auth::login($sendiri);

    expect(Room::whereKey($room->id)->exists())->toBeFalse();
});

/**
 * PENJAGA REGRESI: refresh halaman BUKAN login ulang, jadi seluruh perilaku restore di §3.12
 * (termasuk melanjutkan race dari progress terakhir) harus tetap utuh. Kalau perbaikan ini
 * sampai menyentuh jalur itu, pemain yang me-refresh di tengah balapan akan terlempar keluar.
 */
it('still restores a member across an ordinary page load', function () {
    $pemain = User::factory()->create();
    $lain = User::factory()->create();

    $room = sessionRoom('SES006', $lain);
    sessionMember($room, $lain);
    sessionMember($room, $pemain);

    Livewire::actingAs($pemain)->test(MultiplayerLobby::class)
        ->assertSet('step', 'waiting')
        ->assertSet('roomCode', 'SES006');
});

// ===== TEMUAN 4: ROOM 'finished' TAK PERNAH DISAPU =====

/**
 * sweepStaleWaitingMembers memfilter status='waiting' dan sweepAbandonedRaces memfilter
 * status='racing'. Room 'finished' jatuh di antara keduanya dan TAK DISAPU SIAPA PUN — jadi
 * semua orang menutup tab di layar hasil (cara paling biasa sebuah race berakhir) meninggalkan
 * room beserta seluruh barisnya selamanya.
 */
it('deletes a finished room once every member has gone offline', function () {
    $a = User::factory()->offline()->create();
    $b = User::factory()->offline()->create();

    $room = sessionRoom('SES007', $a, status: 'finished');
    sessionMember($room, $a);
    sessionMember($room, $b);

    app(RoomMembershipService::class)->sweepOfflineMembers();

    expect(Room::whereKey($room->id)->exists())->toBeFalse();
});

/**
 * All-or-nothing, sama seperti sapuan race: layar hasil masih menjalankan tugasnya selama
 * masih ada SATU orang yang membacanya, dan mencabut baris dari bawah mereka akan mengosongkan
 * papan yang sedang dilihat.
 */
it('keeps a finished room while somebody is still looking at the result', function () {
    $pergi = User::factory()->offline()->create();
    $masihLihat = User::factory()->create(); // factory default: online

    $room = sessionRoom('SES008', $pergi, status: 'finished');
    sessionMember($room, $pergi);
    sessionMember($room, $masihLihat);

    app(RoomMembershipService::class)->sweepOfflineMembers();

    expect(Room::whereKey($room->id)->exists())->toBeTrue()
        ->and(RoomMember::where('room_id', $room->id)->count())->toBe(2);
});

it('never sweeps a finished room out from under the caller', function () {
    $pemanggil = User::factory()->offline()->create(); // heartbeat belum mendarat
    $lain = User::factory()->offline()->create();

    $room = sessionRoom('SES009', $pemanggil, status: 'finished');
    sessionMember($room, $pemanggil);
    sessionMember($room, $lain);

    app(RoomMembershipService::class)->sweepOfflineMembers(exceptUserId: $pemanggil->id);

    expect(Room::whereKey($room->id)->exists())->toBeTrue();
});
