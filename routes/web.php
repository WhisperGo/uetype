<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Livewire\TypingEngine;
use App\Livewire\TypingResult;

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

require __DIR__.'/auth.php';
