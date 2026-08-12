<?php

use App\Livewire\MultiplayerLobby;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Services\AntiCheatService;
use Livewire\Livewire;

/**
 * finalizeRace() menulis BANYAK baris untuk BANYAK pemain: place & xp_earned di
 * room_members, total_xp di users, plus satu baris riwayat permanen per pemain.
 *
 * Tanpa transaksi, kegagalan di tengah loop meninggalkan balapan yang separuh
 * final: pemain yang keburu diproses sudah dapat EXP dan punya baris riwayat,
 * sisanya tidak -- dan karena guard idempoten `xp_earned IS NULL` menganggap
 * mereka sudah selesai, pemanggilan ulang TIDAK akan memperbaikinya. Hasilnya
 * permanen dan senyap.
 *
 * Transaksi dan guard idempoten menjaga hal yang BERBEDA dan keduanya tetap perlu:
 * transaksi melindungi dari tulis separuh jadi, guard melindungi dari pemanggilan
 * ganda (fast-path "semua finish" dan checkSuddenDeath bisa sama-sama memanggil).
 */
function racedRoom(int $playerCount): Room
{
    $host = User::factory()->create();

    $room = Room::create([
        'code' => 'FIN123',
        'host_id' => $host->id,
        'status' => 'racing',
        'text_to_type' => str_repeat('ab cde ', 14).'ab',
        'race_starts_at' => now()->subSeconds(60),
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true,
        'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 98, 'finished_time_seconds' => 30,
    ]);

    for ($i = 1; $i < $playerCount; $i++) {
        RoomMember::create([
            'room_id' => $room->id, 'user_id' => User::factory()->create()->id, 'is_ready' => true,
            'progress_percent' => 90, 'wpm' => 50, 'accuracy' => 95, 'finished_time_seconds' => 40 + $i,
        ]);
    }

    return $room;
}

it('awards nothing at all when finalization fails partway through', function () {
    $room = racedRoom(3);

    // Anti-cheat dipanggil sekali per pemain. Yang ini meledak di pemain KEDUA,
    // jadi pemain pertama sudah terlanjur diproses saat kegagalan terjadi.
    //
    // instance(), BUKAN bind(): finalizeRace() me-resolve service ini sekali per
    // pemain, dan bind() akan menyerahkan objek BARU tiap kali -- penghitungnya
    // ter-reset terus dan ledakannya tak pernah terjadi.
    $this->app->instance(AntiCheatService::class, new class extends AntiCheatService
    {
        private int $calls = 0;

        public function check(int $correctChars, int $totalChars, float $durationSeconds): array
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('ledakan di tengah finalisasi');
            }

            return parent::check($correctChars, $totalChars, $durationSeconds);
        }
    });

    try {
        Livewire::actingAs($room->host)->test(MultiplayerLobby::class)
            ->set('roomCode', 'FIN123')->set('step', 'racing')
            ->call('finalizeRace', $room->id);
    } catch (RuntimeException) {
        // Kegagalannya memang disengaja; yang diuji adalah JEJAK yang ia tinggalkan.
    }

    // Semua-atau-tidak-sama-sekali: tak seorang pun boleh membawa EXP, dan riwayat
    // permanen tak boleh memuat balapan yang tak pernah selesai difinalisasi.
    expect(MultiplayerMatchHistory::count())->toBe(0)
        ->and(RoomMember::where('room_id', $room->id)->whereNotNull('xp_earned')->count())->toBe(0)
        ->and(User::where('total_xp', '>', 0)->count())->toBe(0);
});

it('still finalizes everyone on the happy path', function () {
    $room = racedRoom(3);

    Livewire::actingAs($room->host)->test(MultiplayerLobby::class)
        ->set('roomCode', 'FIN123')->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    expect(MultiplayerMatchHistory::count())->toBe(3)
        ->and(RoomMember::where('room_id', $room->id)->whereNull('xp_earned')->count())->toBe(0)
        ->and(RoomMember::where('room_id', $room->id)->pluck('place')->sort()->values()->all())
        ->toBe([1, 2, 3]);
});
