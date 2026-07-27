<?php

use App\Events\RoomUpdated;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

/**
 * Countdown "3-2-1-GO!" harus jatuh pada saat yang sama untuk semua pemain, dan
 * tak boleh terulang saat komponen di-render ulang di tengah hitung mundur.
 *
 * Kontraknya: server mengirim SISA WAKTU (ms), bukan jam absolutnya. Klien
 * mengunci tenggat itu sekali per race pada jam monotonik (performance.now()),
 * jadi morph Livewire / re-init Alpine hanya melanjutkan hitungan yang sama.
 */
function lobbySource(): string
{
    return file_get_contents(app_path('Livewire/MultiplayerLobby.php'));
}

/** Markup + modul JS arena; lihat arenaSourceAll() di RaceLiveWpmTest. */
function arenaBlade(): string
{
    return arenaSourceAll();
}

/**
 * Room siap-mulai. Kalau pemanggil tak menyebut peserta lain, SATU pembalap tambahan tetap
 * dibuat: startRace() menolak room dengan kurang dari MIN_PLAYERS_TO_START pembalap, dan
 * berkas ini menguji sinkronisasi hitung mundur -- bukan aturan mulai. Tanpa pembalap kedua
 * tiap test gagal karena balapannya tak pernah dimulai, yang menyesatkan.
 */
function racingRoomFor(User $host, array $others = []): Room
{
    $room = Room::create([
        'code' => 'CNT123',
        'host_id' => $host->id,
        'status' => 'waiting',
        'text_to_type' => 'aa bb cc',
    ]);

    if ($others === []) {
        $others = [User::factory()->create()];
    }

    foreach ([$host, ...$others] as $u) {
        RoomMember::create(['room_id' => $room->id, 'user_id' => $u->id, 'is_ready' => true]);
    }

    return $room;
}

it('schedules the race start in the future when the host presses start', function () {
    Event::fake([RoomUpdated::class]);
    $host = User::factory()->create();
    racingRoomFor($host);

    $c = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'CNT123')->set('step', 'waiting')
        ->call('startRace');

    $remaining = $c->instance()->raceStartsInMs;

    // Sisa waktu, bukan jam absolut. Harus positif & mendekati 3 detik.
    expect($remaining)->toBeGreaterThan(2000)
        ->and($remaining)->toBeLessThanOrEqual(3000);
});

it('sends the remaining time in milliseconds, not rounded to whole seconds', function () {
    $host = User::factory()->create();
    $room = racingRoomFor($host);

    // Catatan: kolom race_starts_at bertipe `timestamp` (presisi detik), jadi titik
    // startnya memang dibulatkan. Yang penting: pembulatan itu SAMA untuk semua
    // pemain -- jadi tak membuat mereka tak sinkron -- dan sisa waktunya sendiri
    // dihitung dari now() presisi milidetik, bukan dari selisih detik bulat.
    $room->update(['status' => 'racing', 'race_starts_at' => now()->addSeconds(3)]);

    $remaining = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'CNT123')->set('step', 'racing')
        ->instance()->raceStartsInMs;

    // Ada di antara 2 dan 3 detik, dan hampir pasti bukan kelipatan 1000 --
    // artinya milidetik dari now() benar-benar terbawa, bukan dibulatkan.
    expect($remaining)->toBeGreaterThan(1500)
        ->and($remaining)->toBeLessThanOrEqual(3000)
        ->and($remaining % 1000)->not->toBe(0);
});

it('gives every player the same start instant even though the column stores whole seconds', function () {
    $host = User::factory()->create();
    $guest = User::factory()->create();
    $room = racingRoomFor($host, [$guest]);
    $room->update(['status' => 'racing', 'race_starts_at' => now()->addSeconds(3)]);

    // Dua pemain merender pada saat yang berbeda. Sisa waktu masing-masing berbeda,
    // tapi titik akhirnya (render_time + sisa) harus sama -> mereka GO! bersamaan.
    $hostRemaining = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'CNT123')->set('step', 'racing')->instance()->raceStartsInMs;
    $hostAt = microtime(true);

    usleep(150_000); // 150ms kemudian guest baru merender

    $guestRemaining = Livewire::actingAs($guest)->test(MultiplayerLobby::class)
        ->set('roomCode', 'CNT123')->set('step', 'racing')->instance()->raceStartsInMs;
    $guestAt = microtime(true);

    // Guest merender belakangan -> sisa waktunya lebih kecil.
    expect($guestRemaining)->toBeLessThan($hostRemaining);

    // Titik start absolut yang mereka tuju identik (toleransi 60ms untuk overhead tes).
    $hostGoAt = $hostAt + $hostRemaining / 1000;
    $guestGoAt = $guestAt + $guestRemaining / 1000;
    expect(abs($hostGoAt - $guestGoAt))->toBeLessThan(0.06);
});

it('returns a negative remaining time for a player who arrives after the start', function () {
    $host = User::factory()->create();
    $room = racingRoomFor($host);
    $room->update(['status' => 'racing', 'race_starts_at' => now()->subSeconds(2)]);

    $remaining = Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'CNT123')->set('step', 'racing')
        ->instance()->raceStartsInMs;

    // Negatif -> klien langsung beginRace(), tanpa countdown palsu.
    expect($remaining)->toBeLessThan(0);
});

it('returns null when no race is scheduled', function () {
    $host = User::factory()->create();
    $room = racingRoomFor($host);
    $room->update(['status' => 'racing', 'race_starts_at' => null]);

    expect(Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'CNT123')->set('step', 'racing')
        ->instance()->raceStartsInMs)->toBeNull();
});

it('does not broadcast the race start back to the host', function () {
    // startRace() sudah menyetel step='racing' untuk host. Kalau room.updated ikut
    // dikirim ke host, Livewire re-render di tengah countdown -> arena di-morph ->
    // hitung mundur mulai lagi dari 3, dan host tertahan di overlay.
    expect(lobbySource())->toContain('broadcast(new RoomUpdated($this->roomCode))->toOthers());');

    // Aturannya khusus startRace(). Jalur lain (giveUp, sudden death) memang SENGAJA
    // menyiarkan ke diri sendiri juga, karena pengirimnya perlu ikut re-render.
    //
    // Dulu irisan ini dibatasi oleh nama method BERIKUTNYA di file (`checkRoomStatus`),
    // sehingga diam-diam mengunci urutan method -- memindah salah satunya ke trait
    // mematahkannya dengan pesan yang menyesatkan. Sekarang batasnya deklarasi method
    // apa pun yang menyusul, jadi urutannya bebas berubah.
    // Batasnya: deklarasi method berikutnya ATAU akhir file -- startRace bisa saja
    // menjadi method terakhir.
    preg_match(
        '/public function startRace\(\).*?(?=\n    (?:public|private|protected) function |\z)/s',
        lobbySource(),
        $m
    );

    expect($m)->not->toBeEmpty('Method startRace() tak ditemukan')
        ->and($m[0])->toContain('->toOthers()');
});

it('locks the countdown deadline once per race so a re-render cannot restart it', function () {
    $blade = arenaBlade();

    // Tenggat disimpan di store (bertahan lintas morph), bukan di state komponen.
    expect($blade)->toContain('armCountdown(key, remainingMs)')
        ->and($blade)->toContain('if (this.raceKey === key && this.deadline !== null) return;');

    // Countdown membaca tenggat store, bukan menghitung ulang dari config tiap init.
    expect($blade)->toContain('this.$store.race.armCountdown(this.raceKey(), this.raceStartsInMs)')
        ->and($blade)->toContain('const remainingMs = this.$store.race.remainingMs();');
});

it('counts down on a monotonic clock, not the wall clock', function () {
    $blade = arenaBlade();

    // performance.now() kebal terhadap jam sistem yang digeser/di-sync NTP.
    expect($blade)->toContain('performance.now()');

    // Offset jam server-klien yang lama sudah dibuang: ia salah menghitung latensi
    // jaringan sebagai selisih jam, dan toIso8601String() memotong milidetik.
    expect($blade)->not->toContain('clockOffsetMs')
        ->and(lobbySource())->not->toContain('getServerNowProperty');
});

it('skips the countdown overlay entirely when the arena remounts after the start', function () {
    $blade = arenaBlade();

    // raceStarted adalah state komponen: re-init Alpine mengembalikannya ke false
    // dan overlay "3" muncul lagi. Karena tenggatnya ada di store, komponen yang
    // baru di-mount tahu race sudah jalan dan langsung beginRace() tanpa berkedip.
    expect($blade)->toContain('if (this.$store.race.remainingMs() <= 0) {')
        ->and($blade)->toContain('this.beginRace();');
});

it('clears the countdown deadline when a different race starts', function () {
    $blade = arenaBlade();

    // Rematch: raceKey berubah -> tenggat lama harus dibuang, bukan dipakai ulang.
    // Assert kode-nya, bukan teks komentar di belakangnya (komentar bisa diterjemahkan).
    expect($blade)->toContain('this.deadline = null;');
});
