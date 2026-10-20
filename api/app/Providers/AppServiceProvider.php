<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Authoring\Events\CourseVersionDecided;
use App\Domain\Catalog\Jobs\MakeListedSearchable;
use App\Domain\Catalog\Listeners\SyncCourseIndex;
use App\Domain\Catalog\Models\Category;
use App\Domain\Identity\Events\UserAnonymizing;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Support\OtpCodeHasher;
use App\Domain\Learning\Listeners\EraseLearningData;
use App\Domain\Learning\Listeners\RecomputeProgressOnNewVersion;
use App\Domain\Platform\Listeners\AuditSettingsChange;
use App\Domain\Professors\Listeners\EraseProfessorData;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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

        // OTP verification (sign-in, phone change): coarse per-IP cap on top of the per-challenge attempt limit.
        RateLimiter::for('otp-verify', fn (Request $request) => Limit::perMinutes(10, 30)->by($request->ip()));
        // Provider webhooks: generous, but bounded.
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
        // File uploads (credentials, avatar, KYC).
        RateLimiter::for('uploads', fn (Request $request) => Limit::perHour(30)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
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
        // Catalogue and search: a coarse abuse cap only. Server-side rendering (apps/web) and carrier NAT put
        // many visitors behind one IP. TODO(2.x): key on the forwarded client IP once trusted proxies are set.
        RateLimiter::for('catalogue', fn (Request $request) => Limit::perMinute(1_200)->by((string) $request->ip()));
        // Profile updates (limits probing which emails are taken).
        RateLimiter::for('profile-update', fn (Request $request) => Limit::perMinute(10)->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Every platform settings change is audited with before/after values.
        Event::listen(SavingSettings::class, AuditSettingsChange::class);

        // Account deletion: each domain erases its own personal data (PRD-01 FR-11).
        Event::listen(UserAnonymizing::class, EraseProfessorData::class);
        Event::listen(UserAnonymizing::class, EraseLearningData::class);

        // Learning: a new live version changes what counts toward progress (PRD-06 BR-02).
        Event::listen(CourseVersionDecided::class, RecomputeProgressOnNewVersion::class);

        // Search index: rows the course index depends on (PRD-04 FR-11).
        Scout::makeSearchableUsing(MakeListedSearchable::class);
        Event::listen('eloquent.saved: '.ProfessorProfile::class, [SyncCourseIndex::class, 'professorSaved']);
        Event::listen('eloquent.saved: '.User::class, [SyncCourseIndex::class, 'userSaved']);
        Event::listen('eloquent.deleted: '.User::class, [SyncCourseIndex::class, 'userDeleted']);
        Event::listen('eloquent.saved: '.Category::class, [SyncCourseIndex::class, 'categorySaved']);

        // Catch lazy loading, silently discarded attributes, and missing attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
