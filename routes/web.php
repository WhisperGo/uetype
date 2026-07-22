<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MultiplayerPresenceController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Livewire\About;
use App\Livewire\Chat;
use App\Livewire\ClanLeaderboard;
use App\Livewire\Clans;
use App\Livewire\ClanShow;
use App\Livewire\ClanWar;
use App\Livewire\Friends;
use App\Livewire\Settings;
use App\Livewire\Stats;
use App\Livewire\Terms;
use App\Livewire\TypingEngine;
use App\Livewire\TypingResult;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', '/typing')->name('home');

// Mesin ketik solo & halaman hasilnya terbuka untuk tamu (sama seperti '/'):
// TypingEngine menjaga tiap akses Auth dengan Auth::check(), dan TypingResult
// membaca dari session. Tamu bisa mengetik, cuma tak dapat XP/rekor.
Route::get('/typing', TypingEngine::class)->name('typing');
Route::get('/result', TypingResult::class)->name('typing.result');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'me'])->name('profile.me');

    // Profil publik user lain (dibuka dari daftar teman / hasil pencarian).
    // Dirujuk lewat username, bukan ID, supaya ID user (dan jumlah total
    // user terdaftar) tidak bisa dienumerasi dengan mengubah angka di URL.
    Route::get('/users/{user:username}', [ProfileController::class, 'show'])->name('profile.show');

    Route::get('/settings', Settings::class)->name('settings');

    // Heartbeat presence: klien ping berkala supaya last_seen_at tetap segar
    // (deteksi online/offline di daftar teman).
    // Throttle 30/menit: klien normal hanya 2/menit (interval 30 detik), tapi tiap
    // tab kembali visible memicu ping ekstra -- batas ini menyisakan ruang untuk
    // burst wajar itu tanpa membiarkan endpoint dibanjiri.
    Route::post('/heartbeat', [PresenceController::class, 'heartbeat'])
        ->middleware('throttle:30,1')
        ->name('presence.heartbeat');

    Route::get('/achievements', [AchievementController::class, 'index'])->name('achievements.index');

    Route::get('/stats', Stats::class)->name('stats');

    Route::get('/friends', Friends::class)->name('friends.index');
    // Pending-request count for the nav badge: the nav is a static partial and can't
    // re-query itself, so it polls this lightweight endpoint on friendship events.
    Route::get('/friends/pending-count', [FriendController::class, 'pendingCount'])
        ->name('friends.pending-count');
    Route::get('/chat', Chat::class)->name('chat.index');
    // Kirim pesan lewat endpoint ringan (paralel, di luar antrean Livewire)
    // supaya spam pesan tak saling menunggu.
    // Throttle longgar (60/menit) justru KARENA endpoint ini didesain untuk burst:
    // batas ketat akan memutus pengetik cepat, bukan penyerang.
    Route::post('/chat/send', [ChatController::class, 'send'])
        ->middleware('throttle:60,1')
        ->name('chat.send');

    Route::get('/clans', Clans::class)->name('clans.index');
    Route::get('/clan-war', ClanWar::class)->name('clan-war.index');
    Route::get('/clan-leaderboard', ClanLeaderboard::class)->name('clan-leaderboard.index');
    Route::get('/clans/{clan}', ClanShow::class)->name('clans.show');

    Volt::route('/multiplayer', 'multiplayer-lobby')->name('multiplayer.lobby');

    // Leave endpoints for the multiplayer room. The nav is a full page load, so leaving
    // happens outside Livewire: leave-beacon fires on page unload (removes a not-ready
    // non-host member), leave-confirm fires when a ready/host member confirms leaving.
    Route::post('/multiplayer/leave-beacon', [MultiplayerPresenceController::class, 'leaveOnLeave'])
        ->middleware('throttle:60,1')
        ->name('multiplayer.leave-beacon');
    Route::post('/multiplayer/leave-confirm', [MultiplayerPresenceController::class, 'leaveOrUnready'])
        ->middleware('throttle:60,1')
        ->name('multiplayer.leave-confirm');

    Volt::route('/leaderboard', 'leaderboard')->name('leaderboard');
});

Route::post('/locale', LocaleController::class)
    ->middleware('throttle:20,1')
    ->name('locale.update');

// Autentikasi Google-only. Nama route 'login' WAJIB dipertahankan: middleware
// `auth` bawaan Laravel me-redirect tamu ke route bernama itu, jadi menghapusnya
// membuat setiap halaman terproteksi melempar RouteNotFoundException.
Route::get('/login', [GoogleAuthController::class, 'showLogin'])
    ->middleware('guest')
    ->name('login');
Route::post('/logout', [GoogleAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// Route Baru khusus untuk alur pemilihan username setelah Google Auth
Route::get('/auth/google/username', [GoogleAuthController::class, 'showChooseUsernameForm'])->name('auth.google.choose-username');
Route::post('/auth/google/username', [GoogleAuthController::class, 'storeUsername'])->name('auth.google.store-username');

Route::get('/about', About::class)->name('about');

Route::get('/privacy-policy', Terms::class)->name('terms');

// Login cepat sebagai user dummy untuk TESTING. Hanya aktif di environment lokal.
// Buka /dev-login (opsional /dev-login?email=other@uetype.test) untuk langsung masuk.
if (app()->environment('local')) {
    // Acuan design system (token, tipografi, komponen) — alat internal, lokal saja.
    Route::get('/style-guide', fn () => view('style-guide'))->name('style-guide');

    Route::get('/dev-login', function () {
        $email = request('email', 'dummy@uetype.test');
        $user = User::where('email', $email)->first();

        if (! $user) {
            abort(404, "User dummy '{$email}' tidak ditemukan. Jalankan: php artisan db:seed --class=DummyUserSeeder");
        }

        Auth::login($user);

        return redirect('/typing');
    })->name('dev.login');
}
