<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Authoring\Events\CourseVersionDecided;
use App\Domain\Authoring\Models\Course;
use App\Domain\Catalog\Jobs\MakeListedSearchable;
use App\Domain\Catalog\Listeners\RevalidateWebOnChange;
use App\Domain\Catalog\Listeners\SyncCourseIndex;
use App\Domain\Catalog\Models\Category;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Events\RefundDecided;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Finance\Models\Payout;
use App\Domain\Finance\Models\PayoutBatch;
use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OtpCodeHasher;
use App\Domain\Learning\Events\CertificateIssued;
use App\Domain\Learning\Events\ProgressRecorded;
use App\Domain\Learning\Listeners\EraseLearningData;
use App\Domain\Learning\Listeners\IssueCertificateOnProgress;
use App\Domain\Learning\Listeners\IssueCertificatesOnNameSet;
use App\Domain\Learning\Listeners\RecomputeProgressOnNewVersion;
use App\Domain\Learning\Listeners\RefreshRatingsOnUserStatus;
use App\Domain\Learning\Models\LessonThread;
use App\Domain\Learning\Models\Review;
use App\Domain\Learning\Models\ThreadReply;
use App\Domain\Notifications\Listeners\EraseNotificationData;
use App\Domain\Notifications\Listeners\SendCatalogueNotifications;
use App\Domain\Notifications\StaffAlerts;
use App\Domain\Platform\Listeners\AuditSettingsChange;
use App\Domain\Professors\Events\ApplicationDecided;
use App\Domain\Professors\Listeners\EraseProfessorData;
use App\Domain\Professors\Models\KycSubmission;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Protection\Listeners\EraseProtectionData;
use App\Domain\Protection\Stamping\DocumentStamper;
use App\Domain\Protection\Stamping\FpdiDocumentStamper;
use App\Http\Support\ClientIp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Laravel\Scout\Scout;
use LogicException;
use Spatie\LaravelSettings\Events\SavingSettings;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentStamper::class, FpdiDocumentStamper::class);
        $this->app->singleton(OtpCodeHasher::class, fn (Application $app): OtpCodeHasher => new OtpCodeHasher((string) $app['config']->get('app.key')));

        // Atomic locks (payment handlers, payout runs) come from the default cache store.
        $this->app->bind(LockProvider::class, function (Application $app): LockProvider {
            $store = $app->make('cache')->store()->getStore();

            if (! $store instanceof LockProvider) {
                throw new LogicException('The default cache store must support atomic locks (use redis, database, or array).');
            }

            return $store;
        });
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Baseline for every /api/v1 route (bootstrap/app.php): per account when signed in (cookie or bearer token,
        // also on public routes), per visitor otherwise. Kept above every route-specific limit below, and roomy
        // enough for the studio's upload polling; provider webhooks and our web server's own page renders (no
        // visitor behind them) have their own limits or none.
        RateLimiter::for('api', function (Request $request): Limit {
            if ($request->is('api/v1/webhooks/*')) {
                return Limit::none();
            }
            $user = $request->user() ?? Auth::guard('sanctum')->user();
            if ($user !== null) {
                return Limit::perMinute(600)->by('user:'.$user->getAuthIdentifier());
            }
            $ip = ClientIp::of($request);

            return $ip === null ? Limit::none() : Limit::perMinute(1_500)->by($ip);
        });
        // Account deletion and its cancellation.
        RateLimiter::for('account-deletion', fn (Request $request) => Limit::perHour(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Staff: impersonation starts, staff management, and opening identity/credential files (limits bulk copying).
        RateLimiter::for('impersonation', fn (Request $request) => Limit::perHour(20)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('staff-admin', fn (Request $request) => Limit::perHour(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('sensitive-files', fn (Request $request) => [
            Limit::perMinute(30)->by('m:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perDay(500)->by('d:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);
        // CSV statements and data-export downloads: built on each call.
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // OTP verification (sign-in, phone change): coarse per-IP cap on top of the per-challenge attempt limit.
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(10, 30)->by($request->ip()));
        // Staff password sign-in and changes: per email+IP and per IP, against guessing.
        RateLimiter::for('staff-sign-in', fn (Request $request) => [
            Limit::perMinute(5)->by('email:'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perHour(30)->by('ip:'.$request->ip()),
        ]);
        // Provider webhooks: generous, but bounded.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        // File uploads (credentials, avatar, KYC).
        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Video/audio part URLs and upload confirmations (one call per 100 parts of a file).
        RateLimiter::for('upload-parts', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Payment starts: 10 per hour per user (architecture §4.1); waiting-screen polls.
        RateLimiter::for('payments', fn (Request $request) => Limit::perHour(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('order-status', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Quotes and orders with a coupon code: limits code guessing.
        RateLimiter::for('coupons', fn (Request $request) => Limit::perMinute(20)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Guest checkout (parent without an account): per IP, on top of the bot challenge.
        RateLimiter::for('guest-orders', fn (Request $request) => Limit::perHour((int) config('yekkola.checkout.guest_orders_per_ip_per_hour'))->by((string) $request->ip()));
        // Progress sync: web saves every 15 s, mobile in batches.
        RateLimiter::for('progress', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Quiz starts and submissions: grading a whole quiz per call.
        RateLimiter::for('quiz', fn (Request $request) => Limit::perMinute(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Catalogue and search: a coarse abuse cap only, per visitor (ClientIp: our web server forwards the
        // visitor's IP). Carrier NAT still puts many visitors behind one IP.
        RateLimiter::for('catalogue', fn (Request $request) => self::perVisitor($request, 1_200));
        // Profile updates (limits probing which emails are taken).
        // Review writes (PRD-06 FR-12) and public certificate lookups (FR-11).
        RateLimiter::for('reviews', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Lesson Q&A (PRD-06 FR-13): questions and replies per user.
        RateLimiter::for('qa', fn (Request $request) => [
            Limit::perMinute(6)->by('m:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perDay(100)->by('d:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);
        // Reports (PRD-06 FR-14): enough to flag several items, not to flood the moderators' queue.
        RateLimiter::for('reports', fn (Request $request) => [
            Limit::perMinute(5)->by('m:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
            Limit::perDay(30)->by('d:'.($request->user()?->getAuthIdentifier() ?? $request->ip())),
        ]);
        // Playback tokens and documents (PRD-07): per student; previews per IP.
        RateLimiter::for('playback', fn (Request $request) => Limit::perMinute(60)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // HLS playlists + key (3–5 requests per playback start, more on recovery). Signed-in users (cookie or
        // token) by account; guests by IP, generously: many students share one carrier IP.
        RateLimiter::for('hls', function (Request $request) {
            $user = $request->user('sanctum');

            return $user !== null
                ? Limit::perMinute(120)->by('user:'.$user->getAuthIdentifier())
                : Limit::perMinute(600)->by('ip:'.$request->ip());
        });
        RateLimiter::for('preview-playback', fn (Request $request) => self::perVisitor($request, 30));
        RateLimiter::for('certificate-pdf', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('certificates', fn (Request $request) => self::perVisitor($request, 30));
        RateLimiter::for('profile-update', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Every platform settings change is audited with before/after values.
        Event::listen(SavingSettings::class, AuditSettingsChange::class);

        // Account deletion: each domain erases its own personal data (PRD-01 FR-11).
        Event::listen(UserAnonymizing::class, EraseProfessorData::class);
        Event::listen(UserAnonymizing::class, EraseLearningData::class);
        Event::listen(UserAnonymizing::class, EraseProtectionData::class);
        Event::listen(UserAnonymizing::class, EraseNotificationData::class);

        // Learning: a new live version changes what counts toward progress (PRD-06 BR-02).
        Event::listen(CourseVersionDecided::class, RecomputeProgressOnNewVersion::class);
        // Certificates follow progress (PRD-06 FR-10); ratings drop banned authors' reviews (BR-03).
        Event::listen(ProgressRecorded::class, IssueCertificateOnProgress::class);
        Event::listen('eloquent.saved: '.User::class, IssueCertificatesOnNameSet::class);
        Event::listen('eloquent.saved: '.User::class, RefreshRatingsOnUserStatus::class);

        // Notifications (PRD-10 §3): one handler per catalogue event.
        $n = SendCatalogueNotifications::class;
        Event::listen(OrderPaid::class, [$n, 'orderPaid']);
        Event::listen(RefundDecided::class, [$n, 'refundDecided']);
        Event::listen(CourseVersionDecided::class, [$n, 'versionDecided']);
        Event::listen(CertificateIssued::class, [$n, 'certificateIssued']);
        Event::listen(ApplicationDecided::class, [$n, 'applicationDecided']);
        Event::listen('eloquent.saved: '.Payment::class, [$n, 'paymentSaved']);
        Event::listen('eloquent.saved: '.Order::class, [$n, 'orderSaved']);
        Event::listen('eloquent.saved: '.Payout::class, [$n, 'payoutSaved']);
        Event::listen('eloquent.saved: '.KycSubmission::class, [$n, 'kycSaved']);
        Event::listen('eloquent.saved: '.User::class, [$n, 'userSaved']);
        Event::listen('eloquent.saved: '.Course::class, [$n, 'courseSaved']);
        Event::listen('eloquent.created: '.LessonThread::class, [$n, 'threadCreated']);
        Event::listen('eloquent.created: '.ThreadReply::class, [$n, 'replyCreated']);
        Event::listen('eloquent.created: '.Review::class, [$n, 'reviewCreated']);
        Event::listen('eloquent.saved: '.PayoutBatch::class, [StaffAlerts::class, 'payoutBatchSaved']);

        // Search index: rows the course index depends on (PRD-04 FR-11).
        Scout::makeSearchableUsing(MakeListedSearchable::class);
        Event::listen('eloquent.saved: '.ProfessorProfile::class, [SyncCourseIndex::class, 'professorSaved']);
        Event::listen('eloquent.saved: '.User::class, [SyncCourseIndex::class, 'userSaved']);
        Event::listen('eloquent.deleted: '.User::class, [SyncCourseIndex::class, 'userDeleted']);
        Event::listen('eloquent.saved: '.Category::class, [SyncCourseIndex::class, 'categorySaved']);

        // Web app caches: refresh public pages when what they show changes (PRD-04 FR-11, FR-12).
        Event::listen('eloquent.saved: '.Course::class, [RevalidateWebOnChange::class, 'courseSaved']);
        Event::listen('eloquent.deleted: '.Course::class, [RevalidateWebOnChange::class, 'courseDeleted']);
        Event::listen(['eloquent.saved: '.Category::class, 'eloquent.deleted: '.Category::class], [RevalidateWebOnChange::class, 'categoryChanged']);
        Event::listen('eloquent.saved: '.ProfessorProfile::class, [RevalidateWebOnChange::class, 'professorSaved']);

        // Catch lazy loading, silently discarded attributes, and missing attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }

    /**
     * Limits public endpoints per visitor, even when our web server renders the page (ClientIp). Our server's own
     * cache refreshes (no visitor behind them) share one generous bucket.
     */
    private static function perVisitor(Request $request, int $perMinute): Limit
    {
        $ip = ClientIp::of($request);

        return $ip === null
            ? Limit::perMinute($perMinute * 50)->by('web-server')
            : Limit::perMinute($perMinute)->by($ip);
    }
}
