<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Platform\Listeners\AuditSettingsChange;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Spatie\LaravelSettings\Events\SavingSettings;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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

        // Every platform settings change is audited with before/after values.
        Event::listen(SavingSettings::class, AuditSettingsChange::class);

        // Catch lazy loading, silently discarded attributes, and missing attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
