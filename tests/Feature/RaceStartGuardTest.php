<?php

use App\Enums\RoomStatus;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * startRace() hanya boleh dijalankan dari lobby yang masih 'waiting'.
 *
 * Ia dulu satu-satunya aksi host-only di MultiplayerLobby yang TIDAK memeriksa status room --
 * setRaceLang(), kickMember(), dan toggleSpectator() semuanya memeriksa. Akibatnya bukan cuma
 * host bisa mereset balapan yang sedang berjalan, tapi juga: dari room 'finished' ia bisa
 * memulai ulang tanpa lewat playAgain(), dan playAgain() adalah SATU-SATUNYA jalur rematch yang
 * meregenerasi text_to_type.
 *
 * Mengulang teks yang sama adalah keunggulan hafalan, dan hasilnya masuk multiplayer_match_history
 * secara permanen -- persis yang dicegah regenerasi teks itu.
 */
function startGuardRoom(): array
{
    $host = User::factory()->create();
    $rival = User::factory()->create();

    $component = Livewire::actingAs($host)->test(MultiplayerLobby::class)->call('createRoom');
    $room = Room::where('code', $component->get('roomCode'))->first();

    RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $rival->id,
        'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true,
    ]);

    return [$component, $room, $host, $rival];
}

it('refuses to restart a race that is already running', function () {
    [$component, $room] = startGuardRoom();

    $component->call('startRace');
    $room->refresh();

    expect($room->status)->toBe(RoomStatus::Racing);

    // Seorang pemain sudah maju jauh; sudden death sedang berjalan.
    RoomMember::where('room_id', $room->id)->update(['progress_percent' => 80]);
    $room->update(['countdown_started_at' => now()]);

    $startsAt = $room->fresh()->race_starts_at;

    $component->call('startRace');

    $room->refresh();

    // Progres tidak boleh dinolkan, dan jam sudden death tidak boleh dimatikan.
    expect(RoomMember::where('room_id', $room->id)->max('progress_percent'))->toBe(80);
    expect($room->countdown_started_at)->not->toBeNull();
    expect($room->race_starts_at->timestamp)->toBe($startsAt->timestamp);
});

it('refuses to restart a finished room, so a rematch cannot reuse the same text', function () {
    [$component, $room] = startGuardRoom();

    $component->call('startRace');

    $room->refresh();
    $originalText = $room->text_to_type;

    $room->update(['status' => RoomStatus::Finished]);

    // Melewati playAgain() dan langsung memanggil startRace() dulu mengulang balapan dengan
    // paragraf yang persis sama, sambil mereset xp_earned supaya XP dan baris riwayat ditulis
    // lagi -- pengulangan teks yang sudah dihafal, tercatat permanen.
    $component->call('startRace');

    $room->refresh();

    expect($room->status)->toBe(RoomStatus::Finished);
    expect($room->text_to_type)->toBe($originalText);
});

it('still regenerates the text when the host takes the proper rematch path', function () {
    [$component, $room] = startGuardRoom();

    $component->call('startRace');
    $room->refresh();
    $originalText = $room->text_to_type;

    $room->update(['status' => RoomStatus::Finished]);
    $component->call('playAgain');

    $room->refresh();

    expect($room->status)->toBe(RoomStatus::Waiting);
    expect($room->text_to_type)->not->toBe($originalText);

    // Dan dari 'waiting' balapan berikutnya boleh dimulai seperti biasa.
    RoomMember::where('room_id', $room->id)->update(['is_ready' => true]);
    $component->call('startRace');

    expect($room->refresh()->status)->toBe(RoomStatus::Racing);
});

it('ignores a ready toggle once the room has left the lobby', function () {
    [$component, $room, , $rival] = startGuardRoom();

    $component->call('startRace');

    // is_ready hanya bermakna di lobby. Membiarkannya berubah saat balapan berjalan tidak
    // memberi keuntungan apa pun (startRace tidak membacanya), tapi guard-nya harus sama dengan
    // aksi lobby lain supaya tidak ada yang mengira kolom ini masih hidup mid-race.
    Livewire::actingAs($rival)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->call('toggleReady');

    $member = RoomMember::where('room_id', $room->id)->where('user_id', $rival->id)->first();

    // is_ready has no boolean cast on RoomMember, so this comes back as an int.
    expect((bool) $member->is_ready)->toBeTrue();
});
