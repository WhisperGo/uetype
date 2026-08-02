<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\ClanWarProgressController;
use App\Http\Controllers\FriendController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MultiplayerPresenceController;
use App\Http\Controllers\PresenceController;
use App\Http\Controllers\ProfileController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Livewire\About;
use App\Livewire\Chat;
use App\Livewire\ClanLeaderboard;
use App\Livewire\Clans;
use App\Livewire\ClanShow;
use App\Livewire\ClanWar;
use App\Livewire\Friends;
use App\Livewire\ReviewQueue;
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

// The solo typing engine & its result page are open to guests (same as '/'): TypingEngine
// guards each auth-only access with Auth::check(), and TypingResult reads from the session.
// Guests can type, they just don't earn XP/records.
Route::get('/typing', TypingEngine::class)->name('typing');
Route::get('/result', TypingResult::class)->name('typing.result');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'me'])->name('profile.me');

    // Another user's public profile (opened from the friends list / search results).
    // Referenced by username, not ID, so user IDs (and the total registered-user count)
    // can't be enumerated by changing a number in the URL.
    Route::get('/users/{user:username}', [ProfileController::class, 'show'])->name('profile.show');

    Route::get('/settings', Settings::class)->name('settings');

    // Presence heartbeat: the client pings periodically to keep last_seen_at fresh
    // (online/offline detection in the friends list).
    // Throttle 30/min: a normal client is only 2/min (30-second interval), but every time a
    // tab becomes visible again it triggers an extra ping -- this limit leaves room for that
    // reasonable burst without letting the endpoint be flooded.
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
    // Send a message via a lightweight endpoint (parallel, outside the Livewire queue) so
    // rapid messages don't wait on each other.
    // A loose throttle (60/min) precisely BECAUSE this endpoint is designed for bursts: a
    // tight limit would cut off fast typers, not attackers.
    Route::post('/chat/send', [ChatController::class, 'send'])
        ->middleware('throttle:60,1')
        ->name('chat.send');

    Route::get('/clans', Clans::class)->name('clans.index');
    Route::get('/clan-war', ClanWar::class)->name('clan-war.index');

    // Where a running Clan War attempt reports its position and its session ledger. Off the
    // Livewire queue on purpose: a Livewire XHR is cancelled by page unload, so the ping fired
    // as the player pressed refresh -- the only one that decides where they resume -- never
    // arrived. Reached by a keepalive fetch, same as the multiplayer leave-beacon.
    //
    // Throttle 240/min: the client reports once per finished word, and a 120 WPM typist
    // finishes two words a second. Generous enough never to cut off a fast player, and the
    // endpoint writes nothing a flood could grow (every value is bounded and monotonic).
    Route::post('/clan-war/attempt-progress', ClanWarProgressController::class)
        ->middleware('throttle:240,1')
        ->name('clan-war.attempt-progress');
    Route::get('/clan-leaderboard', ClanLeaderboard::class)->name('clan-leaderboard.index');
    Route::get('/clans/{clan}', ClanShow::class)->name('clans.show');

    // Admin-only anti-cheat review queue (§7.6). EnsureUserIsAdmin -> 404 for non-admins,
    // same as the monitoring dashboard, so the page's existence doesn't leak.
    Route::get('/review-queue', ReviewQueue::class)
        ->middleware(EnsureUserIsAdmin::class)
        ->name('review-queue');

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

// Google-only authentication. The route name 'login' MUST be kept: Laravel's built-in
// `auth` middleware redirects guests to that named route, so removing it would make every
// protected page throw a RouteNotFoundException.
Route::get('/login', [GoogleAuthController::class, 'showLogin'])
    ->middleware('guest')
    ->name('login');
Route::post('/logout', [GoogleAuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// Routes for the username-selection flow after Google Auth
Route::get('/auth/google/username', [GoogleAuthController::class, 'showChooseUsernameForm'])->name('auth.google.choose-username');
Route::post('/auth/google/username', [GoogleAuthController::class, 'storeUsername'])->name('auth.google.store-username');

Route::get('/about', About::class)->name('about');

Route::get('/privacy-policy', Terms::class)->name('terms');

// Quick login as a dummy user for TESTING. Only active in the local environment.
// Open /dev-login (optionally /dev-login?email=other@uetype.test) to log straight in.
if (app()->environment('local')) {
    // Design-system reference (tokens, typography, components) — an internal tool, local only.
    Route::get('/style-guide', fn () => view('style-guide'))->name('style-guide');

    Route::get('/dev-login', function () {
        $email = request('email', 'dummy@uetype.test');
        $user = User::where('email', $email)->first();

        if (! $user) {
            abort(404, "Dummy user '{$email}' not found. Run: php artisan db:seed --class=DummyUserSeeder");
        }

        // Same rule as GoogleAuthController::loginAndRegenerate() -- a dev shortcut is still
        // a login path, and one that quietly skipped session rotation is exactly how such a
        // rule erodes. remember: true so this behaves like the real thing too.
        Auth::login($user, remember: true);
        request()->session()->regenerate();

        return redirect('/typing');
    })->name('dev.login');
}
