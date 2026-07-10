<?php

use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Js;
use Livewire\Livewire;

/**
 * Bagian "race track" di layar balapan: satu baris per pemain berisi peringkat,
 * nama, lintasan, bendera finis, dan WPM. Peringkat dihitung di klien dari store
 * Alpine supaya ikut bergerak tanpa re-render Livewire; tes ini menjaga kontrak
 * data yang dikirim Blade ke store itu.
 */
function racingRoom(array $players): array
{
    $host = null;
    $room = null;
    $members = [];

    foreach ($players as $i => $spec) {
        $user = User::factory()->create(['username' => $spec['username']]);

        if ($i === 0) {
            $host = $user;
            $room = Room::create([
                'code' => 'TRK123',
                'host_id' => $user->id,
                'status' => 'racing',
                'text_to_type' => 'the quick brown fox',
                'race_starts_at' => now()->subSeconds(5),
            ]);
        }

        $members[] = RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_ready' => true,
            'progress_percent' => $spec['progress'] ?? 0,
            'wpm' => $spec['wpm'] ?? 0,
            'finished_time_seconds' => $spec['finished'] ?? null,
        ]);
    }

    return [$host, $room, $members];
}

function renderTrack(User $actor): string
{
    return Livewire::actingAs($actor)->test(MultiplayerLobby::class)
        ->set('roomCode', 'TRK123')
        ->set('step', 'racing')
        ->html();
}

it('renders one lane per player with name, wpm and a finish flag', function () {
    [$host] = racingRoom([
        ['username' => 'Narutooo', 'progress' => 90, 'wpm' => 71],
        ['username' => 'whisper', 'progress' => 70, 'wpm' => 64],
        ['username' => 'Lutpi08', 'progress' => 50, 'wpm' => 55],
    ]);

    $html = renderTrack($host);

    foreach (['Narutooo', 'whisper', 'Lutpi08'] as $name) {
        expect($html)->toContain($name);
    }

    // Satu bendera finis per lane, bukan satu untuk seluruh papan.
    expect(substr_count($html, 'race-finish-flag'))->toBe(3);

    // WPM dirender live lewat Alpine (x-text), bukan angka statis dari Blade.
    expect(substr_count($html, 'x-text="liveWpmValue"'))->toBe(3);
    expect(substr_count($html, 'x-text="liveRank"'))->toBe(3);
});

it('seeds every player progress so ranks are correct before any websocket payload', function () {
    [$host, , $members] = racingRoom([
        ['username' => 'Narutooo', 'progress' => 90],
        ['username' => 'whisper', 'progress' => 70],
        ['username' => 'Lutpi08', 'progress' => 50],
    ]);

    $html = renderTrack($host);

    // rankOf() memeringkat dari laneSeeds selama store masih kosong. Tanpa seed
    // yang LENGKAP (id => progress tiap pemain) semua lane akan tampil sebagai
    // peringkat 1 di detik-detik pertama balapan.
    // Bandingkan dengan keluaran @js() yang sebenarnya (kutip di-escape jadi "),
    // bukan tebakan bentuk stringnya.
    $expected = (string) Js::from([
        $members[0]->user_id => 90,
        $members[1]->user_id => 70,
        $members[2]->user_id => 50,
    ]);

    expect($html)->toContain($expected);

    expect($html)->toContain('rankOf(this.playerId, laneSeeds)');
});

it('highlights only the lane of the signed in player', function () {
    [, , $members] = racingRoom([
        ['username' => 'Narutooo'],
        ['username' => 'whisper'],
        ['username' => 'Lutpi08'],
    ]);

    $me = User::where('username', 'whisper')->firstOrFail();
    $html = renderTrack($me);

    // Kartu biru + chip "YOU" hanya sekali, untuk pemain yang sedang login.
    expect(substr_count($html, 'bg-brand/25'))->toBe(1)
        ->and(substr_count($html, '>YOU</span>'))->toBe(1);
});

it('lands the runner exactly on the finish flag at 100%', function () {
    [$host] = racingRoom([['username' => 'Narutooo', 'progress' => 100]]);

    $html = renderTrack($host);

    // Maskot & bendera sama-sama di-center (-translate-x-1/2) pada titik yang sama
    // saat progres 100%: maskot di `14px + (100% - 28px) * 100/100` = `100% - 14px`,
    // bendera di `100% - 14px`. Kalau salah satu bergeser, maskot tak mendarat di bendera.
    expect($html)->toContain('left: calc(100% - 14px);')                       // bendera
        ->and($html)->toContain('left: calc(14px + (100% - 28px) * ${liveProgress} / 100)'); // maskot

    // Bendera harus di DALAM lintasan (absolute), bukan elemen sebelahnya —
    // kalau di luar, progres 100% berhenti sebelum bendera.
    expect($html)->toContain('race-finish-flag absolute');
});

it('keeps the runner inside the lane at both ends', function () {
    [$host] = racingRoom([['username' => 'Narutooo', 'progress' => 0]]);

    $html = renderTrack($host);

    // Rel disisipkan selebar setengah maskot di kedua sisi, jadi maskot yang
    // di-center di 0% maupun 100% tak terpotong tepi baris.
    expect($html)->toContain('left: 14px; right: 14px;');
});

it('keeps the runner on the flag in the dense layout too', function () {
    // >= 4 pemain -> layout rapat: maskot 24px, jadi offsetnya 12px, bukan 14px.
    [$host] = racingRoom([
        ['username' => 'Narutooo'], ['username' => 'whisper'],
        ['username' => 'Lutpi08'], ['username' => 'Sasuke'],
    ]);

    $html = renderTrack($host);

    expect($html)->toContain('left: calc(100% - 12px);')
        ->and($html)->toContain('left: calc(12px + (100% - 24px) * ${liveProgress} / 100)')
        ->and($html)->toContain('left: 12px; right: 12px;');
});

it('marks a finished player as finished rather than dropping the state', function () {
    [$host] = racingRoom([['username' => 'Narutooo', 'progress' => 100, 'finished' => 12]]);

    $html = renderTrack($host);

    // Desain ini tak punya badge "FINISHED"; statusnya diwarnai hijau (token active).
    expect($html)->toContain('liveFinished')
        ->and($html)->toContain('border-active/70');
});
