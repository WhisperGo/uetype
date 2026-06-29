<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Livewire\MultiplayerLobby;
use App\Livewire\TypingEngine;
use App\Livewire\TypingResult;
use App\Models\User;
use Livewire\Volt\Volt;
use App\Http\Controllers\GoogleAuthController;

Route::get('/', TypingEngine::class)->name('home');

Route::get('/dashboard', function () {
    return redirect('/');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/typing', TypingEngine::class)->name('typing');
    Route::get('/result', TypingResult::class)->name('typing.result');

    Route::get('/multiplayer', MultiplayerLobby::class)->name('multiplayer.lobby');

    Volt::route('/leaderboard', 'leaderboard')->name('leaderboard');
});

Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('auth.google');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback']);

// Route Baru khusus untuk alur pemilihan username setelah Google Auth
Route::get('/auth/google/username', [GoogleAuthController::class, 'showChooseUsernameForm'])->name('auth.google.choose-username');
Route::post('/auth/google/username', [GoogleAuthController::class, 'storeUsername'])->name('auth.google.store-username');

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
