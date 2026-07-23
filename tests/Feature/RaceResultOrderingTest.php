<?php

use App\Livewire\MultiplayerLobby;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Livewire\Livewire;

/**
 * Peringkat yang DITAMPILKAN harus sama dengan peringkat yang DICATAT.
 *
 * Dulu keduanya dihitung sendiri-sendiri: modal hasil merender posisi dalam koleksi
 * ($index + 1, diurutkan wpm DESC) sementara riwayat permanen memakai kolom `place`
 * dari writeFinalStandings (diurutkan waktu finis). Selama kedua urutan itu kebetulan
 * sama tak ada yang sadar -- dan untuk pemain yang sama-sama menyelesaikan balapan
 * memang selalu sama, karena teksnya identik sehingga waktu dan WPM berbanding lurus.
 *
 * Yang memisahkannya adalah DNF: sentinel 999 detik dipasang ke finished_time_seconds,
 * tapi `wpm` yang sempat terkumpul dibiarkan utuh. Pemain yang menyerah setelah
 * mengetik cepat jadi naik di atas penyelesai yang lebih lambat -- hanya di tampilan.
 *
 * Nama helper sengaja berprefiks: helper Pest bersifat global lintas file.
 */
function orderingRoom(User $host): Room
{
    return Room::create([
        'code' => 'ORD'.random_int(100, 999),
        'host_id' => $host->id,
        'status' => 'racing',
        'text_to_type' => str_repeat('ab cde ', 14).'ab',
        'race_starts_at' => now()->subSeconds(60),
        // Sudden death sudah lewat -> checkSuddenDeath() memanggil finalizeRace().
        'countdown_started_at' => now()->subSeconds(60),
    ]);
}

function orderingMember(Room $room, User $user, int $wpm, int $progress, int $seconds): RoomMember
{
    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true,
        'wpm' => $wpm,
        'accuracy' => 100,
        'progress_percent' => $progress,
        'finished_time_seconds' => $seconds,
    ]);
}

/** Potongan HTML milik satu blok podium; komentar HTML-nya ikut terender. */
function orderingPodium(string $html, string $marker): string
{
    $start = strpos($html, $marker);

    if ($start === false) {
        return '';
    }

    // Sampai awal blok podium berikutnya (atau ujung dokumen).
    $next = strpos($html, '<!-- PODIUM', $start + strlen($marker));

    return substr($html, $start, $next === false ? 400 : $next - $start);
}

it('shows the finisher above a higher-wpm quitter, matching the recorded standings', function () {
    $finisher = User::factory()->create(['username' => 'Finisher']);
    $quitter = User::factory()->create(['username' => 'Quitter']);

    $room = orderingRoom($finisher);

    // Menyelesaikan balapan dalam 40 detik -> pemenang sah.
    orderingMember($room, $finisher, wpm: 60, progress: 100, seconds: 40);

    // Menyerah di 80% (sentinel DNF 999) tapi WPM tersimpan LEBIH TINGGI.
    orderingMember($room, $quitter, wpm: 90, progress: 80, seconds: RoomMember::DNF_SENTINEL_SECONDS);

    $comp = Livewire::actingAs($finisher)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('checkSuddenDeath');

    $snapshot = collect($comp->get('resultSnapshot'));
    $recordedFirst = MultiplayerMatchHistory::where('place', 1)->value('user_id');

    // Riwayat permanen memang sudah benar sejak dulu -- yang diuji di sini tampilannya.
    expect($recordedFirst)->toBe($finisher->id);

    // Urutan tampil mengikuti kolom otoritatif, bukan WPM.
    expect($snapshot->first()['user_id'])->toBe($finisher->id)
        ->and($snapshot->first()['place'])->toBe(1)
        ->and($snapshot->last()['place'])->toBe(2);

    // Podium juara diisi penyelesai, bukan yang menyerah.
    $html = $comp->html();

    expect(orderingPodium($html, '<!-- PODIUM 1 (CENTER) -->'))->toContain('Finisher')
        ->and(orderingPodium($html, '<!-- PODIUM 1 (CENTER) -->'))->not->toContain('Quitter');
});

it('keeps the displayed rank identical to the rank written to history', function () {
    $finisher = User::factory()->create(['username' => 'Finisher']);
    $quitter = User::factory()->create(['username' => 'Quitter']);

    $room = orderingRoom($finisher);
    orderingMember($room, $finisher, wpm: 60, progress: 100, seconds: 40);
    orderingMember($room, $quitter, wpm: 90, progress: 80, seconds: RoomMember::DNF_SENTINEL_SECONDS);

    $comp = Livewire::actingAs($finisher)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('checkSuddenDeath');

    // Urutan snapshot ADALAH yang dirender ($index + 1 di tabel, get(0..2) di podium),
    // jadi membandingkannya dengan urutan riwayat menguji tepat invariannya:
    // yang dilihat user = yang tercatat. Membandingkan key 'place' saja tidak cukup --
    // kolom itu memang selalu benar; yang dulu salah adalah urutan barisnya.
    $displayed = collect($comp->get('resultSnapshot'))->pluck('user_id')->all();
    $recorded = MultiplayerMatchHistory::orderBy('place')->pluck('user_id')->all();

    expect($displayed)->toBe($recorded);
});
