<?php

declare(strict_types=1);

use App\Providers\IntegrationServiceProvider;

uses(Tests\TestCase::class);

it('refuses to boot production with fake or log drivers', function (array $drivers) {
    IntegrationServiceProvider::assertSafeForEnvironment(true, $drivers);
})->throws(RuntimeException::class, 'Refusing to boot production')->with([
    'fake video' => [['video' => 'fake', 'payments' => 'aggregator', 'sms' => 'provider']],
    'fake payments' => [['video' => 'mux', 'payments' => 'fake', 'sms' => 'provider']],
    'log sms' => [['video' => 'mux', 'payments' => 'aggregator', 'sms' => 'log']],
]);

it('boots production with real drivers and allows fakes elsewhere', function () {
    IntegrationServiceProvider::assertSafeForEnvironment(true, ['video' => 'mux', 'payments' => 'aggregator', 'sms' => 'provider']);
    IntegrationServiceProvider::assertSafeForEnvironment(false, ['video' => 'fake', 'payments' => 'fake', 'sms' => 'log']);

    expect(true)->toBeTrue();
});

it('runs the guard when the provider boots', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['yekkola.drivers' => ['video' => 'fake', 'payments' => 'fake', 'sms' => 'log']]);

    (new IntegrationServiceProvider(app()))->boot();
})->throws(RuntimeException::class, 'Refusing to boot production');

it('requires a fake webhook secret outside local and testing', function () {
    app()->detectEnvironment(fn () => 'staging');
    config(['yekkola.fake_webhook_secret' => null]);
    app()->forgetInstance(App\Integrations\Payments\PaymentGateway::class);

    app(App\Integrations\Payments\PaymentGateway::class);
})->throws(RuntimeException::class, 'FAKE_WEBHOOK_SECRET');
