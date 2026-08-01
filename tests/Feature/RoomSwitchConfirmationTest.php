<?php

use App\Events\RoomPresenceChanged;
use App\Events\RoomUpdated;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * ===== PINDAH ROOM HARUS DISADARI =====
 *
 * joinRoomByCode() dan createRoom() sama-sama memanggil departCurrentRooms() tanpa syarat,
 * jadi masuk ke room baru DIAM-DIAM membuang keanggotaan yang lama. Yang paling terasa adalah
 * lewat undangan: tombol Terima langsung membuka /multiplayer?invite=CODE dan mount() ikut
 * meng-auto-join, sehingga pemain terlempar keluar dari room tempat ia sedang menunggu.
 *
 * Overlay "yakin keluar?" yang sudah ada TIDAK menangkapnya, karena DUA sebab yang berdiri
 * sendiri (lihat multiplayer-nav.js):
 *   1. interceptor hanya memantau klik <a href>, sedangkan tombol Terima adalah <button>
 *      yang menyetel window.location.href;
 *   2. interceptor sengaja MELEWATKAN tujuan yang tetap berada di /multiplayer — dan tujuan
 *      undangan justru /multiplayer.
 *
 * Karena itu gerbangnya dipasang di SERVER, bukan di klien: seorang pemain bisa berada di
 * sebuah room sambil membuka /stats di tab lain, dan di sana atribut data-mp-flags milik
 * lobby tidak ada sama sekali. Aturannya satu dan berlaku untuk ketiga pintu (undangan, kode
 * manual, Create Room):
 *
 *   room lama sedang 'racing' -> DITOLAK  (keluar mid-race juga merugikan lawan)
 *   selain itu                -> KONFIRMASI dulu, tak pernah pindah diam-diam
 */
beforeEach(function () {
    Event::fake([RoomUpdated::class, RoomPresenceChanged::class]);
});

function switchRoom(string $code, User $host, string $status = 'waiting'): Room
{
    return Room::create([
        'code' => $code,
        'host_id' => $host->id,
        'status' => $status,
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => $status === 'racing' ? now()->subSeconds(5) : null,
    ]);
}

function switchMember(Room $room, User $user, string $role = RoomMember::ROLE_PLAYER): RoomMember
{
    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'role' => $role,
        'is_ready' => false,
        'progress_percent' => 0,
        'wpm' => 0,
        'accuracy' => 100,
    ]);
}

// ===== PINTU 1: UNDANGAN =====

it('asks for confirmation instead of yanking a player out of their current room', function () {
    $pemain = User::factory()->create();
    $tuanRumahLama = User::factory()->create();
    $tuanRumahBaru = User::factory()->create();

    $lama = switchRoom('OLD001', $tuanRumahLama);
    switchMember($lama, $tuanRumahLama);
    $anggota = switchMember($lama, $pemain);

    $baru = switchRoom('NEW001', $tuanRumahBaru);
    switchMember($baru, $tuanRumahBaru);

    Livewire::actingAs($pemain)
        ->withQueryParams(['invite' => 'NEW001'])
        ->test(MultiplayerLobby::class)
        ->assertSet('pendingRoomSwitch', ['from' => 'OLD001', 'to' => 'NEW001'])
        // Masih di room lama sampai ia benar-benar memilih.
        ->assertSet('roomCode', 'OLD001');

    expect($anggota->fresh())->not->toBeNull()
        ->and(RoomMember::where('user_id', $pemain->id)->count())->toBe(1);
});

it('moves the player once they confirm the switch', function () {
    $pemain = User::factory()->create();
    $lamaHost = User::factory()->create();
    $baruHost = User::factory()->create();

    $lama = switchRoom('OLD002', $lamaHost);
    switchMember($lama, $lamaHost);
    switchMember($lama, $pemain);

    $baru = switchRoom('NEW002', $baruHost);
    switchMember($baru, $baruHost);

    Livewire::actingAs($pemain)
        ->withQueryParams(['invite' => 'NEW002'])
        ->test(MultiplayerLobby::class)
        ->call('confirmRoomSwitch')
        ->assertSet('roomCode', 'NEW002')
        ->assertSet('pendingRoomSwitch', null);

    $member = RoomMember::where('user_id', $pemain->id)->first();

    expect($member->room_id)->toBe($baru->id)
        ->and(RoomMember::where('user_id', $pemain->id)->count())->toBe(1);
});

it('keeps the player exactly where they were when they cancel', function () {
    $pemain = User::factory()->create();
    $lamaHost = User::factory()->create();
    $baruHost = User::factory()->create();

    $lama = switchRoom('OLD003', $lamaHost);
    switchMember($lama, $lamaHost);
    switchMember($lama, $pemain);

    $baru = switchRoom('NEW003', $baruHost);
    switchMember($baru, $baruHost);

    Livewire::actingAs($pemain)
        ->withQueryParams(['invite' => 'NEW003'])
        ->test(MultiplayerLobby::class)
        ->call('cancelRoomSwitch')
        ->assertSet('pendingRoomSwitch', null)
        ->assertSet('roomCode', 'OLD003');

    expect(RoomMember::where('user_id', $pemain->id)->first()->room_id)->toBe($lama->id);
});

/**
 * Undangan yang tiba saat pemain sedang BALAPAN adalah kasus terburuknya: menerimanya membuang
 * race yang sedang berjalan — dan barisnya lenyap dari tengah balapan yang masih dipakai lawan.
 */
it('refuses the switch outright while the current room is racing', function () {
    $pemain = User::factory()->create();
    $lawan = User::factory()->create();
    $baruHost = User::factory()->create();

    $lama = switchRoom('OLD004', $pemain, status: 'racing');
    switchMember($lama, $pemain);
    switchMember($lama, $lawan);

    $baru = switchRoom('NEW004', $baruHost);
    switchMember($baru, $baruHost);

    Livewire::actingAs($pemain)
        ->withQueryParams(['invite' => 'NEW004'])
        ->test(MultiplayerLobby::class)
        // Tak ada konfirmasi yang ditawarkan: ini ditolak, bukan ditanyakan.
        ->assertSet('pendingRoomSwitch', null)
        ->assertSet('roomCode', 'OLD004');

    expect(RoomMember::where('user_id', $pemain->id)->first()->room_id)->toBe($lama->id)
        ->and(session('error'))->toBe(__('multiplayer.error_leave_race_first'));
});

it('still auto-joins from an invite when the player is in no room at all', function () {
    $pemain = User::factory()->create();
    $host = User::factory()->create();

    $room = switchRoom('NEW005', $host);
    switchMember($room, $host);

    Livewire::actingAs($pemain)
        ->withQueryParams(['invite' => 'NEW005'])
        ->test(MultiplayerLobby::class)
        ->assertSet('roomCode', 'NEW005')
        ->assertSet('pendingRoomSwitch', null);

    expect(RoomMember::where('user_id', $pemain->id)->first()->room_id)->toBe($room->id);
});

it('does not ask anything when the invite points at the room they are already in', function () {
    $pemain = User::factory()->create();
    $host = User::factory()->create();

    $room = switchRoom('SAME01', $host);
    switchMember($room, $host);
    switchMember($room, $pemain);

    Livewire::actingAs($pemain)
        ->withQueryParams(['invite' => 'SAME01'])
        ->test(MultiplayerLobby::class)
        ->assertSet('pendingRoomSwitch', null)
        ->assertSet('roomCode', 'SAME01');
});

// ===== PINTU 2: KODE MANUAL =====

/**
 * Undangan cuma pintu yang paling terlihat. Memasukkan kode room lain dari dalam sebuah room
 * memakai jalur joinRoomByCode() yang PERSIS SAMA, jadi ia punya bug yang sama — dan menutup
 * undangan saja akan meninggalkan gejalanya hidup lewat pintu sebelah.
 */
it('asks for confirmation when a manual join code would leave the current room', function () {
    $pemain = User::factory()->create();
    $lamaHost = User::factory()->create();
    $baruHost = User::factory()->create();

    $lama = switchRoom('OLD006', $lamaHost);
    switchMember($lama, $lamaHost);
    switchMember($lama, $pemain);

    $baru = switchRoom('NEW006', $baruHost);
    switchMember($baru, $baruHost);

    Livewire::actingAs($pemain)->test(MultiplayerLobby::class)
        ->set('joinCodeInput', ['N', 'E', 'W', '0', '0', '6'])
        ->call('joinRoom')
        ->assertSet('pendingRoomSwitch', ['from' => 'OLD006', 'to' => 'NEW006']);

    expect(RoomMember::where('user_id', $pemain->id)->first()->room_id)->toBe($lama->id);
});

// ===== PINTU 3: CREATE ROOM =====

it('asks for confirmation when creating a room would leave the current one', function () {
    $pemain = User::factory()->create();
    $lamaHost = User::factory()->create();

    $lama = switchRoom('OLD007', $lamaHost);
    switchMember($lama, $lamaHost);
    switchMember($lama, $pemain);

    Livewire::actingAs($pemain)->test(MultiplayerLobby::class)
        ->call('createRoom')
        // to = null menandai "buat room baru", bukan pindah ke kode tertentu.
        ->assertSet('pendingRoomSwitch', ['from' => 'OLD007', 'to' => null]);

    expect(Room::count())->toBe(1)
        ->and(RoomMember::where('user_id', $pemain->id)->first()->room_id)->toBe($lama->id);
});

it('creates the room once the switch is confirmed', function () {
    $pemain = User::factory()->create();
    $lamaHost = User::factory()->create();

    $lama = switchRoom('OLD008', $lamaHost);
    switchMember($lama, $lamaHost);
    switchMember($lama, $pemain);

    Livewire::actingAs($pemain)->test(MultiplayerLobby::class)
        ->call('createRoom')
        ->call('confirmRoomSwitch')
        ->assertSet('step', 'waiting')
        ->assertSet('pendingRoomSwitch', null);

    expect(Room::count())->toBe(2)
        ->and(RoomMember::where('user_id', $pemain->id)->first()->room_id)->not->toBe($lama->id);
});

it('creates a room with no questions asked when the player is in no room', function () {
    $pemain = User::factory()->create();

    Livewire::actingAs($pemain)->test(MultiplayerLobby::class)
        ->call('createRoom')
        ->assertSet('step', 'waiting')
        ->assertSet('pendingRoomSwitch', null);

    expect(Room::count())->toBe(1);
});

// ===== KETAHANAN =====

it('ignores a confirmation that was never requested', function () {
    $pemain = User::factory()->create();

    Livewire::actingAs($pemain)->test(MultiplayerLobby::class)
        ->call('confirmRoomSwitch')
        ->assertSet('step', 'choose');

    expect(Room::count())->toBe(0);
});

/**
 * Room yang sudah 'finished' tetap dikonfirmasi, bukan dilewatkan.
 *
 * Menggodanya adalah membebaskan status ini — tak ada race yang hilang, jadi "tak ada yang
 * dirugikan". Tapi layar hasil adalah tempat orang menekan Main Lagi bersama, dan aturan yang
 * berbunyi "kamu sedang di sebuah room, meninggalkannya adalah keputusan" hanya bisa dipercaya
 * kalau ia berlaku di semua status. Satu aturan yang konsisten mengalahkan satu pengecualian
 * yang harus dijelaskan.
 */
it('confirms the switch even from a finished room', function () {
    $pemain = User::factory()->create();
    $lamaHost = User::factory()->create();
    $baruHost = User::factory()->create();

    $lama = switchRoom('OLD009', $lamaHost, status: 'finished');
    switchMember($lama, $lamaHost);
    switchMember($lama, $pemain);

    $baru = switchRoom('NEW009', $baruHost);
    switchMember($baru, $baruHost);

    Livewire::actingAs($pemain)->test(MultiplayerLobby::class)
        ->set('joinCodeInput', ['N', 'E', 'W', '0', '0', '9'])
        ->call('joinRoom')
        ->assertSet('pendingRoomSwitch', ['from' => 'OLD009', 'to' => 'NEW009']);
});
