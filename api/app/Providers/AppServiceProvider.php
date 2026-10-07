<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Atomic locks (idempotency, payment handlers) come from the default cache store.
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
        // Catch lazy loading, silently discarded attributes, and missing attributes outside production.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
