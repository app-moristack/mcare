<?php

use App\Http\Controllers\CarePortalController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\ProviderVisitController;
use App\Http\Middleware\RequireWorkforceMfa;
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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/security/mfa', [MfaController::class, 'show'])->name('security.mfa');
    Route::post('/security/mfa/setup', [MfaController::class, 'begin'])->middleware('throttle:5,1');
    Route::post('/security/mfa/verify', [MfaController::class, 'verify'])->middleware('throttle:5,1');
    Route::post('/workspace-context', [CarePortalController::class, 'context']);
    Route::get('/my-care', [CarePortalController::class, 'index']);
    Route::post('/my-care/request', [CarePortalController::class, 'requestCare'])->middleware('throttle:5,1');
    Route::get('/application', [CarePortalController::class, 'application']);
    Route::post('/application', [CarePortalController::class, 'submitApplication']);
    Route::middleware(RequireWorkforceMfa::class)->group(function () {
        Route::get('/my-visits', [ProviderVisitController::class, 'index']);
        Route::post('/my-visits/{id}/attendance', [ProviderVisitController::class, 'attendance']);
        Route::post('/workspace/invoices/{id}/payments', [OperationsController::class, 'payment']);
        Route::patch('/workspace/team/applications/{id}', [OperationsController::class, 'reviewApplication']);
        Route::post('/workspace/team/memberships', [OperationsController::class, 'addMembership']);
        Route::patch('/workspace/team/memberships/{id}', [OperationsController::class, 'membership']);
        Route::get('/workspace/{module}', [OperationsController::class, 'index'])->name('operations.index');
        Route::post('/workspace/{module}', [OperationsController::class, 'store']);
        Route::patch('/workspace/{module}/{id}', [OperationsController::class, 'update']);
    });
});
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
