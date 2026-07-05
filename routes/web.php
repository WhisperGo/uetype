<?php

use App\Http\Controllers\AchievementController;
use App\Http\Controllers\GoogleAuthController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProfileController;
use App\Livewire\About;
use App\Livewire\ClanLeaderboard;
use App\Livewire\Clans;
use App\Livewire\ClanShow;
use App\Livewire\ClanWar;
use App\Livewire\Friends;
use App\Livewire\Settings;
use App\Livewire\Terms;
use App\Livewire\TypingEngine;
use App\Livewire\TypingResult;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', TypingEngine::class)->name('home');

Route::get('/dashboard', function () {
    return redirect('/');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');

    // Profil publik user lain (dibuka dari daftar teman / hasil pencarian).
    // Dirujuk lewat username, bukan ID, supaya ID user (dan jumlah total
    // user terdaftar) tidak bisa dienumerasi dengan mengubah angka di URL.
    Route::get('/users/{user:username}', [ProfileController::class, 'show'])->name('profile.show');

    Route::get('/settings', Settings::class)->name('settings');

    Route::get('/achievements', [AchievementController::class, 'index'])->name('achievements.index');

    Route::get('/friends', Friends::class)->name('friends.index');

    Route::get('/clans', Clans::class)->name('clans.index');
    Route::get('/clan-war', ClanWar::class)->name('clan-war.index');
    Route::get('/clan-leaderboard', ClanLeaderboard::class)->name('clan-leaderboard.index');
    Route::get('/clans/{clan}', ClanShow::class)->name('clans.show');

    Route::get('/typing', TypingEngine::class)->name('typing');
    Route::get('/result', TypingResult::class)->name('typing.result');

    Volt::route('/multiplayer', 'multiplayer-lobby')->name('multiplayer.lobby');

    Volt::route('/leaderboard', 'leaderboard')->name('leaderboard');
});

Route::post('/locale', LocaleController::class)->name('locale.update');

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

require __DIR__.'/auth.php';
