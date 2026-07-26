<?php

use App\Livewire\MultiplayerLobby;
use App\Livewire\TypingEngine;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\TypingResult;
use App\Models\User;
use App\Models\UserAchievement;
use App\Services\SoloSessionGuard;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Sampai sekarang pemain tak pernah diberi tahu saat membuka achievement -- lencananya
 * hanya muncul diam-diam kalau ia kebetulan membuka /achievements.
 *
 * Separuh mekanismenya sebenarnya sudah ada: syncUnlocks() selalu mengembalikan daftar key
 * yang BARU terbuka, tapi TypingEngine membuang nilai baliknya. Berkas ini mengunci
 * penyalurannya sampai ke layar, plus satu celah yang selama ini tak terlihat: balapan
 * multiplayer memberi XP tapi tak pernah mencatat achievement sama sekali.
 */

/**
 * Sesi solo yang sah: mode di-set, lalu jam server dimundurkan seolah benar-benar diketik.
 * Tanpa backdate, SoloSessionGuard membatasi karakter berdasarkan waktu nyata yang berlalu
 * (~0 detik di test) dan menolak submitnya. $user null = tamu.
 */
function noticeSession(?User $user, int $chars, int $durationMs = 30000, ?int $correct = null)
{
    if ($user) {
        Livewire::actingAs($user);
    }

    $component = Livewire::test(TypingEngine::class)->call('setMode', 'time', '30');

    app(SoloSessionGuard::class)->backdate($durationMs / 1000);

    return $component->call('saveResult', [
        'durationMs' => $durationMs,
        'totalKeystrokes' => $chars,
        'correctKeystrokes' => $correct ?? $chars,
    ]);
}

/** Room yang sudah selesai dibalap, siap difinalisasi. Teks 100 karakter -> progress% = karakter. */
function noticeRacedRoom(User $host): Room
{
    $room = Room::create([
        'code' => 'ACH'.random_int(100, 999),
        'host_id' => $host->id,
        'status' => 'racing',
        'text_to_type' => str_repeat('ab cde ', 14).'ab',
        'race_starts_at' => now()->subSeconds(60),
    ]);

    RoomMember::create([
        'room_id' => $room->id, 'user_id' => $host->id, 'is_ready' => true,
        'progress_percent' => 100, 'wpm' => 60, 'accuracy' => 98, 'finished_time_seconds' => 30,
    ]);

    return $room;
}

/** Payload halaman hasil yang lengkap; hanya daftar unlock yang berbeda antar kasus. */
function noticeResultPage(User $user, array $newAchievements)
{
    app()->setLocale('en');

    session(['typing_result' => [
        'wpm' => 100.0, 'rawWpm' => 105, 'accuracy' => 98.0, 'time' => 30,
        'mode' => 'time', 'subMode' => '30', 'score' => null,
        'totalKeystrokes' => 250, 'correctKeystrokes' => 245, 'incorrectKeystrokes' => 5,
        'wpmHistory' => [90, 100], 'rawHistory' => [95, 105],
        'missedChars' => [], 'xpEarned' => 25,
        'isPersonalBest' => false, 'previousBest' => null, 'consistency' => 92,
        'levelData' => ['level' => 3, 'progress' => 40, 'needed' => 300, 'next_level' => 4],
        'drainEventCount' => 0, 'survivalPreviousBest' => null, 'isSurvivalPersonalBest' => false,
        'ghostResult' => null, 'afk' => false, 'newAchievements' => $newAchievements,
    ]]);

    return actingAs($user)->get('/result');
}

// ---- Penyaluran ke halaman hasil ----

it('reports a freshly unlocked achievement to the result screen', function () {
    $user = User::factory()->create();

    // 250 karakter / 30 detik = tepat 100 WPM -> ambang 'Speed Demon'.
    noticeSession($user, 250);

    expect(session('typing_result')['newAchievements'])->toContain('speed_demon');
});

it('stays quiet on a session that unlocks nothing new', function () {
    $user = User::factory()->create();

    noticeSession($user, 250);
    noticeSession($user, 250);

    // syncUnlocks hanya menyisipkan baris yang belum ada, jadi sesi kedua tak punya kabar.
    expect(session('typing_result')['newAchievements'])->toBe([]);
});

it('reports several at once', function () {
    $user = User::factory()->create();

    // Cepat DAN sempurna: 100 WPM membuka 'Speed Demon', akurasi 100% membuka 'Perfectionist'.
    noticeSession($user, 250, correct: 250);

    expect(session('typing_result')['newAchievements'])
        ->toContain('speed_demon')
        ->toContain('perfectionist');
});

/** Sesi yang ditinggalkan tak menulis apa pun, jadi tak ada yang bisa diumumkan. */
it('announces nothing for an abandoned run', function () {
    $user = User::factory()->create();

    // Diam 40 detik dari 60 -- jauh di atas ambang AFK.
    $component = Livewire::actingAs($user)->test(TypingEngine::class)->call('setMode', 'time', '60');
    app(SoloSessionGuard::class)->backdate(60);
    $component->call('saveResult', [
        'durationMs' => 60000, 'totalKeystrokes' => 500, 'correctKeystrokes' => 500, 'maxIdleMs' => 40000,
    ]);

    expect(session('typing_result')['newAchievements'])->toBe([])
        ->and(UserAchievement::where('user_id', $user->id)->count())->toBe(0);
});

it('does not break for a guest', function () {
    // Tamu tak punya achievement sama sekali -- yang dijaga di sini adalah halaman hasil
    // tetap merender, bukan meledak karena kunci yang tak ada.
    noticeSession(null, 250);

    expect(session('typing_result')['newAchievements'])->toBe([]);

    $this->get('/result')->assertOk();
});

// ---- Render ----

/**
 * Diumumkan lewat toast, bukan banner permanen. Halaman hasil menaruh muatannya di antrean
 * `__uetypeToasts`; toastStack menyerapnya begitu Alpine hidup. Antrean dipakai, bukan event
 * langsung, karena <x-toast-stack /> berada SETELAH slot halaman di app.blade.php -- jadi
 * komponen halaman ini selalu init lebih dulu dan event yang dikirim saat itu tak akan
 * ada yang mendengar.
 */
it('hands the unlocked achievement to the toast stack', function () {
    noticeResultPage(User::factory()->create(), ['speed_demon'])
        ->assertOk()
        ->assertSee('__uetypeToasts', false)
        ->assertSee(__('achievements.defs.speed_demon.title'), false);
});

it('lists every unlock in a single toast', function () {
    // Satu toast tampil pada satu waktu (push() menimpa, bukan menumpuk), jadi dua unlock
    // harus muat dalam SATU muatan -- kalau tidak, salah satunya hilang tanpa jejak.
    noticeResultPage(User::factory()->create(), ['speed_demon', 'perfectionist'])
        ->assertOk()
        ->assertSee(__('achievements.defs.speed_demon.title'), false)
        ->assertSee(__('achievements.defs.perfectionist.title'), false);
});

it('queues no toast when nothing was unlocked', function () {
    noticeResultPage(User::factory()->create(), [])
        ->assertOk()
        ->assertDontSee('__uetypeToasts', false);
});

// ---- Bug D: balapan multiplayer ----

/**
 * Balapan memberi XP lewat User::addExp() persis seperti solo, tapi syncUnlocks() tak
 * pernah dipanggil dari sana -- jadi pemain yang naik level di balapan tak pernah
 * tercatat, dan lencananya muncul tanpa tanggal. Balapan tak menulis baris
 * typing_results, jadi yang bisa terbuka di sini hanya achievement berbasis LEVEL.
 */
it('records a level achievement earned in a race', function () {
    // xpToReachLevel(10) = 100 x (10x9)/2 = 4500. Satu XP saja sudah cukup menyeberang.
    $host = User::factory()->create(['total_xp' => 4499]);
    $room = noticeRacedRoom($host);

    Livewire::actingAs($host)->test(MultiplayerLobby::class)
        ->set('roomCode', $room->code)->set('step', 'racing')
        ->call('finalizeRace', $room->id);

    expect($host->fresh()->levelData()['level'])->toBe(10)
        ->and(UserAchievement::where('user_id', $host->id)
            ->where('achievement_key', 'rising_star')->exists())->toBeTrue();
});

// ---- Backfill ----

/**
 * Akun yang syaratnya sudah lama terpenuhi tapi belum pernah tercatat akan memuntahkan
 * semuanya sekaligus sebagai "baru" di sesi berikutnya. Command ini mencatatnya lebih
 * dulu, tanpa diumumkan.
 */
it('backfills already met achievements without announcing them', function () {
    $user = User::factory()->create();

    // Rekor lama yang memenuhi syarat, tapi tanpa baris unlock -- data dari sebelum fitur ada.
    TypingResult::create([
        'user_id' => $user->id, 'mode' => 'time', 'mode_config' => '30',
        'net_wpm' => 120, 'raw_wpm' => 125, 'accuracy' => 96,
        'correct_chars' => 300, 'incorrect_chars' => 10, 'duration_seconds' => 30,
    ]);

    expect(UserAchievement::where('user_id', $user->id)->count())->toBe(0);

    $this->artisan('achievements:backfill')->assertSuccessful();

    expect(UserAchievement::where('user_id', $user->id)
        ->where('achievement_key', 'speed_demon')->exists())->toBeTrue();

    // Sesi berikutnya tak lagi menganggapnya baru. Sengaja TIDAK sempurna (245 dari 250):
    // akurasi 100% akan membuka 'Perfectionist', yang memang belum pernah diraih dan
    // karenanya sah dilaporkan sebagai baru -- itu akan menguji hal yang berbeda.
    noticeSession($user, 250, correct: 245);

    expect(session('typing_result')['newAchievements'])->toBe([]);
});
