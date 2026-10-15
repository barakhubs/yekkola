<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Http\Controllers\Api\V1\Account\DataExportController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\PhoneController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Admin\KycSubmissionController;
use App\Http\Controllers\Api\V1\Admin\ProfessorApplicationController as AdminApplicationController;
use App\Http\Controllers\Api\V1\Admin\ProfessorController as AdminProfessorController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Professor\ApplicationController;
use App\Http\Controllers\Api\V1\Professor\KycController;
use App\Http\Controllers\Api\V1\Professor\PayoutMethodController;
use App\Http\Controllers\Api\V1\Professor\StudioProfileController;
use App\Http\Controllers\Api\V1\Public\ProfessorController;
use App\Http\Controllers\Api\V1\Public\ProvinceController;
use Illuminate\Support\Facades\Route;

/*
| Yekkola API v1 — prefix /api/v1 (see docs/architecture.md §4.2).
| Route groups by audience: auth, public, student, professor, admin, webhooks.
| Authorization is by policies + roles, never by client type.
*/

Route::get('/ping', fn () => ['status' => 'ok'])->name('ping');

// Public
Route::get('/provinces', [ProvinceController::class, 'index'])->name('provinces.index');

// Auth (PRD-01)
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/otp/request', [OtpController::class, 'request'])->name('otp.request');
    Route::post('/otp/verify', [OtpController::class, 'verify'])->middleware('throttle:otp-verify')->name('otp.verify');
    Route::post('/logout', LogoutController::class)->middleware('auth:sanctum')->name('logout');
});

// Account (any signed-in user)
Route::middleware('signed-in')->prefix('me')->name('me.')->group(function () {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::patch('/', [ProfileController::class, 'update'])->middleware('throttle:profile-update')->name('update');
    Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
    Route::delete('/deletion', [ProfileController::class, 'cancelDeletion'])->name('deletion.cancel');

    Route::get('/devices', [DeviceController::class, 'index'])->name('devices.index');
    Route::delete('/devices/{device}', [DeviceController::class, 'destroy'])->name('devices.destroy');

    Route::post('/phone/otp', [PhoneController::class, 'requestOtp'])->middleware('throttle:otp-verify')->name('phone.otp');
    Route::put('/phone', [PhoneController::class, 'update'])->middleware('throttle:otp-verify')->name('phone.update');

    Route::post('/export', [DataExportController::class, 'store'])->name('export.store');
    Route::get('/export', [DataExportController::class, 'show'])->name('export.show');
    Route::get('/export/download', [DataExportController::class, 'download'])->name('export.download');
});

// Public professor pages (PRD-02 FR-12)
Route::get('/professors/{slug}', [ProfessorController::class, 'show'])->name('professors.show');

// Becoming a professor: any signed-in user (PRD-02 FR-01-03)
Route::middleware('signed-in')->prefix('professor/application')->name('professor.application.')->group(function () {
    Route::get('/', [ApplicationController::class, 'show'])->name('show');
    Route::put('/', [ApplicationController::class, 'save'])->name('save');
    Route::post('/submit', [ApplicationController::class, 'submit'])->name('submit');
    Route::post('/credentials', [ApplicationController::class, 'addCredential'])->middleware('throttle:uploads')->name('credentials.store');
    Route::delete('/credentials/{file}', [ApplicationController::class, 'removeCredential'])->name('credentials.destroy');
});

// Professor studio (PRD-02 FR-07-10)
Route::middleware(['signed-in', 'professor'])->prefix('professor')->name('professor.')->group(function () {
    Route::get('/profile', [StudioProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [StudioProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/avatar', [StudioProfileController::class, 'avatar'])->middleware('throttle:uploads')->name('profile.avatar');
    Route::post('/terms/accept', [StudioProfileController::class, 'acceptTerms'])->name('terms.accept');

    Route::get('/kyc', [KycController::class, 'show'])->name('kyc.show');
    Route::post('/kyc', [KycController::class, 'store'])->middleware('throttle:uploads')->name('kyc.store');

    Route::post('/payout-method/otp', [PayoutMethodController::class, 'requestOtp'])->middleware('throttle:otp-verify')->name('payout-method.otp');
    Route::put('/payout-method', [PayoutMethodController::class, 'update'])->middleware('throttle:otp-verify')->name('payout-method.update');
});

// Back office (PRD-09): staff only; each group checks a permission.
Route::middleware(['signed-in', 'staff'])->prefix('admin')->name('admin.')->group(function () {
    Route::middleware('can:'.Permission::ModerationReview->value)->group(function () {
        Route::get('/professor-applications', [AdminApplicationController::class, 'index'])->name('professor-applications.index');
        Route::get('/professor-applications/{application}', [AdminApplicationController::class, 'show'])->name('professor-applications.show');
        Route::post('/professor-applications/{application}/start-review', [AdminApplicationController::class, 'startReview'])->name('professor-applications.start-review');
        Route::post('/professor-applications/{application}/approve', [AdminApplicationController::class, 'approve'])->name('professor-applications.approve');
        Route::post('/professor-applications/{application}/reject', [AdminApplicationController::class, 'reject'])->name('professor-applications.reject');
        Route::post('/professor-applications/{application}/request-info', [AdminApplicationController::class, 'requestInfo'])->name('professor-applications.request-info');
        Route::get('/professor-applications/{application}/credentials/{file}', [AdminApplicationController::class, 'credentialFile'])->name('professor-applications.credential');
    });

    Route::middleware('can:'.Permission::KycView->value)->group(function () {
        Route::get('/kyc-submissions', [KycSubmissionController::class, 'index'])->name('kyc-submissions.index');
        Route::get('/kyc-submissions/{submission}', [KycSubmissionController::class, 'show'])->name('kyc-submissions.show');
        Route::get('/kyc-submissions/{submission}/files/{kind}', [KycSubmissionController::class, 'file'])->name('kyc-submissions.file');
        Route::post('/kyc-submissions/{submission}/verify', [KycSubmissionController::class, 'verify'])->name('kyc-submissions.verify');
        Route::post('/kyc-submissions/{submission}/reject', [KycSubmissionController::class, 'reject'])->name('kyc-submissions.reject');
    });

    Route::middleware('can:'.Permission::ProfessorsManage->value)->group(function () {
        Route::get('/professors', [AdminProfessorController::class, 'index'])->name('professors.index');
        Route::get('/professors/{professor}', [AdminProfessorController::class, 'show'])->name('professors.show');
        Route::patch('/professors/{professor}', [AdminProfessorController::class, 'update'])->name('professors.update');
        Route::post('/professors/{professor}/suspend', [AdminProfessorController::class, 'suspend'])->name('professors.suspend');
        Route::post('/professors/{professor}/reinstate', [AdminProfessorController::class, 'reinstate'])->name('professors.reinstate');
    });
});
