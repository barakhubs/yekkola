<?php

declare(strict_types=1);

use App\Providers\IntegrationServiceProvider;

it('refuses to boot production with fake or log drivers', function (array $drivers) {
    IntegrationServiceProvider::assertSafeForEnvironment(true, $drivers);
})->throws(RuntimeException::class, 'Refusing to boot production')->with([
    'fake video' => [['video' => 'fake', 'payments' => 'aggregator', 'sms' => 'provider']],
    'fake payments' => [['video' => 'mux', 'payments' => 'fake', 'sms' => 'provider']],
    'log sms' => [['video' => 'mux', 'payments' => 'aggregator', 'sms' => 'log']],
]);

it('boots production with real drivers', function () {
    IntegrationServiceProvider::assertSafeForEnvironment(true, ['video' => 'mux', 'payments' => 'aggregator', 'sms' => 'provider']);

    expect(true)->toBeTrue();
});

it('allows fake and log drivers outside production', function () {
    IntegrationServiceProvider::assertSafeForEnvironment(false, ['video' => 'fake', 'payments' => 'fake', 'sms' => 'log']);

    expect(true)->toBeTrue();
});
