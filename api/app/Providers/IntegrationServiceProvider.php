<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\BotChallenge\BotChallenge;
use App\Integrations\BotChallenge\FakeBotChallenge;
use App\Integrations\BotChallenge\TurnstileBotChallenge;
use App\Integrations\Payments\FakeGateway;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Sms\LogSmsSender;
use App\Integrations\Sms\SmsSender;
use App\Integrations\Video\FakeVideoProvider;
use App\Integrations\Video\VideoProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use RuntimeException;

/**
 * Binds the external-service interfaces to the configured drivers (config/yekkola.php) and refuses to
 * boot production with fake or log drivers.
 */
final class IntegrationServiceProvider extends ServiceProvider
{
    /** Drivers that must never run in production. */
    public const UNSAFE_DRIVERS = ['fake', 'log'];

    public function register(): void
    {
        // Singletons so a request (or test) sees one consistent fake.
        $this->app->singleton(VideoProvider::class, fn (Application $app): VideoProvider => match ($this->driver('video')) {
            'fake' => new FakeVideoProvider($app->make('cache')->store(), $this->fakeSecret()),
            default => throw new InvalidArgumentException('Unknown video driver ['.$this->driver('video').'].'),
        });

        $this->app->singleton(PaymentGateway::class, fn (Application $app): PaymentGateway => match ($this->driver('payments')) {
            'fake' => new FakeGateway($app->make('cache')->store(), $this->fakeSecret()),
            default => throw new InvalidArgumentException('Unknown payments driver ['.$this->driver('payments').'].'),
        });

        $this->app->singleton(SmsSender::class, fn (Application $app): SmsSender => match ($this->driver('sms')) {
            'log' => new LogSmsSender($app->make('log')),
            default => throw new InvalidArgumentException('Unknown SMS driver ['.$this->driver('sms').'].'),
        });

        $this->app->singleton(BotChallenge::class, fn (Application $app): BotChallenge => match ($this->driver('bot_challenge')) {
            'fake' => new FakeBotChallenge,
            'turnstile' => new TurnstileBotChallenge($app->make('http'), $app->make('log'), (string) config('yekkola.turnstile.secret')),
            default => throw new InvalidArgumentException('Unknown bot challenge driver ['.$this->driver('bot_challenge').'].'),
        });
    }

    public function boot(): void
    {
        self::assertSafeForEnvironment($this->app->isProduction(), (array) config('yekkola.drivers'));

        if ($this->app->isProduction() && $this->driver('bot_challenge') === 'turnstile' && (string) config('yekkola.turnstile.secret') === '') {
            throw new RuntimeException('TURNSTILE_SECRET_KEY must be set in production.');
        }
    }

    /**
     * @param  array<string, mixed>  $drivers
     *
     * @throws RuntimeException
     */
    public static function assertSafeForEnvironment(bool $production, array $drivers): void
    {
        if (! $production) {
            return;
        }

        $unsafe = array_filter($drivers, fn (mixed $driver) => in_array($driver, self::UNSAFE_DRIVERS, true));

        if ($unsafe !== []) {
            throw new RuntimeException(
                'Refusing to boot production with fake/log integration drivers: '.
                implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($unsafe), $unsafe)).
                '. Configure real providers (config/yekkola.php).'
            );
        }
    }

    private function driver(string $service): string
    {
        return (string) config("yekkola.drivers.{$service}");
    }

    private function fakeSecret(): string
    {
        $secret = (string) config('yekkola.fake_webhook_secret');

        if ($secret === '') {
            if (! $this->app->environment(['local', 'testing'])) {
                throw new RuntimeException('FAKE_WEBHOOK_SECRET must be set when fake drivers run outside local/testing.');
            }

            return 'local-fake-webhook-secret';
        }

        return $secret;
    }
}
