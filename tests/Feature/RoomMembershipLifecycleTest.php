<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * Keanggotaan room punya satu aturan: seorang pemain ada di PALING BANYAK satu
 * room, dan room yang ditinggal kosong ikut hilang.
 *
 * Dulu hanya leaveRoom() yang menghormatinya. createRoom() membuang keanggotaan
 * lama tanpa merawat room yang ditinggalkan (room tanpa anggota tetap hidup
 * selamanya), dan joinRoom() bahkan tak membuangnya sama sekali (satu pemain bisa
 * terdaftar di dua room sekaligus, mengunci slot di room yang sudah ia tinggalkan
 * dan meninggalkan host hantu di sana).
 *
 * Keduanya terbukti lewat eksekusi, bukan pembacaan kode:
 *   createRoom : rooms=2, first_room_still_exists=true, members_left_in_first=0
 *   joinRoom   : b_memberships=2, b_room_ids=[4,3]
 */
function lobbyFor(User $user)
{
    return Livewire::actingAs($user)->test(MultiplayerLobby::class);
}

function joinRoomAs(User $user, string $code)
{
    return lobbyFor($user)->set('joinCodeInput', str_split($code))->call('joinRoom');
}

it('deletes the room a player abandons by creating a new one', function () {
    $host = User::factory()->create();

    lobbyFor($host)->call('createRoom');
    $first = Room::first();

    lobbyFor($host)->call('createRoom');

    expect(Room::whereKey($first->id)->exists())->toBeFalse()
        ->and(Room::count())->toBe(1);
});

it('keeps a player in exactly one room when they join another', function () {
    $host = User::factory()->create();
    $joiner = User::factory()->create();

    lobbyFor($host)->call('createRoom');
    $target = Room::first();

    // $joiner sudah jadi host di room-nya sendiri, lalu bergabung ke room lain.
    lobbyFor($joiner)->call('createRoom');
    $own = Room::where('id', '!=', $target->id)->first();

    joinRoomAs($joiner, $target->code);

    expect(RoomMember::where('user_id', $joiner->id)->count())->toBe(1)
        ->and(RoomMember::where('user_id', $joiner->id)->first()->room_id)->toBe($target->id)
        // Room lamanya kosong -> ikut terhapus, tak menyisakan host hantu.
        ->and(Room::whereKey($own->id)->exists())->toBeFalse();
});

it('does not delete the abandoned room when other members remain', function () {
    $host = User::factory()->create();
    $other = User::factory()->create();
    $target = User::factory()->create();

    lobbyFor($host)->call('createRoom');
    $shared = Room::first();
    joinRoomAs($other, $shared->code);

    lobbyFor($target)->call('createRoom');
    $targetRoom = Room::where('id', '!=', $shared->id)->first();

    // $other pergi, tapi $host masih di sana -> room TIDAK boleh ikut terhapus.
    joinRoomAs($other, $targetRoom->code);

    expect(Room::whereKey($shared->id)->exists())->toBeTrue()
        ->and(RoomMember::where('room_id', $shared->id)->count())->toBe(1);
});

it('hands the room over to someone else when the host leaves by creating a new room', function () {
    $host = User::factory()->create();
    $other = User::factory()->create();

    lobbyFor($host)->call('createRoom');
    $room = Room::first();
    joinRoomAs($other, $room->code);

    lobbyFor($host)->call('createRoom');

    // Room tetap hidup untuk $other, dan host-nya berpindah -- bukan tetap
    // menunjuk pemain yang sudah tak ada di sana.
    expect(Room::whereKey($room->id)->exists())->toBeTrue()
        ->and(Room::find($room->id)->host_id)->toBe($other->id);
});
