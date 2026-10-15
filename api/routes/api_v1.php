<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Http\Controllers\Api\V1\Account\DataExportController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\PhoneController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\WishlistController;
use App\Http\Controllers\Api\V1\Admin\CourseVersionController;
use App\Http\Controllers\Api\V1\Admin\FeaturedCourseController;
use App\Http\Controllers\Api\V1\Admin\KycSubmissionController;
use App\Http\Controllers\Api\V1\Admin\ProfessorApplicationController as AdminApplicationController;
use App\Http\Controllers\Api\V1\Admin\ProfessorController as AdminProfessorController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Professor\ApplicationController;
use App\Http\Controllers\Api\V1\Professor\CourseController;
use App\Http\Controllers\Api\V1\Professor\DraftController;
use App\Http\Controllers\Api\V1\Professor\KycController;
use App\Http\Controllers\Api\V1\Professor\MediaController;
use App\Http\Controllers\Api\V1\Professor\PayoutMethodController;
use App\Http\Controllers\Api\V1\Professor\StudioProfileController;
use App\Http\Controllers\Api\V1\Public\CategoryController;
use App\Http\Controllers\Api\V1\Public\CourseController as PublicCourseController;
use App\Http\Controllers\Api\V1\Public\ProfessorController;
use App\Http\Controllers\Api\V1\Public\ProvinceController;
use App\Http\Controllers\Api\V1\Webhooks\FakeVideoUploadController;
use App\Http\Controllers\Api\V1\Webhooks\VideoWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Yekkola API v1 — prefix /api/v1 (see docs/architecture.md §4.2).
| Route groups by audience: auth, public, student, professor, admin, webhooks.
| Authorization is by policies + roles, never by client type.
*/

Route::get('/ping', fn () => ['status' => 'ok'])->name('ping');

// Public
Route::get('/provinces', [ProvinceController::class, 'index'])->name('provinces.index');

// Public catalogue (PRD-04)
Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('/courses', [PublicCourseController::class, 'index'])->middleware('throttle:catalogue')->name('courses.index');
Route::get('/courses/{slug}', [PublicCourseController::class, 'show'])->name('courses.show');
Route::get('/featured', [PublicCourseController::class, 'featured'])->name('featured');

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

    Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
    Route::post('/wishlist', [WishlistController::class, 'store'])->name('wishlist.store');
    Route::delete('/wishlist/{course}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');
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

    // Courses (PRD-03)
    Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
    Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');
    Route::patch('/courses/{course}', [CourseController::class, 'update'])->name('courses.update');
    Route::delete('/courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');
    Route::post('/courses/{course}/draft', [CourseController::class, 'startDraft'])->name('courses.draft.start');
    Route::post('/courses/{course}/submit', [CourseController::class, 'submit'])->name('courses.submit');
    Route::post('/courses/{course}/withdraw', [CourseController::class, 'withdraw'])->name('courses.withdraw');
    Route::post('/courses/{course}/unpublish', [CourseController::class, 'unpublish'])->name('courses.unpublish');
    Route::post('/courses/{course}/republish', [CourseController::class, 'republish'])->name('courses.republish');
    Route::post('/courses/{course}/archive', [CourseController::class, 'archive'])->name('courses.archive');

    // Course builder: the draft version
    Route::get('/courses/{course}/draft', [DraftController::class, 'show'])->name('draft.show');
    Route::patch('/courses/{course}/draft', [DraftController::class, 'update'])->name('draft.update');
    Route::post('/courses/{course}/draft/cover', [DraftController::class, 'cover'])->middleware('throttle:uploads')->name('draft.cover');
    Route::put('/courses/{course}/draft/order', [DraftController::class, 'reorder'])->name('draft.reorder');
    Route::post('/courses/{course}/draft/sections', [DraftController::class, 'storeSection'])->name('draft.sections.store');
    Route::patch('/courses/{course}/draft/sections/{section}', [DraftController::class, 'updateSection'])->name('draft.sections.update');
    Route::delete('/courses/{course}/draft/sections/{section}', [DraftController::class, 'destroySection'])->name('draft.sections.destroy');
    Route::post('/courses/{course}/draft/lessons', [DraftController::class, 'storeLesson'])->name('draft.lessons.store');
    Route::patch('/courses/{course}/draft/lessons/{lesson}', [DraftController::class, 'updateLesson'])->name('draft.lessons.update');
    Route::delete('/courses/{course}/draft/lessons/{lesson}', [DraftController::class, 'destroyLesson'])->name('draft.lessons.destroy');
    Route::post('/courses/{course}/draft/lessons/{lesson}/media', [DraftController::class, 'mediaUpload'])->middleware('throttle:uploads')->name('draft.lessons.media');
    Route::post('/courses/{course}/draft/lessons/{lesson}/document', [DraftController::class, 'document'])->middleware('throttle:uploads')->name('draft.lessons.document');
    Route::put('/courses/{course}/draft/lessons/{lesson}/quiz', [DraftController::class, 'quiz'])->name('draft.lessons.quiz');
    Route::get('/media/{media}', [MediaController::class, 'show'])->name('media.show');

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

        // Course review queue (PRD-09 FR-07)
        Route::get('/course-versions', [CourseVersionController::class, 'index'])->name('course-versions.index');
        Route::get('/course-versions/{version}', [CourseVersionController::class, 'show'])->name('course-versions.show');
        Route::post('/course-versions/{version}/approve', [CourseVersionController::class, 'approve'])->name('course-versions.approve');
        Route::post('/course-versions/{version}/reject', [CourseVersionController::class, 'reject'])->name('course-versions.reject');
    });

    Route::middleware('can:'.Permission::KycView->value)->group(function () {
        Route::get('/kyc-submissions', [KycSubmissionController::class, 'index'])->name('kyc-submissions.index');
        Route::get('/kyc-submissions/{submission}', [KycSubmissionController::class, 'show'])->name('kyc-submissions.show');
        Route::get('/kyc-submissions/{submission}/files/{kind}', [KycSubmissionController::class, 'file'])->name('kyc-submissions.file');
    });

    Route::middleware(['can:'.Permission::KycView->value, 'can:'.Permission::KycReview->value])->group(function () {
        Route::post('/kyc-submissions/{submission}/verify', [KycSubmissionController::class, 'verify'])->name('kyc-submissions.verify');
        Route::post('/kyc-submissions/{submission}/reject', [KycSubmissionController::class, 'reject'])->name('kyc-submissions.reject');
    });

    Route::middleware('can:'.Permission::CatalogManage->value)->group(function () {
        Route::get('/featured-courses', [FeaturedCourseController::class, 'index'])->name('featured-courses.index');
        Route::put('/featured-courses', [FeaturedCourseController::class, 'update'])->name('featured-courses.update');
    });

    Route::middleware('can:'.Permission::ProfessorsManage->value)->group(function () {
        Route::get('/professors', [AdminProfessorController::class, 'index'])->name('professors.index');
        Route::get('/professors/{professor}', [AdminProfessorController::class, 'show'])->name('professors.show');
        Route::patch('/professors/{professor}', [AdminProfessorController::class, 'update'])->name('professors.update');
        Route::post('/professors/{professor}/suspend', [AdminProfessorController::class, 'suspend'])->name('professors.suspend');
        Route::post('/professors/{professor}/reinstate', [AdminProfessorController::class, 'reinstate'])->name('professors.reinstate');
    });
});

// Webhooks: no auth; each provider verifies its signature.
Route::post('/webhooks/video', VideoWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.video');

// Fake video driver only (local, staging): stands in for the provider's upload URL.
Route::put('/_fake/video-uploads/{upload}', FakeVideoUploadController::class)->name('fake.video-upload');
