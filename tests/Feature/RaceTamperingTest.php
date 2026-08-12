<?php

use App\Livewire\MultiplayerLobby;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * Race progress arrives from the client, so "I finished" is a single number a tampered
 * client can simply assert. WPM was already recomputed server-side, but PLACE is ranked by
 * finish TIME -- so a teleport to 100% still took the podium and pushed the real winner
 * down a slot in their permanent history.
 *
 * text_to_type is 100 characters so progress% maps 1:1 to correct characters.
 */
function tamperRoom(User $host, int $startedSecondsAgo): Room
{
    return Room::create([
        'code' => 'TMP'.random_int(100, 999),
        'host_id' => $host->id,
        'status' => 'racing',
        'text_to_type' => str_repeat('ab cde ', 14).'ab',
        'race_starts_at' => now()->subSeconds($startedSecondsAgo),
    ]);
}

function tamperMember(Room $room, User $user, int $progress = 0, string $role = RoomMember::ROLE_PLAYER): RoomMember
{
    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'is_ready' => true,
        'role' => $role,
        'progress_percent' => $progress,
        'wpm' => 0,
    ]);
}

function sendProgress(User $user, Room $room, int $progress, int $wpm = 0, int $accuracy = 100): void
{
    Livewire::actingAs($user)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('updateRaceProgress', $progress, $wpm, $accuracy);
}

it('refuses a finish claimed faster than a human can type', function () {
    // Jam dibekukan: durasi balapan dihitung server sebagai now() - race_starts_at, jadi tiap
    // detik NYATA antara penyiapan room dan pengiriman progress ikut menambah penyebutnya.
    // Room ini sengaja dibuat baru berjalan 2 detik supaya 100 karakter mustahil -- tapi kalau
    // eksekusinya sendiri memakan beberapa detik, klaimnya berubah jadi wajar dan test gagal
    // membuktikan apa pun.
    $this->freezeTime();

    $user = User::factory()->create();
    $room = tamperRoom($user, startedSecondsAgo: 2);
    $member = tamperMember($room, $user);

    // 100 characters in 2 seconds = 600 WPM.
    sendProgress($user, $room, 100, 999);

    $member->refresh();

    expect($member->finished_time_seconds)->toBeNull()
        ->and($member->place)->toBeNull();
});

/**
 * The gap the tighter race ceiling exists to close: against a ~240-character race text a
 * 10-second teleport reads as ~290 WPM, which slipped under the general 300 limit and was
 * therefore banked as a genuine win.
 */
it('refuses a finish that squeaks under the general wpm ceiling', function () {
    $user = User::factory()->create();

    // 240-character text finished in 10 seconds = ~288 WPM: below 300, above the race limit.
    $room = tamperRoom($user, startedSecondsAgo: 10);
    $room->update(['text_to_type' => str_repeat('abcde ', 40)]); // 240 characters
    $member = tamperMember($room, $user);

    sendProgress($user, $room, 100);

    $member->refresh();

    expect($member->finished_time_seconds)->toBeNull();
});

it('does not let progress move backwards', function () {
    $user = User::factory()->create();
    $room = tamperRoom($user, startedSecondsAgo: 30);
    $member = tamperMember($room, $user, progress: 80);

    // Rewinding would let a client replay the easy stretch of the text.
    sendProgress($user, $room, 10);

    expect($member->refresh()->progress_percent)->toBe(80);
});

it('ignores race progress sent by a spectator', function () {
    $host = User::factory()->create();
    $spectator = User::factory()->create();
    $room = tamperRoom($host, startedSecondsAgo: 40);
    $member = tamperMember($room, $spectator, role: RoomMember::ROLE_SPECTATOR);

    sendProgress($spectator, $room, 100, 200);

    $member->refresh();

    expect($member->progress_percent)->toBe(0)
        ->and($member->finished_time_seconds)->toBeNull()
        ->and($member->place)->toBeNull();
});

it('still accepts an honest finish', function () {
    $user = User::factory()->create();

    // 100 characters in 60 seconds = 20 WPM, entirely ordinary.
    $room = tamperRoom($user, startedSecondsAgo: 60);
    $member = tamperMember($room, $user);

    sendProgress($user, $room, 100, 20, 97);

    $member->refresh();

    expect($member->finished_time_seconds)->not->toBeNull()
        ->and($member->progress_percent)->toBe(100);
});

/**
 * The damaging part of the old behaviour: the cheat itself was thrown out at finalization
 * (no XP, no history row) but it had already consumed place 1, so the honest winner was
 * recorded as runner-up forever.
 */
it('does not let a rejected result steal the winner\'s place', function () {
    $cheater = User::factory()->create();
    $honest = User::factory()->create();

    $room = tamperRoom($cheater, startedSecondsAgo: 60);
    $cheatMember = tamperMember($room, $cheater);
    $honestMember = tamperMember($room, $honest);

    // The cheater's row is written straight to the DB, as though it had slipped past the
    // live gate: finished first, but at an impossible pace.
    $cheatMember->update([
        'progress_percent' => 100,
        'finished_time_seconds' => 2,
        'wpm' => 600,
        'accuracy' => 100,
    ]);

    // The honest player finishes later, at a believable speed.
    sendProgress($honest, $room, 100, 20, 98);

    $honestMember->refresh();
    $cheatMember->refresh();

    expect($honestMember->place)->toBe(1)
        ->and($cheatMember->result_recorded)->toBeFalse()
        ->and($cheatMember->place)->toBeNull();

    $history = MultiplayerMatchHistory::where('user_id', $honest->id)->first();

    expect($history)->not->toBeNull()
        ->and($history->place)->toBe(1)
        ->and($history->player_count)->toBe(1);
});

/**
 * Sisi LAYAR dari kasus di atas -- yang selama ini luput.
 *
 * Test sebelumnya membuktikan kolom `place` dan riwayat permanen sudah benar, tapi
 * modal hasil tidak pernah membaca kolom itu: ia merender posisi dalam koleksi
 * ($index + 1), diurutkan wpm DESC. Baris yang ditolak anti-cheat punya WPM
 * tertinggi justru karena dicurangi, jadi ia tetap berdiri di puncak podium dan
 * mendorong pemenang jujur ke posisi 2 -- persis kerusakan yang komentar di
 * FinalizesRace klaim sudah ditutup, tapi hanya ditutup di database.
 */
it('does not let a rejected result take the podium on screen', function () {
    $cheater = User::factory()->create(['username' => 'Cheater']);
    $honest = User::factory()->create(['username' => 'Honest']);

    $room = tamperRoom($cheater, startedSecondsAgo: 60);
    $cheatMember = tamperMember($room, $cheater);
    tamperMember($room, $honest);

    $cheatMember->update([
        'progress_percent' => 100,
        'finished_time_seconds' => 2,
        'wpm' => 600,
        'accuracy' => 100,
    ]);

    // Pemain jujur finis -> semua peserta selesai -> fast-path finalize + snapshot.
    // roomUpdated() menyusul karena fast-path menyiarkan RoomUpdated ke SEMUA klien
    // (bukan toOthers); itulah yang menyalakan modal hasil -- fast-path sendiri hanya
    // menyimpan snapshot.
    $comp = Livewire::actingAs($honest)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 20, 98)
        ->call('roomUpdated');

    $snapshot = collect($comp->get('resultSnapshot'));

    // Yang dirender paling atas harus pemenang sah, bukan yang WPM-nya tertinggi.
    expect($snapshot->first()['user_id'])->toBe($honest->id)
        ->and($snapshot->first()['place'])->toBe(1);

    // Hasil ditolak tidak membawa angka peringkat sama sekali -> view menampilkan tanda pisah.
    expect($snapshot->firstWhere('user_id', $cheater->id)['place'])->toBeNull();

    // Podium juara (blok tengah) menampilkan pemain jujur.
    $html = $comp->html();
    $marker = '<!-- PODIUM 1 (CENTER) -->';

    expect($html)->toContain($marker);

    $podium = substr($html, strpos($html, $marker), 600);

    expect($podium)->toContain('Honest')
        ->and($podium)->not->toContain('Cheater');
});

/**
 * Selama hitung mundur 3-2-1, room sudah berstatus 'racing' tapi race belum resmi mulai
 * (race_starts_at masih di masa depan). Client jujur baru mengirim progress SETELAH
 * countdown -- jadi progress yang datang lebih awal itu selalu palsu, dan menerimanya
 * membiarkan client tampered mengunci waktu finish selama jendela countdown.
 */
it('ignores race progress sent before the countdown finishes', function () {
    $user = User::factory()->create();
    // race_starts_at 2 detik di masa DEPAN -> hitung mundur belum selesai.
    $room = tamperRoom($user, startedSecondsAgo: -2);
    $member = tamperMember($room, $user);

    sendProgress($user, $room, 100, 200);

    $member->refresh();

    expect($member->progress_percent)->toBe(0)
        ->and($member->finished_time_seconds)->toBeNull()
        ->and($member->place)->toBeNull();
});

/**
 * updateRaceProgress adalah jalur terpanas race dan tiap panggilan menyiarkan ke seluruh
 * anggota room. Client jujur membatasi diri ~8 emit/detik (120ms); rate-limit server-side
 * (20/detik) meloloskan itu tapi memotong client yang di-script agar tak membanjiri.
 */
it('drops race progress updates that exceed the per-second rate limit', function () {
    $user = User::factory()->create();
    $room = tamperRoom($user, startedSecondsAgo: 40);
    $member = tamperMember($room, $user);

    // Penuhi kuota per-detik (20) langsung di limiter, sinkron dalam satu jendela 1 detik
    // supaya deterministik (20 panggilan Livewire beneran akan meluber >1 detik dan malah
    // keburu meluruh -- itu justru bukti window 1 detiknya cuma membatasi flood, bukan
    // pemakaian normal). Key-nya cerminan 'race-progress:'.Auth::id() di updateRaceProgress.
    $key = 'race-progress:'.$user->id;
    foreach (range(1, 20) as $ignored) {
        RateLimiter::hit($key, 1);
    }

    // Kuota sudah penuh -> tick ini ditolak throttle, progres tetap di nilai awal (0).
    sendProgress($user, $room, 90);

    expect($member->refresh()->progress_percent)->toBe(0);
});
