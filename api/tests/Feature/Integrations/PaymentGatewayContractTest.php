<?php

declare(strict_types=1);

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Payments\CollectionRequest;
use App\Integrations\Payments\DisbursementRequest;
use App\Integrations\Payments\FakeGateway;
use App\Integrations\Payments\GatewayStatus;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\Scenario;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/*
| Contract every PaymentGateway must satisfy. Add the real aggregator driver to the dataset when
| it's integrated (with Http::fake() for its API).
*/

dataset('payment gateways', [
    'fake' => fn (): PaymentGateway => new FakeGateway(cache()->store(), 'secret'),
]);

function collection(string $msisdn = '+243812345678', ?string $reference = null): CollectionRequest
{
    return new CollectionRequest(
        reference: $reference ?? (string) Str::ulid(),
        amount: Money::of(1_500, Currency::USD),
        rail: MobileMoneyRail::Orange,
        payer: PhoneNumber::fromString($msisdn),
        description: 'Cours Yekkola',
    );
}

it('starts a collection and can re-check it by gateway reference', function (Closure $make) {
    $gateway = $make();
    $started = $gateway->collect(collection());

    expect($started->gatewayReference)->not->toBeEmpty()
        ->and($gateway->collectionStatus($started->gatewayReference)->gatewayReference)->toBe($started->gatewayReference);
})->with('payment gateways');

it('treats a repeated reference as the same transaction', function (Closure $make) {
    $gateway = $make();
    $reference = (string) Str::ulid();

    $first = $gateway->collect(collection(reference: $reference));
    $again = $gateway->collect(collection(reference: $reference));

    expect($again->gatewayReference)->toBe($first->gatewayReference);
})->with('payment gateways');

it('starts disbursements', function (Closure $make) {
    $result = $make()->disburse(new DisbursementRequest(
        reference: (string) Str::ulid(),
        amount: Money::of(10_000, Currency::CDF),
        rail: MobileMoneyRail::Airtel,
        recipient: PhoneNumber::fromString('+243972345678'),
        recipientName: 'Prof. Mbuyi',
        description: 'Versement Yekkola',
    ));

    expect($result->gatewayReference)->not->toBeEmpty();
})->with('payment gateways');

it('rejects webhooks with a bad signature', function (Closure $make) {
    $request = Request::create('/api/v1/webhooks/payments/x', 'POST', content: '{"event_id":"1"}');
    $request->headers->set(FakeGateway::WEBHOOK_SECRET_HEADER, 'forged');

    $make()->parseWebhook($request);
})->with('payment gateways')->throws(InvalidWebhookSignature::class);

// Fake-specific behaviour (PRD-05 FR-18)

it('simulates every outcome from the payer number', function (string $suffix, GatewayStatus $first, array $checks) {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $started = $gateway->collect(collection('+24381234'.$suffix));

    expect($started->status)->toBe($first);
    foreach ($checks as $expected) {
        expect($gateway->collectionStatus($started->gatewayReference)->status)->toBe($expected);
    }
})->with([
    'normal: pending then succeeded' => ['5678', GatewayStatus::Pending, [GatewayStatus::Succeeded]],
    '0001: failed' => ['0001', GatewayStatus::Failed, [GatewayStatus::Failed]],
    '0002: pending forever' => ['0002', GatewayStatus::Pending, [GatewayStatus::Pending, GatewayStatus::Pending]],
    '0003: late success' => ['0003', GatewayStatus::Pending, [GatewayStatus::Pending, GatewayStatus::Succeeded]],
    '0004: reversal' => ['0004', GatewayStatus::Pending, [GatewayStatus::Succeeded, GatewayStatus::Reversed]],
]);

it('lets tests force the next outcome', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $gateway->queue(Scenario::Fail);

    expect($gateway->collect(collection())->status)->toBe(GatewayStatus::Failed)
        ->and($gateway->collect(collection())->status)->toBe(GatewayStatus::Pending);
});

it('parses webhooks it signed itself', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');

    $event = $gateway->parseWebhook($gateway->webhookRequest([
        'event_id' => 'evt-1', 'reference' => 'fake-123', 'kind' => 'collection',
    ]));

    expect($event->eventId)->toBe('evt-1')
        ->and($event->gatewayReference)->toBe('fake-123')
        ->and($event->kind)->toBe('collection');
});

it('is bound as a single instance so state is shared within a request', function () {
    expect(app(PaymentGateway::class))->toBe(app(PaymentGateway::class))
        ->toBeInstanceOf(FakeGateway::class);
});
