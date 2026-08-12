<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * State hasil satu balapan tidak boleh terbawa ke balapan berikutnya.
 *
 * $hasFinished menyembunyikan panel input ketik di view. Ia di-reset di
 * roomUpdated() dan playAgain(), tapi TIDAK di resetToChoose() maupun startRace()
 * -- padahal keduanya adalah jalan yang dilewati host saat memulai room baru.
 * Host tak menerima broadcast room.updated miliknya sendiri (->toOthers()), jadi
 * tak ada yang membersihkan state itu untuknya.
 */
/**
 * Room siap-mulai: host + SATU pembalap lain, keduanya ready.
 *
 * Pembalap kedua wajib ada karena startRace() menolak room dengan kurang dari
 * MIN_PLAYERS_TO_START pembalap. Berkas ini menguji reset state, bukan aturan mulai --
 * tanpa pembalap kedua tiap test di sini gagal karena alasan yang sama sekali lain
 * (balapannya tak pernah dimulai) dan pesan gagalnya akan menyesatkan.
 */
function lobbyRoomFor(User $host, string $code): Room
{
    $room = Room::create([
        'code' => $code,
        'host_id' => $host->id,
        'status' => 'waiting',
        'text_to_type' => 'aa bb cc',
    ]);

    RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true]);
    RoomMember::create([
        'room_id' => $room->id,
        'user_id' => User::factory()->create()->id,
        'is_ready' => true,
    ]);

    return $room;
}

it('membersihkan status selesai saat kembali ke layar pilih', function () {
    $host = User::factory()->create();
    lobbyRoomFor($host, 'RST111');

    $c = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'RST111')
        ->set('step', 'racing')
        ->set('hasFinished', true)
        ->call('leaveRoom');

    // Tanpa ini, room berikutnya dimulai dengan panel ketik tersembunyi.
    $c->assertSet('step', 'choose')->assertSet('hasFinished', false);
});

it('membersihkan status selesai saat balapan baru dimulai', function () {
    $host = User::factory()->create();
    lobbyRoomFor($host, 'RST222');

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'RST222')
        ->set('step', 'waiting')
        ->set('hasFinished', true)
        ->call('startRace')
        ->assertSet('step', 'racing')
        ->assertSet('hasFinished', false);
});

/** Panel input harus tampil lagi di balapan berikutnya. */
it('menampilkan kembali panel ketik pada balapan setelah host pernah selesai', function () {
    $host = User::factory()->create();
    lobbyRoomFor($host, 'RST333');

    $html = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'RST333')
        ->set('step', 'waiting')
        ->set('hasFinished', true)
        ->call('startRace')
        ->html();

    expect($html)->toContain('x-model="typedText"');
});
