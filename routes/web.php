<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use App\Livewire\TypingEngine;
use App\Livewire\TypingResult;
use App\Models\User;

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
});

// Login cepat sebagai user dummy untuk TESTING. Hanya aktif di environment lokal.
// Buka /dev-login (opsional /dev-login?email=other@uetype.test) untuk langsung masuk.
if (app()->environment('local')) {
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
