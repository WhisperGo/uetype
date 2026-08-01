<?php

use App\Events\RaceProgressUpdated;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    Event::fake([RaceProgressUpdated::class, RoomUpdated::class, SuddenDeathTriggered::class]);
});

/**
 * countdown_started_at adalah SATU-SATUNYA penanda bahwa sudden death berjalan:
 * checkSuddenDeath() langsung return kalau kolom ini null. Jadi kalau ada satu saja
 * jalur di mana pemain finish TANPA kolom ini terisi, race tak akan pernah ditutup
 * dan pemain yang tersisa menggantung selamanya.
 */
test('sudden death mulai saat pemain pertama finish', function () {
    $racer = User::factory()->create();
    $lain = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0001',
        'host_id' => $racer->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(10),
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $lain->id, 'is_ready' => true]);

    Livewire::actingAs($racer)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0001')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 80, 100);

    expect($room->fresh()->countdown_started_at)->not->toBeNull();
});

/**
 * REGRESI B3: guard lama berbunyi `$alreadyFinishedCount === 0 && ! countdown_started_at`.
 * Syarat "saya orang pertama" itu tak relevan DAN berbahaya: kalau sudah ada pemain
 * yang tercatat finish tapi countdown_started_at belum sempat terisi (jendela race
 * condition antara baca-hitung dan tulis-status), maka pemain BERIKUTNYA yang finish
 * akan gagal syarat `=== 0` -> timer tak pernah menyala -> race menggantung selamanya.
 */
test('sudden death tetap mulai walau sudah ada pemain lain yang tercatat finish', function () {
    $duluan = User::factory()->create();
    $berikutnya = User::factory()->create();
    $masihNgetik = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0002',
        'host_id' => $duluan->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(10),
        // Inti skenarionya: sudah ada yang finish, tapi timer BELUM menyala.
        'countdown_started_at' => null,
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $duluan->id,
        'is_ready' => true, 'progress_percent' => 100,
        'finished_time_seconds' => 8,   // sudah tercatat finish di DB
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $berikutnya->id, 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $masihNgetik->id, 'is_ready' => true]);

    Livewire::actingAs($berikutnya)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0002')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 75, 100);

    // Tanpa perbaikan: null -> checkSuddenDeath() return terus -> $masihNgetik menggantung.
    expect($room->fresh()->countdown_started_at)->not->toBeNull();
});

test('giveUp does not start sudden death (only a valid finish does)', function () {
    $menyerah = User::factory()->create();
    $masihNgetik = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0003',
        'host_id' => $menyerah->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(10),
        'countdown_started_at' => null,
    ]);

    RoomMember::create(['room_id' => $room->id, 'user_id' => $menyerah->id, 'is_ready' => true, 'progress_percent' => 20]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $masihNgetik->id, 'is_ready' => true, 'progress_percent' => 30]);

    Livewire::actingAs($menyerah)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0003')
        ->set('step', 'racing')
        ->call('giveUp');

    // Conceding is not a finish: the clock must stay off so the remaining player keeps
    // racing until someone actually finishes with a valid result.
    expect($room->fresh()->countdown_started_at)->toBeNull()
        ->and($room->fresh()->status)->toBe('racing');
});

/**
 * Timer TIDAK boleh di-reset oleh pemain kedua yang finish -- kalau di-reset, pemain
 * yang tersisa dapat perpanjangan waktu diam-diam setiap ada orang finish.
 */
test('pemain kedua yang finish tidak me-reset timer sudden death', function () {
    $pertama = User::factory()->create();
    $kedua = User::factory()->create();
    $ketiga = User::factory()->create();

    $mulaiTimer = now()->subSeconds(6);

    $room = Room::create([
        'code' => 'SD0004',
        'host_id' => $pertama->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(20),
        'countdown_started_at' => $mulaiTimer,   // timer sudah jalan 6 detik
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $pertama->id,
        'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 14,
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $kedua->id, 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $ketiga->id, 'is_ready' => true]);

    Livewire::actingAs($kedua)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0004')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 70, 100);

    expect($room->fresh()->countdown_started_at->timestamp)->toBe($mulaiTimer->timestamp);
});

/**
 * Sisa waktu dikirim sebagai DURASI, bukan timestamp akhir.
 *
 * Ini pelajaran yang sudah dipetik untuk countdown 3-2-1 (lihat multiplayer-race.md §3.3)
 * tapi belum diterapkan ke sudden death: klien dulu menghitung
 * `new Date(endTimeIso) - Date.now()`, yang menjadikan SELISIH JAM klien sebagai selisih
 * waktu. Jam klien yang cepat >= 15 detik menghasilkan sisa 0 -> lockRace() -> pemain
 * langsung dibekukan dari balapan padahal jatahnya masih penuh; jam yang lambat memberinya
 * jendela yang jauh lebih panjang. Dengan durasi relatif, jam klien tak lagi relevan.
 */
test('broadcasts the remaining seconds, not just an absolute end timestamp', function () {
    $racer = User::factory()->create();
    $lain = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0006',
        'host_id' => $racer->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(10),
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'is_ready' => true]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $lain->id, 'is_ready' => true]);

    Livewire::actingAs($racer)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0006')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 100, 80, 100);

    Event::assertDispatched(
        SuddenDeathTriggered::class,
        // Timer baru saja menyala, jadi sisanya jendela penuh.
        fn (SuddenDeathTriggered $e) => $e->remainingSeconds === 15
    );
});

test('the client prefers the relative duration over the client clock', function () {
    $echo = file_get_contents(resource_path('js/race-echo.js'));

    // Diuji lewat EKSPRESI-nya, bukan posisi kata: komentar di modul itu justru menyebut
    // pola lama yang sedang dipensiunkan (konvensi "komentar menjelaskan alasan"), jadi
    // assertion berbasis urutan teks akan menuduh prosanya sendiri.
    expect($echo)
        // Durasi dari server dipakai kalau ada...
        ->toContain("typeof e.remainingSeconds === 'number'")
        // ...dan perhitungan berbasis jam klien hanya cabang cadangan untuk bundle lama.
        ->and(strpos($echo, 'e.remainingSeconds'))
        ->toBeLessThan(strpos($echo, 'new Date(e.endTimeIso)'));
});

/** Setelah timer habis, race HARUS ditutup dan yang belum selesai ditandai DNF. */
/**
 * ===== KONTRAK TAMPILAN SUDDEN DEATH (markup) =====
 *
 * Keputusan: sudden death + timernya muncul di SATU tempat saja -- banner in-flow tepat di
 * atas kotak input. Badge yang dulu ada di header (di atas LIVE STANDINGS) dihapus, termasuk
 * di tampilan spectator (satu header yang sama).
 *
 * Bug yang diperbaiki sekaligus: timer bawah tak bergerak. Ia ada di dalam kontainer
 * mengetik yang di-morph Livewire ~8x/detik (emit progres), dan server merender counter itu
 * TANPA teks (nilainya klien-only via x-text). Tanpa wire:ignore tiap morph menghapus angka
 * yang sudah dirender Alpine, dan Alpine baru menuliskannya lagi di tick 1 detik berikutnya
 * -- angkanya beku di antara tick. Persis kelas bug transform paragraf (RaceMobileInputTest).
 */
test('badge sudden death di header dihapus; SD tampil in-flow, satu per penonton', function () {
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php')));

    // Dua tampilan in-flow yang SALING EKSKLUSIF per viewer: banner di atas kotak input
    // (racer) dan banner di layar tunggu (spectator/selesai/menyerah). Tak ada viewer yang
    // pernah melihatnya dua kali; badge di header (di atas LIVE STANDINGS) sudah tak ada.
    expect(substr_count($markup, 'multiplayer.sudden_death'))->toBe(2);

    // Badge header lama (x-text mentah) hilang; kedua banner in-flow pakai varian `+ 's'`.
    expect($markup)->not->toContain('x-text="suddenDeathRemaining"');
});

test('banner sudden death racer kebal morph lewat wire:ignore', function () {
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php')));

    // Banner di atas kotak input harus wire:ignore supaya morph ~8x/detik tak menghapus
    // x-text-nya dan membekukan angkanya.
    //
    // `!showStartPrompt` ikut di syaratnya sejak deadline mulai ditambahkan: pemain yang masih
    // di 0% dikuasai jam yang lebih dulu habis di antara grace dan sudden death, dan banner
    // mereka sendiri sudah menampilkan minimum itu — angka sudden death justru KELIRU untuk
    // mereka. Yang dijaga test ini tetap sama: wire:ignore-nya ada.
    expect($markup)->toContain('wire:ignore x-show="suddenDeathActive && raceStarted && !isFinished && !showStartPrompt"');
});

test('penonton (spectator/selesai/menyerah) tetap melihat countdown sudden death', function () {
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php')));

    // Layar tunggu tak punya kotak input, jadi butuh banner SD-nya sendiri -- juga wire:ignore
    // agar tak dibekukan morph. x-show="suddenDeathActive" (tanpa !isFinished) supaya pemain
    // yang sudah selesai/menyerah pun tetap melihat sisa waktu race yang masih berjalan.
    expect($markup)->toContain('wire:ignore x-show="suddenDeathActive"');
});

test('menghapus badge header tak mematikan start clock sudden death (hook sync tetap ada)', function () {
    $markup = tanpaKomentarBlade(file_get_contents(resource_path('views/livewire/multiplayer-lobby.blade.php')));

    // Hook x-init server-authoritative tetap ada (kini tak terlihat), jadi countdown tetap
    // menyala persis seperti sebelumnya saat server mengonfirmasi sudden death.
    expect($markup)->toContain('syncSuddenDeath(');
});

/**
 * REGRESI (akar masalah): timer sudden death dulu baru jalan setelah user REFRESH.
 *
 * Sebabnya: state SD dulu hidup di INSTANCE komponen Alpine (suddenDeathRemaining +
 * setInterval per-instance). Saat SD mulai, string x-data ikut berubah (suddenDeathActive
 * false -> true) sehingga Livewire morph bisa membuang instance lama & membuat yang baru;
 * interval + angka reaktif menempel di instance basi, sedangkan banner (wire:ignore) tak
 * di-rebind bersih -> banner MUNCUL tapi angkanya beku sampai refresh (init() membangun ulang).
 *
 * Perbaikan: pindahkan deadline SD ke GLOBAL STORE `race` pada jam MONOTONIC
 * (performance.now()) -- persis pola `deadline` countdown 3-2-1 & posisi maskot yang memang
 * kebal morph. Komponen hanya MEMBACA store, jadi instance mana pun yang bertahan pasca-morph
 * menampilkan angka yang identik, dan sisa waktu digerakkan oleh satu ticker `now` 1 detik
 * yang sudah ada -- bukan setInterval per-instance yang rentan.
 */
test('timer sudden death hidup di store yang kebal morph, bukan di instance komponen', function () {
    $arena = file_get_contents(resource_path('js/race-arena.js'));

    expect($arena)
        // Deadline & flag SD ada di store, di-arm dari sisa-detik server (idempotent, earliest-wins).
        ->toContain('armSuddenDeath')
        ->toContain('sdDeadline')
        // Sisa waktu diturunkan dari jam monotonic + ticker `now` store, bukan timer per-instance.
        ->toContain('sdRemainingSeconds')
        // Interval per-instance yang dulu bikin angka beku lintas morph sudah tak ada lagi.
        ->and($arena)->not->toContain('_sdInterval');
});

/**
 * REGRESI (server-authoritative, real time): sudden death dulu HANYA ditutup saat timer LOKAL
 * satu klien memanggil checkSuddenDeath() di 0. Untuk pemain yang MASIH mengetik, server tak
 * pernah menutup race secara real time dari aktivitasnya sendiri -- ia menunggu satu ping klien
 * yang bisa telat (server lambat), ter-throttle (tab background), atau hilang. Kini
 * updateRaceProgress() -- yang dipanggil ~8x/detik selama mengetik -- ikut menegakkan deadline:
 * begitu jendela 15 detik lewat, emit progres berikutnya dari si pengetik menutup race saat itu.
 */
test('a still-typing player closes the race in real time once the window elapses', function () {
    $finisher = User::factory()->create();
    $stillTyping = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0007',
        'host_id' => $finisher->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(40),
        'countdown_started_at' => now()->subSeconds(16),   // jendela 15s sudah lewat
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $finisher->id,
        'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 24,
    ]);
    $typing = RoomMember::create([
        'room_id' => $room->id, 'user_id' => $stillTyping->id,
        'is_ready' => true, 'progress_percent' => 40,
    ]);

    // Si pengetik hanya mengirim progres biasa (BUKAN finish). Sebelum perbaikan ini tak
    // berpengaruh pada sudden death; kini ia menutup race di server saat itu juga.
    Livewire::actingAs($stillTyping)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0007')
        ->set('step', 'racing')
        ->call('updateRaceProgress', 45, 60, 100)
        ->assertSet('showResultModal', true);

    expect($room->fresh()->status)->toBe('finished')
        ->and($typing->fresh()->finished_time_seconds)->toBe(RoomMember::DNF_SENTINEL_SECONDS);
});

test('checkSuddenDeath menutup race setelah 15 detik', function () {
    $pemenang = User::factory()->create();
    $tertinggal = User::factory()->create();

    $room = Room::create([
        'code' => 'SD0005',
        'host_id' => $pemenang->id,
        'status' => 'racing',
        'text_to_type' => 'the quick brown fox jumps over the lazy dog',
        'race_starts_at' => now()->subSeconds(40),
        'countdown_started_at' => now()->subSeconds(16),   // sudah lewat 15 detik
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $pemenang->id,
        'is_ready' => true, 'progress_percent' => 100, 'finished_time_seconds' => 24,
    ]);
    RoomMember::create(['room_id' => $room->id, 'user_id' => $tertinggal->id, 'is_ready' => true, 'progress_percent' => 40]);

    Livewire::actingAs($tertinggal)->test(MultiplayerLobby::class)
        ->set('roomCode', 'SD0005')
        ->set('step', 'racing')
        ->call('checkSuddenDeath')
        ->assertSet('showResultModal', true);

    expect($room->fresh()->status)->toBe('finished');
});
