<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Http\Controllers\Api\V1\Account\DataExportController;
use App\Http\Controllers\Api\V1\Account\DeviceController;
use App\Http\Controllers\Api\V1\Account\PhoneController;
use App\Http\Controllers\Api\V1\Account\ProfileController;
use App\Http\Controllers\Api\V1\Account\WishlistController;
use App\Http\Controllers\Api\V1\Admin\BundleController as AdminBundleController;
use App\Http\Controllers\Api\V1\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Api\V1\Admin\CourseVersionController;
use App\Http\Controllers\Api\V1\Admin\FeaturedCourseController;
use App\Http\Controllers\Api\V1\Admin\KycSubmissionController;
use App\Http\Controllers\Api\V1\Admin\ProfessorApplicationController as AdminApplicationController;
use App\Http\Controllers\Api\V1\Admin\ProfessorController as AdminProfessorController;
use App\Http\Controllers\Api\V1\Admin\RefundController as AdminRefundController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\OtpController;
use App\Http\Controllers\Api\V1\Commerce\CheckoutController;
use App\Http\Controllers\Api\V1\Commerce\EnrollmentController;
use App\Http\Controllers\Api\V1\Commerce\GuestOrderController;
use App\Http\Controllers\Api\V1\Commerce\OrderController;
use App\Http\Controllers\Api\V1\Commerce\ReceiptController;
use App\Http\Controllers\Api\V1\Commerce\RefundController;
use App\Http\Controllers\Api\V1\Learning\LearningController;
use App\Http\Controllers\Api\V1\Learning\QuizAttemptController;
use App\Http\Controllers\Api\V1\Professor\ApplicationController;
use App\Http\Controllers\Api\V1\Professor\BundleController as StudioBundleController;
use App\Http\Controllers\Api\V1\Professor\CouponController as StudioCouponController;
use App\Http\Controllers\Api\V1\Professor\CourseController;
use App\Http\Controllers\Api\V1\Professor\DraftController;
use App\Http\Controllers\Api\V1\Professor\KycController;
use App\Http\Controllers\Api\V1\Professor\MediaController;
use App\Http\Controllers\Api\V1\Professor\PayoutMethodController;
use App\Http\Controllers\Api\V1\Professor\StudioProfileController;
use App\Http\Controllers\Api\V1\Public\BundleController;
use App\Http\Controllers\Api\V1\Public\CategoryController;
use App\Http\Controllers\Api\V1\Public\CourseController as PublicCourseController;
use App\Http\Controllers\Api\V1\Public\ProfessorController;
use App\Http\Controllers\Api\V1\Public\ProvinceController;
use App\Http\Controllers\Api\V1\Webhooks\FakeVideoUploadController;
use App\Http\Controllers\Api\V1\Webhooks\PaymentWebhookController;
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

// Checkout (PRD-05)
Route::get('/checkout/rails', [CheckoutController::class, 'rails'])->name('checkout.rails');
// A parent with no account (PRD-05 FR-14): the order token stands in for sign-in
Route::post('/guest/orders', [GuestOrderController::class, 'store'])->middleware(['throttle:guest-orders', 'idempotent'])->name('guest.orders.store');
Route::get('/guest/orders/{order}', [GuestOrderController::class, 'show'])->middleware('throttle:order-status')->name('guest.orders.show');
Route::post('/guest/orders/{order}/payments', [GuestOrderController::class, 'pay'])->middleware(['throttle:payments', 'idempotent'])->name('guest.orders.pay');
Route::get('/guest/orders/{order}/receipt.pdf', [GuestOrderController::class, 'receipt'])->middleware('throttle:order-status')->name('guest.orders.receipt');
Route::get('/bundles/{slug}', [BundleController::class, 'show'])->middleware('throttle:catalogue')->name('bundles.show');
Route::middleware('signed-in')->group(function () {
    Route::post('/courses/{course}/enroll', [EnrollmentController::class, 'store'])->name('courses.enroll');
    Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->middleware('throttle:coupons')->name('checkout.quote');
    Route::post('/orders', [OrderController::class, 'store'])->middleware(['throttle:coupons', 'idempotent'])->name('orders.store');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('throttle:order-status')->name('orders.show');
    Route::post('/orders/{order}/payments', [OrderController::class, 'pay'])->middleware(['throttle:payments', 'idempotent'])->name('orders.pay');
    Route::get('/me/orders', [OrderController::class, 'index'])->name('me.orders.index');
    Route::get('/orders/{order}/receipt', [ReceiptController::class, 'show'])->name('orders.receipt');
    Route::get('/orders/{order}/receipt.pdf', [ReceiptController::class, 'pdf'])->middleware('throttle:order-status')->name('orders.receipt.pdf');
    Route::post('/orders/{order}/refund-requests', [RefundController::class, 'store'])->name('orders.refund-requests.store');
    Route::get('/me/refunds', [RefundController::class, 'index'])->name('me.refunds.index');

    // Learning (PRD-06)
    Route::get('/me/enrollments', [LearningController::class, 'index'])->name('me.enrollments.index');
    Route::get('/me/courses/{course}', [LearningController::class, 'show'])->name('me.courses.show');
    Route::put('/me/progress', [LearningController::class, 'sync'])->middleware('throttle:progress')->name('me.progress.sync');
    Route::get('/lessons/{lesson}/quiz-attempts', [QuizAttemptController::class, 'index'])->name('lessons.quiz-attempts.index');
    Route::post('/lessons/{lesson}/quiz-attempts', [QuizAttemptController::class, 'store'])->middleware('throttle:quiz')->name('lessons.quiz-attempts.store');
    Route::put('/quiz-attempts/{attempt}', [QuizAttemptController::class, 'submit'])->middleware('throttle:quiz')->name('quiz-attempts.submit');
});

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
    // Coupons and bundles (PRD-05 FR-12, FR-13)
    Route::get('/coupons', [StudioCouponController::class, 'index'])->name('coupons.index');
    Route::post('/coupons', [StudioCouponController::class, 'store'])->name('coupons.store');
    Route::patch('/coupons/{coupon}', [StudioCouponController::class, 'update'])->name('coupons.update');
    Route::get('/bundles', [StudioBundleController::class, 'index'])->name('bundles.index');
    Route::post('/bundles', [StudioBundleController::class, 'store'])->name('bundles.store');
    Route::patch('/bundles/{bundle}', [StudioBundleController::class, 'update'])->name('bundles.update');

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

    // Refunds (PRD-09 FR-12)
    Route::middleware('can:'.Permission::FinanceRefunds->value)->group(function () {
        Route::get('/refunds', [AdminRefundController::class, 'index'])->name('refunds.index');
        Route::get('/refunds/{refund}', [AdminRefundController::class, 'show'])->name('refunds.show');
        Route::post('/refunds/{refund}/approve', [AdminRefundController::class, 'approve'])->name('refunds.approve');
        Route::post('/refunds/{refund}/reject', [AdminRefundController::class, 'reject'])->name('refunds.reject');
        Route::post('/refunds/{refund}/retry', [AdminRefundController::class, 'retry'])->name('refunds.retry');
        Route::post('/payments/{payment}/refund', [AdminRefundController::class, 'refundUnapplied'])->name('payments.refund');
    });

    // Coupons and bundles (PRD-09 FR-13): a combinable permission, like finance
    Route::middleware('can:'.Permission::PromotionsManage->value)->group(function () {
        Route::get('/coupons', [AdminCouponController::class, 'index'])->name('coupons.index');
        Route::post('/coupons', [AdminCouponController::class, 'store'])->name('coupons.store');
        Route::get('/coupons/{coupon}', [AdminCouponController::class, 'show'])->name('coupons.show');
        Route::patch('/coupons/{coupon}', [AdminCouponController::class, 'update'])->name('coupons.update');
        Route::get('/bundles', [AdminBundleController::class, 'index'])->name('bundles.index');
        Route::post('/bundles', [AdminBundleController::class, 'store'])->name('bundles.store');
        Route::patch('/bundles/{bundle}', [AdminBundleController::class, 'update'])->name('bundles.update');
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
Route::post('/webhooks/payments/{gateway}', PaymentWebhookController::class)->middleware('throttle:webhooks')->name('webhooks.payments');

// Fake video driver only (local, staging): stands in for the provider's upload URL.
Route::put('/_fake/video-uploads/{upload}', FakeVideoUploadController::class)->name('fake.video-upload');
