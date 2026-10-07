<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Account\DataExportController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\PhoneController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
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
