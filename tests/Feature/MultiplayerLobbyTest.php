<?php

use App\Events\RaceProgressUpdated;
use App\Events\RoomMessageSent;
use App\Events\RoomPresenceChanged;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Livewire\MultiplayerLobby;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

describe('multiplayer lobby', function () {
    it('renders the lobby page for authenticated users', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/multiplayer');

        $response->assertOk();
        $response->assertSee('Create Room');
        $response->assertSee('Join Room');
    });

    it('renders the waiting room after creating a room', function () {
        /** @var User $user */
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(MultiplayerLobby::class)
            ->call('createRoom')
            ->assertSee('Room Code - Share with friends')
            ->assertSee('Players');
    });

    it('joins a room from the join-code array the paste handler populates', function () {
        // Handler paste menulis seluruh array joinCodeInput via $wire.set; ini
        // menegaskan kontrak itu: array 6-elemen -> joinRoom() memasukkan user.
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();
        $joiner = User::factory()->create();

        $room = Room::create([
            'code' => 'XYZ789',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $host->id,
            'is_ready' => false,
        ]);

        Livewire::actingAs($joiner)->test(MultiplayerLobby::class)
            ->set('joinCodeInput', ['X', 'Y', 'Z', '7', '8', '9'])
            ->call('joinRoom')
            ->assertSet('step', 'waiting');

        $this->assertDatabaseHas('room_members', [
            'room_id' => $room->id,
            'user_id' => $joiner->id,
        ]);
    });

    it('records the real elapsed time from race start when a player finishes', function () {
        // Broadcast di-fake supaya updateRaceProgress() tak mencoba konek Reverb asli.
        Event::fake([RaceProgressUpdated::class, RoomUpdated::class, SuddenDeathTriggered::class]);

        $user = User::factory()->create();

        // Race dimulai 30 detik yang lalu (race_starts_at di masa lalu), bukan baru saja.
        $room = Room::create([
            'code' => 'ABC123',
            'host_id' => $user->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(30),
            // updated_at sengaja SANGAT baru (mensimulasikan baris yang baru saja
            // ter-update oleh broadcast lain) -- inilah yang dulu keliru dipakai
            // sebagai basis durasi, menghasilkan angka kecil (mis. 2 detik).
        ]);
        $room->forceFill(['updated_at' => now()->subSeconds(2)])->save();

        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_ready' => true,
            'progress_percent' => 50,
            'wpm' => 60,
            'accuracy' => 100,
        ]);

        Livewire::actingAs($user)->test(MultiplayerLobby::class)
            ->set('roomCode', 'ABC123')
            ->set('step', 'racing')
            ->call('updateRaceProgress', 100, 60, 100);

        $seconds = RoomMember::where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->value('finished_time_seconds');

        // Harus ~30 detik (durasi asli sejak race_starts_at), BUKAN ~2 detik
        // (yang akan terjadi kalau masih memakai updated_at yang lama & keliru).
        expect($seconds)->toBeGreaterThanOrEqual(29);
        expect($seconds)->toBeLessThanOrEqual(31);
    });

    it('transfers host to the earliest remaining member when the host leaves', function () {
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();
        $racer = User::factory()->create();

        $room = Room::create([
            'code' => 'LEAVE1',
            'host_id' => $host->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(5),
        ]);
        foreach ([$host, $racer] as $u) {
            RoomMember::create(['room_id' => $room->id, 'user_id' => $u->id, 'is_ready' => true]);
        }

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'LEAVE1')
            ->set('step', 'racing')
            ->call('leaveRoom');

        // Room tetap ada, host berpindah ke racer, host lama tak lagi jadi member.
        $this->assertDatabaseHas('rooms', ['code' => 'LEAVE1', 'host_id' => $racer->id]);
        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $host->id]);
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $racer->id, 'is_ready' => true]);
    });

    it('deletes the room only when the last member leaves', function () {
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();

        $room = Room::create([
            'code' => 'SOLO01',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'SOLO01')
            ->set('step', 'waiting')
            ->call('leaveRoom');

        $this->assertDatabaseMissing('rooms', ['code' => 'SOLO01']);
    });

    it('marks the player DNF and keeps the room when giving up mid-race with others still racing', function () {
        Event::fake([RoomUpdated::class, SuddenDeathTriggered::class]);

        $quitter = User::factory()->create();
        $racer = User::factory()->create();

        $room = Room::create([
            'code' => 'GIVEUP',
            'host_id' => $quitter->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(5),
        ]);
        foreach ([$quitter, $racer] as $u) {
            RoomMember::create(['room_id' => $room->id, 'user_id' => $u->id, 'is_ready' => true, 'progress_percent' => 20]);
        }

        Livewire::actingAs($quitter)->test(MultiplayerLobby::class)
            ->set('roomCode', 'GIVEUP')
            ->set('step', 'racing')
            ->call('giveUp')
            ->assertSet('hasGivenUp', true)
            ->assertSee('You Gave Up')
            ->assertSee('Waiting for other players');

        // Quitter ditandai DNF (999), tetap jadi member, room tetap racing (racer belum selesai).
        $this->assertDatabaseHas('room_members', [
            'room_id' => $room->id,
            'user_id' => $quitter->id,
            'finished_time_seconds' => 999,
        ]);
        $this->assertDatabaseHas('rooms', ['code' => 'GIVEUP', 'status' => 'racing']);
        expect($room->fresh()->countdown_started_at)->not->toBeNull();
    });

    it('finishes the room when the last active player gives up', function () {
        Event::fake([RoomUpdated::class, SuddenDeathTriggered::class]);

        $quitter = User::factory()->create();

        $room = Room::create([
            'code' => 'ALLGUP',
            'host_id' => $quitter->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(5),
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $quitter->id, 'is_ready' => true, 'progress_percent' => 10]);

        Livewire::actingAs($quitter)->test(MultiplayerLobby::class)
            ->set('roomCode', 'ALLGUP')
            ->set('step', 'racing')
            ->call('giveUp')
            ->assertSet('showResultModal', true);

        $this->assertDatabaseHas('rooms', ['code' => 'ALLGUP', 'status' => 'finished']);
    });

    it('sends the remaining racer back to the choose screen when the room vanished', function () {
        Event::fake([RoomUpdated::class]);

        $racer = User::factory()->create();

        // Room sudah tak ada (semua keluar): racer menerima room-updated -> balik 'choose'.
        Livewire::actingAs($racer)->test(MultiplayerLobby::class)
            ->set('roomCode', 'GONE01')
            ->set('step', 'racing')
            ->call('roomUpdated')
            ->assertSet('step', 'choose')
            ->assertSet('roomCode', '')
            ->assertSee('Create Room')
            ->assertSee('Join Room');
    });

    it('recovers to the choose screen on render when the room vanished without an event', function () {
        // Reverb mati -> event room-updated tak pernah sampai. Penjaga di render()
        // tetap harus memulihkan, bukan merender halaman kosong.
        $racer = User::factory()->create();

        Livewire::actingAs($racer)->test(MultiplayerLobby::class)
            ->set('roomCode', 'GHOST1') // room tak pernah ada di DB
            ->set('step', 'racing')
            ->assertSet('step', 'choose')
            ->assertSee('Create Room');
    });

    it('shows the finished waiting screen (not a blank page) when finishing before others', function () {
        Event::fake([RaceProgressUpdated::class, RoomUpdated::class, SuddenDeathTriggered::class]);

        $finisher = User::factory()->create();
        $racer = User::factory()->create();

        $room = Room::create([
            'code' => 'WAIT01',
            'host_id' => $finisher->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(5),
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $finisher->id, 'is_ready' => true, 'progress_percent' => 90]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'is_ready' => true, 'progress_percent' => 30]);

        // Finisher menyelesaikan teks; racer belum -> room tetap racing.
        Livewire::actingAs($finisher)->test(MultiplayerLobby::class)
            ->set('roomCode', 'WAIT01')
            ->set('step', 'racing')
            ->call('updateRaceProgress', 100, 80, 100)
            ->assertSet('hasFinished', true)
            ->assertSet('showResultModal', false)
            ->assertSee('You Finished');

        $this->assertDatabaseHas('rooms', ['code' => 'WAIT01', 'status' => 'racing']);
    });

    it('freezes the result snapshot so leaving does not change the standings', function () {
        Event::fake([RoomUpdated::class, SuddenDeathTriggered::class]);

        $winner = User::factory()->create(['username' => 'Winner']);
        $loser = User::factory()->create(['username' => 'Loser']);

        $room = Room::create([
            'code' => 'FROZEN',
            'host_id' => $winner->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(5),
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $winner->id, 'is_ready' => true, 'wpm' => 90, 'progress_percent' => 100, 'finished_time_seconds' => 5]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $loser->id, 'is_ready' => true, 'wpm' => 40, 'progress_percent' => 60, 'finished_time_seconds' => 999]);

        $room->update(['status' => 'finished']);

        // Winner membuka result -> snapshot terbentuk berisi 2 pemain.
        $winnerComp = Livewire::actingAs($winner)->test(MultiplayerLobby::class)
            ->set('roomCode', 'FROZEN')
            ->set('step', 'racing')
            ->call('roomUpdated');

        expect($winnerComp->get('resultSnapshot'))->toHaveCount(2);

        // Loser keluar room -> baris room_members-nya dihapus.
        Livewire::actingAs($loser)->test(MultiplayerLobby::class)
            ->set('roomCode', 'FROZEN')
            ->set('step', 'racing')
            ->set('showResultModal', true)
            ->call('leaveRoom');

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $loser->id]);

        // Snapshot winner tetap 2 pemain (beku), Loser masih tampil di full results (nama diredup).
        $winnerComp->call('roomUpdated');
        expect($winnerComp->get('resultSnapshot'))->toHaveCount(2);
        $winnerComp->assertSee('Loser')->assertSee('Winner');
    });
});

describe('multiplayer spectators', function () {
    it('lets a member toggle to spectator and back while waiting', function () {
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();
        $member = User::factory()->create();

        $room = Room::create([
            'code' => 'SPEC01',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $member->id, 'role' => 'player', 'is_ready' => false]);

        $comp = Livewire::actingAs($member)->test(MultiplayerLobby::class)
            ->set('roomCode', 'SPEC01')
            ->set('step', 'waiting');

        $comp->call('toggleSpectator');
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $member->id, 'role' => 'spectator']);

        $comp->call('toggleSpectator');
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $member->id, 'role' => 'player']);
    });

    it('does not let spectators hold up finish detection or receive a placement', function () {
        Event::fake([RaceProgressUpdated::class, RoomUpdated::class, SuddenDeathTriggered::class]);

        $racer = User::factory()->create();
        $spectator = User::factory()->create();

        $room = Room::create([
            'code' => 'SPEC02',
            'host_id' => $racer->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
            'race_starts_at' => now()->subSeconds(5),
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $racer->id, 'role' => 'player', 'is_ready' => true, 'progress_percent' => 90]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $spectator->id, 'role' => 'spectator', 'is_ready' => false]);

        // Satu-satunya pembalap finish -> race harus benar-benar selesai meski penonton
        // masih punya finished_time_seconds = null (kalau tak difilter, ini menahannya).
        Livewire::actingAs($racer)->test(MultiplayerLobby::class)
            ->set('roomCode', 'SPEC02')
            ->set('step', 'racing')
            ->call('updateRaceProgress', 100, 80, 100)
            ->assertSet('hasFinished', true);

        // Room benar-benar ditutup: penonton (finished_time_seconds=null) tak menahannya.
        $this->assertDatabaseHas('rooms', ['code' => 'SPEC02', 'status' => 'finished']);

        // Penonton tak pernah diberi place/XP dan tak ditandai DNF.
        $spectatorRow = RoomMember::where('room_id', $room->id)->where('user_id', $spectator->id)->first();
        expect($spectatorRow->place)->toBeNull();
        expect($spectatorRow->xp_earned)->toBeNull();
        expect($spectatorRow->finished_time_seconds)->toBeNull();
    });

    it('overflows the sixth joiner into a spectator once players are full', function () {
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();
        $room = Room::create([
            'code' => 'SPEC03',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);

        // 5 pembalap penuh.
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        foreach (range(1, 4) as $i) {
            RoomMember::create(['room_id' => $room->id, 'user_id' => User::factory()->create()->id, 'role' => 'player', 'is_ready' => true]);
        }

        $sixth = User::factory()->create();
        Livewire::actingAs($sixth)->test(MultiplayerLobby::class)
            ->set('joinCodeInput', str_split('SPEC03'))
            ->call('joinRoom')
            ->assertSet('step', 'waiting');

        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $sixth->id, 'role' => 'spectator']);
    });

    it('refuses to start a race when everyone is a spectator', function () {
        Event::fake([RoomUpdated::class]);

        $host = User::factory()->create();
        $room = Room::create([
            'code' => 'SPEC04',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'spectator', 'is_ready' => false]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'SPEC04')
            ->set('step', 'waiting')
            ->call('startRace');

        // Room tetap waiting: tak ada pembalap untuk memulai.
        $this->assertDatabaseHas('rooms', ['code' => 'SPEC04', 'status' => 'waiting']);
    });
});

describe('multiplayer room chat', function () {
    it('broadcasts a room chat message from a member', function () {
        Event::fake([RoomMessageSent::class]);

        $host = User::factory()->create(['username' => 'host_chat']);
        $room = Room::create([
            'code' => 'CHAT01',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'CHAT01')
            ->set('step', 'waiting')
            ->call('sendRoomMessage', 'halo semuanya');

        Event::assertDispatched(RoomMessageSent::class, function ($e) use ($host) {
            return $e->roomCode === 'CHAT01'
                && $e->senderId === $host->id
                && $e->senderUsername === 'host_chat'
                && $e->body === 'halo semuanya';
        });
    });

    it('lets a spectator send chat messages too', function () {
        Event::fake([RoomMessageSent::class]);

        $host = User::factory()->create();
        $watcher = User::factory()->create(['username' => 'penonton']);
        $room = Room::create([
            'code' => 'CHAT02',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $watcher->id, 'role' => 'spectator', 'is_ready' => false]);

        Livewire::actingAs($watcher)->test(MultiplayerLobby::class)
            ->set('roomCode', 'CHAT02')
            ->set('step', 'waiting')
            ->call('sendRoomMessage', 'semangat!');

        Event::assertDispatched(RoomMessageSent::class, fn ($e) => $e->senderUsername === 'penonton' && $e->body === 'semangat!');
    });

    it('ignores chat from a non-member', function () {
        Event::fake([RoomMessageSent::class]);

        $host = User::factory()->create();
        $outsider = User::factory()->create();
        Room::create([
            'code' => 'CHAT03',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);

        Livewire::actingAs($outsider)->test(MultiplayerLobby::class)
            ->set('roomCode', 'CHAT03')
            ->set('step', 'waiting')
            ->call('sendRoomMessage', 'menyusup');

        Event::assertNotDispatched(RoomMessageSent::class);
    });

    it('does not broadcast an empty or whitespace-only message', function () {
        Event::fake([RoomMessageSent::class]);

        $host = User::factory()->create();
        $room = Room::create([
            'code' => 'CHAT04',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'CHAT04')
            ->set('step', 'waiting')
            ->call('sendRoomMessage', '   ');

        Event::assertNotDispatched(RoomMessageSent::class);
    });

    it('does not allow chat while a race is in progress', function () {
        Event::fake([RoomMessageSent::class]);

        $host = User::factory()->create();
        $room = Room::create([
            'code' => 'CHAT05',
            'host_id' => $host->id,
            'status' => 'racing',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

        Livewire::actingAs($host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'CHAT05')
            ->set('step', 'racing')
            ->call('sendRoomMessage', 'ngobrol saat balapan');

        Event::assertNotDispatched(RoomMessageSent::class);
    });
});

describe('multiplayer room presence', function () {
    it('broadcasts a join notification when someone joins the room', function () {
        Event::fake([RoomUpdated::class, RoomPresenceChanged::class]);

        $host = User::factory()->create();
        $joiner = User::factory()->create(['username' => 'pemain_baru']);
        $room = Room::create([
            'code' => 'JOIN01',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);

        Livewire::actingAs($joiner)->test(MultiplayerLobby::class)
            ->set('joinCodeInput', str_split('JOIN01'))
            ->call('joinRoom');

        Event::assertDispatched(RoomPresenceChanged::class, function ($e) {
            return $e->roomCode === 'JOIN01' && $e->username === 'pemain_baru' && $e->action === 'join';
        });
    });

    it('broadcasts a leave notification when a member leaves a room that still has others', function () {
        Event::fake([RoomUpdated::class, RoomPresenceChanged::class]);

        $host = User::factory()->create();
        $leaver = User::factory()->create(['username' => 'yang_keluar']);
        $room = Room::create([
            'code' => 'LEAV01',
            'host_id' => $host->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $host->id, 'role' => 'player', 'is_ready' => true]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $leaver->id, 'role' => 'player', 'is_ready' => false]);

        Livewire::actingAs($leaver)->test(MultiplayerLobby::class)
            ->set('roomCode', 'LEAV01')
            ->set('step', 'waiting')
            ->call('leaveRoom');

        Event::assertDispatched(RoomPresenceChanged::class, function ($e) {
            return $e->username === 'yang_keluar' && $e->action === 'leave';
        });
    });

    it('does not broadcast a leave notification when the last member leaves (room deleted)', function () {
        Event::fake([RoomUpdated::class, RoomPresenceChanged::class]);

        $solo = User::factory()->create();
        $room = Room::create([
            'code' => 'LEAV02',
            'host_id' => $solo->id,
            'status' => 'waiting',
            'text_to_type' => 'the quick brown fox',
        ]);
        RoomMember::create(['room_id' => $room->id, 'user_id' => $solo->id, 'role' => 'player', 'is_ready' => true]);

        Livewire::actingAs($solo)->test(MultiplayerLobby::class)
            ->set('roomCode', 'LEAV02')
            ->set('step', 'waiting')
            ->call('leaveRoom');

        // Tak ada yang mendengarkan lagi -> notif keluar tak perlu disiarkan.
        Event::assertNotDispatched(RoomPresenceChanged::class);
    });
});
