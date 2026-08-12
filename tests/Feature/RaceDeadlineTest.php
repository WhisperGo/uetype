<?php

use App\Enums\RoomStatus;
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
        ->and($room->fresh()->status)->toBe(RoomStatus::Finished);
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
        ->and($room->fresh()->status)->toBe(RoomStatus::Racing);
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
        ->and($room->fresh()->status)->toBe(RoomStatus::Racing);
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
        ->and($room->fresh()->status)->toBe(RoomStatus::Racing);
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

    expect($room->fresh()->status)->toBe(RoomStatus::Finished)
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
    expect($room->fresh()->status)->toBe(RoomStatus::Racing)
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

    expect($room->fresh()->status)->toBe(RoomStatus::Finished)
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
        ->and($room->fresh()->status)->toBe(RoomStatus::Racing);
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

// ===== TITIK NOL KEDUA JAM =====

/**
 * KEDUA JAM MULAI DI `race_starts_at`, BUKAN SAAT ARENA DIRENDER.
 *
 * Ini gampang sekali terlewat: startRace() menulis `status = 'racing'` BERSAMAAN dengan
 * `race_starts_at = now() + COUNTDOWN_SECONDS`, jadi arena — beserta kedua jamnya — dirender
 * tiga detik SEBELUM race benar-benar dimulai. Selama tiga detik itu `now - race_starts_at`
 * bernilai negatif, dan `diffInSeconds($absolute: true)` membalikkannya jadi positif: server
 * melapor "sudah lewat 3 detik" untuk race yang belum jalan, lalu memotong kedua jam segitu.
 *
 * Akibatnya nyata dan per-pemain, bukan sekadar kosmetik: klien mengunci deadline dari sisa
 * detik SAAT HALAMANNYA dirender (armRaceDeadlines, earliest-wins), dan tiap pemain merender
 * di milidetik berbeda — host di T-3.0 mengunci detik 14, pemain lain di T-2.8 mengunci detik
 * 15, dan pemain yang reload di tengah race justru satu-satunya yang mengunci detik 20 yang
 * benar. Timer yang sama menunjukkan angka berbeda di tiap layar.
 *
 * Jawaban yang benar sudah ada di berkas yang sama sejak lama: getRaceStartsInMsProperty()
 * memakai selisih BERTANDA dan komentarnya menulis eksplisit "May be negative". Kedua accessor
 * ini menyalin polanya tanpa menyalin tandanya. Test inilah yang absen, dan itulah kenapa bug
 * ini lolos sementara seluruh test lain hijau — semuanya memanggil checkRaceDeadline() pada
 * race yang SUDAH berjalan, jadi tak satu pun pernah melewati jendela pra-start.
 */
it('starts both clocks at race_starts_at, not at the moment the arena renders', function () {
    $this->freezeTime();

    $host = User::factory()->create();

    // Race dijadwalkan tiga detik ke depan: persis keadaan selama hitung mundur 3-2-1.
    $room = deadlineRoom('DL0020', $host, -MultiplayerLobby::COUNTDOWN_SECONDS);
    deadlineRacer($room, $host);

    $lobby = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0020')
        ->set('step', 'racing')
        ->instance();

    // Pemain memang punya 3 detik hitung mundur DITAMBAH jendela penuhnya, bukan dikurangi.
    expect($lobby->startGraceRemaining)
        ->toBe(MultiplayerLobby::START_GRACE_SECONDS + MultiplayerLobby::COUNTDOWN_SECONDS)
        ->and($lobby->raceDeadlineRemaining)
        ->toBe(MultiplayerLobby::MAX_RACE_SECONDS + MultiplayerLobby::COUNTDOWN_SECONDS);
});

it('has both clocks read their full constant exactly at the start line', function () {
    $this->freezeTime();

    $host = User::factory()->create();

    $room = deadlineRoom('DL0021', $host, 0);
    deadlineRacer($room, $host);

    $lobby = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0021')
        ->set('step', 'racing')
        ->instance();

    expect($lobby->startGraceRemaining)->toBe(MultiplayerLobby::START_GRACE_SECONDS)
        ->and($lobby->raceDeadlineRemaining)->toBe(MultiplayerLobby::MAX_RACE_SECONDS);
});

it('counts both clocks down once the race is genuinely running', function () {
    $this->freezeTime();

    $host = User::factory()->create();

    $room = deadlineRoom('DL0022', $host, 5);
    deadlineRacer($room, $host);

    $lobby = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0022')
        ->set('step', 'racing')
        ->instance();

    expect($lobby->startGraceRemaining)->toBe(MultiplayerLobby::START_GRACE_SECONDS - 5)
        ->and($lobby->raceDeadlineRemaining)->toBe(MultiplayerLobby::MAX_RACE_SECONDS - 5);
});

/**
 * Sisi kedua dari bug yang sama: klien yang mengunci jam terlalu pendek akan bertanya ke server
 * TERLALU CEPAT, server menjawab "belum", dan karena pemicunya dulu sebuah $watch yang hanya
 * menyala sekali per perubahan nilai — dan nilainya lalu diam di 0 selamanya — tak ada yang
 * pernah bertanya lagi. Aturan 20 detik jadi tak pernah menjatuhkan siapa pun.
 *
 * Test ini mengunci setengah bagian server: bertanya sebelum waktunya tak boleh melakukan apa
 * pun, dan bertanya LAGI setelah waktunya harus tetap bekerja. Setengah bagian klien (pemicu
 * yang berulang, bukan sekali tembak) dijaga oleh assertion race-arena.js di bawah.
 */
it('stays answerable after an early check: asking too soon must not disarm the rule', function () {
    $diam = User::factory()->create();
    $ngetik = User::factory()->create();

    // Detik 14 -- persis titik di mana klien yang salah-kunci akan bertanya.
    $room = deadlineRoom('DL0023', $ngetik, MultiplayerLobby::START_GRACE_SECONDS - 6);
    $member = deadlineRacer($room, $diam);
    deadlineRacer($room, $ngetik, progress: 30);

    $lobby = Livewire::actingAs($diam)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0023')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    expect($member->fresh()->finished_time_seconds)->toBeNull();

    // Waktunya benar-benar habis, dan pertanyaan KEDUA harus tetap dilayani.
    $room->update(['race_starts_at' => now()->subSeconds(MultiplayerLobby::START_GRACE_SECONDS + 1)]);

    $lobby->call('checkRaceDeadline');

    expect($member->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS);
});

/**
 * Pemicu klien harus BERULANG, bukan sekali tembak.
 *
 * `$watch` di Alpine menyala pada PERUBAHAN nilai. Kedua jam menghitung mundur ke 0 lalu diam
 * di sana, jadi satu panggilan yang hilang — request gagal, tab ter-throttle, atau (seperti di
 * atas) waktunya belum tiba — berarti server tak pernah ditanya lagi dan race menggantung.
 * Justru race yang menggantung inilah yang jadi alasan fitur ini dibuat.
 *
 * Jadi pemicunya menumpang tick 1 detik milik store — sumber waktu yang sama yang membuat
 * sudden death kebal morph, dan alasan yang sama kenapa interval per-instance dibuang dulu.
 */
it('re-asks the server on every tick instead of once per crossing', function () {
    $arena = file_get_contents(resource_path('js/race-arena.js'));

    expect($arena)
        ->toContain('_clockTick')
        ->toContain("this.\$watch('_clockTick', askServerToResolve)")
        ->not->toContain("this.\$watch('startGraceRemaining', askServerToResolve)")
        ->not->toContain("this.\$watch('raceDeadlineRemaining', askServerToResolve)");
});

/**
 * Guard sudden death hanya boleh membungkus PLAFON. Plafon memang mundur selama sudden death
 * (resolveRaceDeadlinesIfElapsed), tapi grace sengaja terus berjalan menembusnya — dan klien
 * yang menolak bertanya membuat keputusan server itu tak pernah sampai ke mana-mana.
 */
it('keeps asking about the start grace while sudden death runs, but not about the ceiling', function () {
    $arena = file_get_contents(resource_path('js/race-arena.js'));

    expect($arena)
        ->toContain('this.raceDeadlineRemaining <= 0 && !this.suddenDeathActive')
        ->not->toContain('&& !this.suddenDeathActive && this.$wire');
});

// ===== PEMAIN YANG DIJATUHKAN HARUS DIBERI TAHU =====

/**
 * MENJATUHKAN PEMAIN TANPA MEMBERI TAHU DIA SAMA SAJA DENGAN TIDAK MENJATUHKANNYA.
 *
 * Server menandai racer yang diam sebagai DNF, tapi `$hasGivenUp` — satu-satunya hal yang
 * mengganti kotak ketik dengan panel hasil — hanya pernah diisi oleh giveUp() dan oleh
 * mount(). Jadi pemain yang baru saja dijatuhkan tetap melihat kotak ketiknya menyala,
 * kursornya berkedip, dan kata-katanya menyorot saat diketik: setiap emit progres yang ia
 * kirim diam-diam ditolak di updateRaceProgress() (`finished_time_seconds` sudah terisi)
 * tanpa satu pun umpan balik. Ia baru tahu ketika race berakhir, atau saat halaman dimuat
 * ulang.
 *
 * Ada dua jalan berbeda menuju keadaan itu, dan keduanya harus ditutup:
 *
 *   1. pemain itu sendiri yang bertanya (checkRaceDeadline miliknya yang menjatuhkannya);
 *   2. KLIEN LAIN yang bertanya lebih dulu. Resolusinya idempoten dan race-safe, jadi hanya
 *      SATU pemanggil yang benar-benar menulis — semua klien lain hanya menerima siaran
 *      RoomUpdated, dan penanganannya tak pernah menyentuh outcome untuk room yang masih
 *      `racing`. Jalur inilah yang paling sering terjadi di ruangan berisi banyak orang.
 */
it('tells a player straight away when their own check is what dropped them', function () {
    $diam = User::factory()->create();
    $ngetik = User::factory()->create();

    $room = deadlineRoom('DL0030', $ngetik, MultiplayerLobby::START_GRACE_SECONDS + 1);
    deadlineRacer($room, $diam);
    deadlineRacer($room, $ngetik, progress: 30);

    Livewire::actingAs($diam)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0030')
        ->set('step', 'racing')
        ->call('checkRaceDeadline')
        // Panel hasil menggantikan kotak ketik...
        ->assertSet('hasGivenUp', true)
        // ...dan arena Alpine ikut dikunci, supaya ketikan tak lagi masuk ke ruang hampa.
        ->assertDispatched('force-finish');
});

it('tells a player dropped by someone else’s check as soon as the room updates', function () {
    $diam = User::factory()->create();
    $ngetik = User::factory()->create();

    $room = deadlineRoom('DL0031', $ngetik, MultiplayerLobby::START_GRACE_SECONDS + 1);
    $member = deadlineRacer($room, $diam);
    deadlineRacer($room, $ngetik, progress: 30);

    // Klien LAIN yang memicu resolusinya; race tetap berjalan untuk yang masih mengetik.
    Livewire::actingAs($ngetik)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0031')
        ->set('step', 'racing')
        ->call('checkRaceDeadline');

    expect($member->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS)
        ->and($room->fresh()->status)->toBe(RoomStatus::Racing);

    Livewire::actingAs($diam)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0031')
        ->set('step', 'racing')
        ->set('hasGivenUp', false)
        ->call('roomUpdated')
        ->assertSet('hasGivenUp', true)
        ->assertDispatched('force-finish');
});

/** Kebalikannya sama pentingnya: yang masih balapan tak boleh kehilangan kotak ketiknya. */
it('leaves a racer who is still going alone when the room updates', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $room = deadlineRoom('DL0032', $a, 5);
    deadlineRacer($room, $a, progress: 12);
    deadlineRacer($room, $b, progress: 30);

    Livewire::actingAs($a)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0032')
        ->set('step', 'racing')
        ->call('roomUpdated')
        ->assertSet('hasGivenUp', false)
        ->assertSet('hasFinished', false)
        ->assertNotDispatched('force-finish');
});

/**
 * Seorang penonton tak pernah balapan, jadi ia tak bisa DNF — dan panel "kamu menyerah" akan
 * mengambil alih layar tontonannya kalau outcome ini salah diturunkan untuknya.
 */
it('never derives an outcome for a spectator', function () {
    $racer = User::factory()->create();
    $penonton = User::factory()->create();

    $room = deadlineRoom('DL0033', $racer, MultiplayerLobby::START_GRACE_SECONDS + 1);
    deadlineRacer($room, $racer, progress: 30);
    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $penonton->id, 'role' => RoomMember::ROLE_SPECTATOR,
        'is_ready' => true, 'progress_percent' => 0, 'wpm' => 0, 'accuracy' => 100,
    ]);

    Livewire::actingAs($penonton)->test(MultiplayerLobby::class)
        ->set('roomCode', 'DL0033')
        ->set('step', 'racing')
        ->call('roomUpdated')
        ->assertSet('hasGivenUp', false)
        ->assertSet('hasFinished', false);
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

    expect($room->fresh()->status)->toBe(RoomStatus::Finished);
});

/** Backstop malas: siapa pun yang membuka lobby ikut membereskan race yang sudah lewat batas. */
it('resolves an over-deadline race lazily on lobby mount', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();

    $room = deadlineRoom('DL0011', $a, MultiplayerLobby::MAX_RACE_SECONDS + 1);
    deadlineRacer($room, $a, progress: 10);
    deadlineRacer($room, $b, progress: 20);

    Livewire::actingAs($a)->test(MultiplayerLobby::class);

    expect($room->fresh()->status)->toBe(RoomStatus::Finished);
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
    expect($room->fresh()->status)->toBe(RoomStatus::Finished);
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

    expect($room->fresh()->status)->toBe(RoomStatus::Racing);
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
    expect($room->fresh()->status)->toBe(RoomStatus::Waiting)
        ->and($member->fresh()->finished_time_seconds)->toBeNull();
});
