<?php

use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\CarePortalController;
use App\Http\Controllers\ClinicalObservationController;
use App\Http\Controllers\ClinicalRecordController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OperationsController;
use App\Http\Controllers\PatientRecordController;
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
    Route::get('/my-care/patients/{id}/clinical', [ClinicalRecordController::class, 'own'])->whereNumber('id');
    Route::get('/my-care/patients/{id}/assessments', [AssessmentController::class, 'own']);
    Route::post('/my-care/patients/{id}/assessments', [AssessmentController::class, 'ownStore']);
    Route::patch('/my-care/patients/{id}/assessments/{assessment}', [AssessmentController::class, 'ownUpdate']);
    Route::get('/my-care/patients/{id}', [PatientRecordController::class, 'own']);
    Route::post('/my-care/patients/{id}/grants', [PatientRecordController::class, 'ownGrant']);
    Route::post('/my-care/patients/{id}/blocks', [PatientRecordController::class, 'ownBlock']);
    Route::post('/my-care/patients/{id}/access/{rule}/revoke', [PatientRecordController::class, 'ownRevoke']);
    Route::get('/my-care', [CarePortalController::class, 'index']);
    Route::post('/my-care/request', [CarePortalController::class, 'requestCare'])->middleware('throttle:5,1');
    Route::get('/application', [CarePortalController::class, 'application']);
    Route::post('/application', [CarePortalController::class, 'submitApplication']);
    Route::middleware(RequireWorkforceMfa::class)->group(function () {
        Route::get('/patient-records/{id}/clinical', [ClinicalRecordController::class, 'index'])->whereNumber('id');
        Route::post('/patient-records/{id}/clinical/{kind}', [ClinicalRecordController::class, 'store'])->whereNumber('id');
        Route::patch('/patient-records/{id}/clinical/{kind}/{record}', [ClinicalRecordController::class, 'update'])->whereNumber(['id', 'record']);
        Route::post('/patient-records/{id}/clinical/plans/{record}/transition', [ClinicalRecordController::class, 'transition'])->whereNumber(['id', 'record']);
        Route::post('/patient-records/{id}/clinical/{kind}/{record}/publish', [ClinicalRecordController::class, 'publish'])->whereNumber(['id', 'record']);
        Route::get('/patient-records/{id}/assessments', [AssessmentController::class, 'index']);
        Route::post('/patient-records/{id}/assessments', [AssessmentController::class, 'store']);
        Route::patch('/patient-records/{id}/assessments/{assessment}', [AssessmentController::class, 'update']);
        Route::post('/patient-records/{id}/assessments/{assessment}/cosign', [AssessmentController::class, 'cosign']);
        Route::get('/patient-records/{id}/observations', [ClinicalObservationController::class, 'index'])->whereNumber('id');
        Route::post('/patient-records/{id}/observations', [ClinicalObservationController::class, 'store'])->whereNumber('id');
        Route::get('/patient-records/{id}', [PatientRecordController::class, 'show']);
        Route::patch('/patient-records/{id}', [PatientRecordController::class, 'update']);
        Route::get('/patient-records/{id}/summary', [PatientRecordController::class, 'summary']);
        Route::patch('/patient-records/{id}/summary', [PatientRecordController::class, 'saveSummary']);
        Route::post('/patient-records/{id}/access', [PatientRecordController::class, 'addRule']);
        Route::post('/patient-records/{id}/access/{rule}/revoke', [PatientRecordController::class, 'revoke']);
        Route::get('/search', GlobalSearchController::class)->middleware('throttle:60,1');
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
