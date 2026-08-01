<?php

use App\Events\RoomUpdated;
use App\Livewire\MultiplayerLobby;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * ===== BATAS WAKTU RACE =====
 *
 * Sebelum ini, SATU-SATUNYA yang bisa menutup sebuah race adalah sudden death — dan sudden
 * death hanya menyala setelah ada finisher VALID pertama (startSuddenDeathIfNeeded, dipanggil
 * dari updateRaceProgress saat progress mencapai 100%). Konsekuensinya: kalau tak seorang pun
 * pernah menyentuh garis finish, `countdown_started_at` tak pernah terisi, dan room tinggal
 * di status 'racing' SELAMANYA.
 *
 * Jaring pengaman yang ada tak menolong: sweepAbandonedRaces() menuntut SELURUH member
 * offline, sedangkan pemain yang duduk diam di arena tetap mengirim heartbeat presence tiap
 * 30 detik — jadi ia "online" selamanya dan room-nya tak pernah tersapu.
 *
 * Dua ambang menutup ini, keduanya diturunkan dari `rooms.race_starts_at` yang SUDAH ADA
 * (tanpa kolom/migrasi baru):
 *
 *   START_GRACE_SECONDS — racer yang belum mengetik apa pun langsung di-DNF.
 *   MAX_RACE_SECONDS    — batas keras: race ditutup apa pun yang terjadi.
 *
 * Keduanya ditegakkan lewat resolveRaceDeadlinesIfElapsed(), mengikuti pola yang sudah
 * terbukti di resolveSuddenDeathIfElapsed(): idempoten, race-safe lewat update bersyarat,
 * dan dipanggil dari jalur progres (real time) MAUPUN timer klien (untuk pemain yang diam).
 */
beforeEach(function () {
    Event::fake([RoomUpdated::class]);
});

/** Teks race sepanjang aslinya (45 kata) supaya matematika 1% = ~3 karakter tetap berlaku. */
function deadlineText(): string
{
    return trim(str_repeat('lorem ipsum dolor sit amet consectetur adipiscing elit sed do ', 5));
}

function deadlineRoom(string $code, User $host, int $startedSecondsAgo): Room
{
    return Room::create([
        'code' => $code,
        'host_id' => $host->id,
        'status' => 'racing',
        'text_to_type' => deadlineText(),
        'race_starts_at' => now()->subSeconds($startedSecondsAgo),
    ]);
}

function deadlineRacer(Room $room, User $user, int $progress = 0): RoomMember
{
    return RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true,
        'progress_percent' => $progress,
        'wpm' => 0,
        'accuracy' => 100,
    ]);
}

// ===== DEADLINE MULAI =====

it('marks a racer who never started typing as DNF once the grace window elapses', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $room = deadlineRoom('DL0001', $a, MultiplayerLobby::START_GRACE_SECONDS + 1);
    $memberA = deadlineRacer($room, $a);
    $memberB = deadlineRacer($room, $b);

    Livewire::actingAs($a)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0001')
        ->set('step', 'racing')
        ->call('checkRaceDeadline')
        ->assertSet('showResultModal', true);

    // Tak seorang pun memulai -> keduanya DNF dan race ditutup, bukan menggantung selamanya.
    expect($memberA->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS)
        ->and($memberB->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS)
        ->and($room->fresh()->status)->toBe('finished');
});

/**
 * REGRESI PEMAIN JUJUR — ini penjaga terpenting di berkas ini.
 *
 * Penanda "belum mulai" adalah `progress_percent === 0`, dan itu aman justru karena teks race
 * panjang: 45 kata ≈ 250-290 karakter, dan klien memakai Math.floor(). Jadi 0% berarti KURANG
 * DARI ~3 karakter diketik. Setelah 20 detik, bahkan pemula 5 WPM sudah mengetik ~8 karakter
 * (≈3%), jadi ia tak pernah tersentuh aturan ini.
 *
 * Kalau ambangnya suatu saat dinaikkan atau teks race dipendekkan, test inilah yang jatuh
 * lebih dulu — dan itu memang gunanya.
 */
it('never DNFs a slow racer who has genuinely started (1% is started)', function () {
    $lambat = User::factory()->create();
    $cepat = User::factory()->create();

    $room = deadlineRoom('DL0002', $cepat, MultiplayerLobby::START_GRACE_SECONDS + 1);
    $memberLambat = deadlineRacer($room, $lambat, progress: 1);
    deadlineRacer($room, $cepat, progress: 40);

    Livewire::actingAs($lambat)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0002')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    expect($memberLambat->fresh()->finished_time_seconds)->toBeNull()
        ->and($room->fresh()->status)->toBe('racing');
});

it('DNFs only the idle racer and lets the race continue for whoever is still typing', function () {
    $diam = User::factory()->create();
    $ngetik = User::factory()->create();

    $room = deadlineRoom('DL0003', $ngetik, MultiplayerLobby::START_GRACE_SECONDS + 1);
    $memberDiam = deadlineRacer($room, $diam);
    $memberNgetik = deadlineRacer($room, $ngetik, progress: 35);

    Livewire::actingAs($ngetik)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0003')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    // Yang diam dibuang; yang mengetik TIDAK boleh ikut dipotong balapannya.
    expect($memberDiam->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS)
        ->and($memberNgetik->fresh()->finished_time_seconds)->toBeNull()
        ->and($room->fresh()->status)->toBe('racing');
});

it('leaves everyone alone while the grace window is still open', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $room = deadlineRoom('DL0004', $a, MultiplayerLobby::START_GRACE_SECONDS - 5);
    $memberA = deadlineRacer($room, $a);
    $memberB = deadlineRacer($room, $b);

    Livewire::actingAs($a)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0004')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    expect($memberA->fresh()->finished_time_seconds)->toBeNull()
        ->and($memberB->fresh()->finished_time_seconds)->toBeNull()
        ->and($room->fresh()->status)->toBe('racing');
});

it('never touches a spectator: they are not racing, so they cannot be DNF', function () {
    $racer = User::factory()->create();
    $penonton = User::factory()->create();

    $room = deadlineRoom('DL0005', $racer, MultiplayerLobby::START_GRACE_SECONDS + 1);
    deadlineRacer($room, $racer);
    $watcher = RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $penonton->id,
        'role' => RoomMember::ROLE_SPECTATOR,
        'is_ready' => false,
        'progress_percent' => 0,
    ]);

    Livewire::actingAs($penonton)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0005')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    expect($watcher->fresh()->finished_time_seconds)->toBeNull();
});

// ===== BATAS KERAS RACE =====

/**
 * Deadline mulai hanya menangkap yang tak pernah MEMULAI. Pemain yang mengetik sedikit lalu
 * berhenti melewatinya — dan tanpa batas kedua, race-nya tetap menggantung. Batas keras ini
 * yang menutup kasus itu.
 */
it('force-closes the race at the hard limit, DNFing whoever has not finished', function () {
    $berhenti = User::factory()->create();
    $jugaBerhenti = User::factory()->create();

    $room = deadlineRoom('DL0006', $berhenti, MultiplayerLobby::MAX_RACE_SECONDS + 1);
    $memberA = deadlineRacer($room, $berhenti, progress: 30);
    $memberB = deadlineRacer($room, $jugaBerhenti, progress: 55);

    Livewire::actingAs($berhenti)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0006')
        ->set('step', 'racing')
        ->call('checkRaceDeadline')
        ->assertSet('showResultModal', true);

    expect($room->fresh()->status)->toBe('finished')
        ->and($memberA->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS)
        ->and($memberB->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS);
});

it('keeps a genuine finisher’s time when the hard limit closes the race', function () {
    $selesai = User::factory()->create();
    $tertinggal = User::factory()->create();

    $room = deadlineRoom('DL0007', $selesai, MultiplayerLobby::MAX_RACE_SECONDS + 1);
    $finisher = RoomMember::create([
        'room_id' => $room->id, 'user_id' => $selesai->id, 'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true, 'progress_percent' => 100, 'accuracy' => 97, 'wpm' => 60,
        'finished_time_seconds' => 90,
    ]);
    $memberTertinggal = deadlineRacer($room, $tertinggal, progress: 40);

    Livewire::actingAs($tertinggal)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0007')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    // Batas keras hanya menyentuh yang BELUM selesai; hasil sah tak boleh ditimpa DNF.
    expect($finisher->fresh()->finished_time_seconds)->toBe(90)
        ->and($finisher->fresh()->place)->toBe(1)
        ->and($memberTertinggal->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS)
        ->and($memberTertinggal->fresh()->place)->toBeNull();
});

// ===== PLAFON KERAS vs SUDDEN DEATH =====

/**
 * Dua jam bisa hidup bersamaan, dan yang satu tak boleh memotong yang lain.
 *
 * Pemain pertama finish di detik 170 menyalakan sudden death sampai detik 185 — tapi plafon
 * keras jatuh di detik 180. Tanpa penjaga ini, pemain yang tersisa hanya dapat 10 dari 15
 * detik jatahnya, dan yang paling merusak: banner di layarnya **masih menunjukkan sisa 5
 * detik** saat server sudah menutup race. Itu persis kelas bug yang sudah dua kali dibayar di
 * §3.3 dan §3.4 — pemain melihat jendela yang tak benar-benar ia punya.
 *
 * Plafon keras adalah jaring anti-menggantung. Sudden death yang berjalan membuktikan race ini
 * TIDAK menggantung (ada yang finish) dan pasti menutup dirinya dalam <=15 detik, jadi jaring
 * itu tak punya alasan untuk ikut campur.
 */
it('does not let the hard limit cut a running sudden-death window short', function () {
    $finisher = User::factory()->create();
    $masihNgetik = User::factory()->create();

    // Race sudah lewat plafon keras, tapi sudden death baru berjalan 11 dari 15 detik.
    $room = deadlineRoom('DL0015', $finisher, MultiplayerLobby::MAX_RACE_SECONDS + 1);
    $room->update(['countdown_started_at' => now()->subSeconds(11)]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $finisher->id, 'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true, 'progress_percent' => 100, 'accuracy' => 97, 'wpm' => 60,
        'finished_time_seconds' => 170,
    ]);
    $member = deadlineRacer($room, $masihNgetik, progress: 80);

    Livewire::actingAs($masihNgetik)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0015')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    // 4 detik terakhirnya utuh: race masih jalan, dan ia belum di-DNF.
    expect($room->fresh()->status)->toBe('racing')
        ->and($member->fresh()->finished_time_seconds)->toBeNull();
});

/** Setelah jendelanya benar-benar habis, sudden death sendiri yang menutup — bukan menggantung. */
it('still closes once the sudden-death window itself elapses past the hard limit', function () {
    $finisher = User::factory()->create();
    $masihNgetik = User::factory()->create();

    $room = deadlineRoom('DL0016', $finisher, MultiplayerLobby::MAX_RACE_SECONDS + 20);
    $room->update(['countdown_started_at' => now()->subSeconds(16)]); // 15 dtk sudah lewat

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $finisher->id, 'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true, 'progress_percent' => 100, 'accuracy' => 97, 'wpm' => 60,
        'finished_time_seconds' => 170,
    ]);
    $member = deadlineRacer($room, $masihNgetik, progress: 80);

    Livewire::actingAs($masihNgetik)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0016')
        ->set('step', 'racing')
        ->call('checkSuddenDeath');

    expect($room->fresh()->status)->toBe('finished')
        ->and($member->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS);
});

/**
 * Aturan MULAI, berbeda dari plafon, tetap berjalan MENEMBUS sudden death.
 *
 * Versi pertama membuatnya ikut berhenti, dan itu diam-diam membatalkan janji yang jadi alasan
 * aturan ini ada: lawan cepat yang finish di detik 8 membuka jendela sudden death sampai detik
 * 23, dan sejak saat itu pemain yang diam tak pernah lagi dinilai aturan 20 detik — jendelanya
 * berubah jadi `min(20, waktu_finish + 15)`, padahal layarnya menjanjikan 20.
 *
 * Dua puluh detik harus berarti dua puluh detik, atau ia bukan aturan yang bisa dijadikan
 * pegangan pemain.
 */
it('keeps enforcing the start grace right through a sudden-death window', function () {
    $finisher = User::factory()->create();
    $belumNgetik = User::factory()->create();
    $ngetik = User::factory()->create();

    // Finish cepat di detik 8 -> sudden death sampai detik 23. Grace tetap jatuh di detik 20.
    $room = deadlineRoom('DL0017', $finisher, MultiplayerLobby::START_GRACE_SECONDS + 1);
    $room->update(['countdown_started_at' => now()->subSeconds(13)]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $finisher->id, 'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true, 'progress_percent' => 100, 'accuracy' => 98, 'wpm' => 90,
        'finished_time_seconds' => 8,
    ]);
    $diam = deadlineRacer($room, $belumNgetik);
    $aktif = deadlineRacer($room, $ngetik, progress: 45);

    Livewire::actingAs($belumNgetik)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0017')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    // Yang diam tetap dijatuhkan aturan 20 detik; yang mengetik tak tersentuh dan race
    // dibiarkan sudden death yang menutup.
    expect($diam->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS)
        ->and($aktif->fresh()->finished_time_seconds)->toBeNull()
        ->and($room->fresh()->status)->toBe('racing');
});

/**
 * Yang ditampilkan ke pemain diam harus deadline SEBENARNYA, yaitu yang lebih dulu habis dari
 * kedua jam. Menampilkan grace saja menjanjikan waktu yang akan dirampas sudden death;
 * menampilkan sudden death saja menjanjikan waktu yang akan dirampas grace.
 */
it('shows an idle racer the earlier of the two clocks, never just one of them', function () {
    $arena = file_get_contents(resource_path('js/race-arena.js'));
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php')));

    expect($arena)
        ->toContain('Math.min(this.startGraceRemaining, this.suddenDeathRemaining)');

    // Banner memakai minimum itu, bukan angka grace mentah.
    expect($markup)
        ->toContain('x-text="startPromptRemaining + \'s\'"')
        ->not->toContain('x-text="startGraceRemaining + \'s\'"');

    // Dan banner sudden death mundur untuk pemain itu, supaya tak ada dua timer bertumpuk
    // yang salah satunya menunjukkan angka yang keliru untuknya.
    expect($markup)->toContain('suddenDeathActive && raceStarted && !isFinished && !showStartPrompt');
});

/**
 * PERMINTAAN EKSPLISIT: batas 180 detik harus TERLIHAT. Batas yang tak terlihat membuat race
 * yang tiba-tiba ditutup terasa seperti bug, bukan aturan.
 */
it('shows the race clock to racers and spectators alike', function () {
    $arena = file_get_contents(resource_path('js/race-arena.js'));
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php')));

    // m:ss, bukan detik mentah -- "147" tak terbaca sebagai waktu pada skala ini.
    expect($arena)->toContain('raceClockLabel');

    // Ada di header bersama (racer + spectator memakai header yang sama), dan kebal morph.
    expect($markup)
        ->toContain('wire:ignore x-show="showRaceClock"')
        ->toContain('multiplayer.race_time_left')
        ->toContain('x-text="raceClockLabel"');
});

/** Jam race disembunyikan saat sudden death: plafonnya memang berhenti berlaku di situ. */
it('hides the race clock once the ceiling stops governing', function () {
    $arena = file_get_contents(resource_path('js/race-arena.js'));

    expect($arena)->toContain('this.raceStarted && !this.suddenDeathActive && this.raceDeadlineRemaining > 0');
});

// ===== KONSEKUENSI HASIL =====

/**
 * DNF hasil timeout harus diperlakukan persis seperti DNF lain (menyerah / AFK saat sudden
 * death): tak masuk riwayat permanen dan tak dapat XP. Kalau tidak, sesi "0 WPM karena tak
 * pernah mulai" akan menyeret turun rata-rata pemain selamanya — persis alasan aturan
 * "DNF tak pernah dicatat" ada (lihat anti-cheat-wpm.md §5.2).
 */
it('writes no history row and awards no XP for a timeout DNF', function () {
    $a = User::factory()->create(['total_xp' => 0]);
    $b = User::factory()->create(['total_xp' => 0]);

    $room = deadlineRoom('DL0008', $a, MultiplayerLobby::START_GRACE_SECONDS + 1);
    deadlineRacer($room, $a);
    deadlineRacer($room, $b);

    Livewire::actingAs($a)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0008')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    expect(MultiplayerMatchHistory::count())->toBe(0)
        ->and($a->fresh()->total_xp)->toBe(0)
        ->and($b->fresh()->total_xp)->toBe(0);
});

/**
 * Sama seperti resolveSuddenDeathIfElapsed(): update bersyarat `where('status','racing')`
 * memastikan TEPAT SATU pemanggil yang memfinalisasi, berapa pun klien yang memicu bersamaan.
 * Tanpa itu, finalizeRace() jalan dua kali dan XP/riwayat bisa ganda.
 */
it('resolves exactly once when two clients trigger the deadline together', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $room = deadlineRoom('DL0009', $a, MultiplayerLobby::MAX_RACE_SECONDS + 1);
    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $a->id, 'role' => RoomMember::ROLE_PLAYER,
        'is_ready' => true, 'progress_percent' => 100, 'accuracy' => 96, 'wpm' => 55,
        'finished_time_seconds' => 100,
    ]);
    deadlineRacer($room, $b, progress: 20);

    foreach ([$a, $b] as $pemicu) {
        Livewire::actingAs($pemicu)->test(MultiplayerLobby::class)
            ->set('roomCode', 'DL0009')
            ->set('step', 'racing')
            ->call('checkRaceDeadline');
    }

    // Satu finisher sah -> tepat satu baris riwayat, walau dua klien memicu penutupan.
    expect(MultiplayerMatchHistory::where('room_code', 'DL0009')->count())->toBe(1);
});

// ===== JALUR PENEGAKAN =====

/**
 * Jalur real-time: pemain yang MASIH mengetik menutup race lewat emit progresnya sendiri
 * (~8x/detik), jadi penegakan tak menunggu timer lokal satu klien. Pola yang sama sudah
 * dipakai sudden death (lihat SuddenDeathTimerTest).
 */
it('enforces the hard limit from the progress path in real time', function () {
    $ngetik = User::factory()->create();
    $lain = User::factory()->create();

    $room = deadlineRoom('DL0010', $ngetik, MultiplayerLobby::MAX_RACE_SECONDS + 1);
    deadlineRacer($room, $ngetik, progress: 40);
    deadlineRacer($room, $lain, progress: 30);

    Livewire::actingAs($ngetik)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0010')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 45, 50, 98)
        ->assertSet('showResultModal', true);

    expect($room->fresh()->status)->toBe('finished');
});

/** Backstop malas: siapa pun yang membuka lobby ikut membereskan race yang sudah lewat batas. */
it('resolves an over-deadline race lazily on lobby mount', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $room = deadlineRoom('DL0011', $a, MultiplayerLobby::MAX_RACE_SECONDS + 1);
    deadlineRacer($room, $a, progress: 10);
    deadlineRacer($room, $b, progress: 20);

    Livewire::actingAs($a)->test(MultiplayerLobby::class);

    expect($room->fresh()->status)->toBe('finished');
});

/**
 * TEMUAN 7 — room 'racing' yang kehilangan SELURUH racer.
 *
 * Racer terakhir boleh keluar mid-race lewat leave-confirm (itu pilihan eksplisit dan memang
 * diizinkan). Kalau yang tersisa hanya penonton, tak ada lagi yang bisa memicu finish atau
 * giveUp — satu-satunya jalur yang memanggil finalizeRace. Room-nya macet permanen, dan
 * sweepAbandonedRaces() tak menyentuhnya selama penonton itu masih online.
 */
it('finalizes a racing room that has lost every racer, leaving only spectators', function () {
    $penonton = User::factory()->create();

    $room = deadlineRoom('DL0012', $penonton, 5); // masih jauh di dalam kedua deadline
    RoomMember::create([
        'room_id' => $room->id,
        'user_id' => $penonton->id,
        'role' => RoomMember::ROLE_SPECTATOR,
        'is_ready' => false,
    ]);

    Livewire::actingAs($penonton)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0012')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    // Tak ada balapan tanpa pembalap: room ditutup alih-alih menjebak penonton di arena kosong.
    expect($room->fresh()->status)->toBe('finished');
});

it('does not close a racing room that still has a racer', function () {
    $racer = User::factory()->create();
    $penonton = User::factory()->create();

    $room = deadlineRoom('DL0013', $racer, 5);
    deadlineRacer($room, $racer, progress: 15);
    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $penonton->id,
        'role' => RoomMember::ROLE_SPECTATOR, 'is_ready' => false,
    ]);

    Livewire::actingAs($penonton)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0013')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    expect($room->fresh()->status)->toBe('racing');
});

it('does nothing for a room that is not racing', function () {
    $host = User::factory()->create();

    $room = Room::create([
        'code' => 'DL0014',
        'host_id' => $host->id,
        'status' => 'waiting',
        'text_to_type' => deadlineText(),
        'race_starts_at' => now()->subSeconds(MultiplayerLobby::MAX_RACE_SECONDS + 1),
    ]);
    $member = deadlineRacer($room, $host);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0014')
        ->set('step', 'waiting')
        ->call('checkRaceDeadline');

    // race_starts_at basi milik race SEBELUMNYA tak boleh menutup lobby yang sedang menunggu.
    expect($room->fresh()->status)->toBe('waiting')
        ->and($member->fresh()->finished_time_seconds)->toBeNull();
});
