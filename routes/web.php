<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('welcome'))->name('home');
Route::get('/terms', fn () => Inertia::render('legal', ['kind' => 'terms']))->name('terms');
Route::get('/privacy', fn () => Inertia::render('legal', ['kind' => 'privacy']))->name('privacy');
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::middleware('verified')->group(function () {
        Route::get('/onboarding', [OnboardingController::class, 'edit'])->name('onboarding.edit');
        Route::patch('/onboarding', [OnboardingController::class, 'update'])->name('onboarding.update');
    });
});
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
