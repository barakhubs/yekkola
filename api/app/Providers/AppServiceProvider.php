<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Support\OtpCodeHasher;
use App\Domain\Platform\Listeners\AuditSettingsChange;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
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

        // Every platform settings change is audited with before/after values.
        Event::listen(SavingSettings::class, AuditSettingsChange::class);

        // Catch lazy loading, silently discarded attributes, and missing attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
