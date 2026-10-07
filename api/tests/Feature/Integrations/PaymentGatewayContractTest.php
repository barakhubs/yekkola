<?php

declare(strict_types=1);

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\InvalidWebhookPayload;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Payments\CollectionRequest;
use App\Integrations\Payments\DisbursementRequest;
use App\Integrations\Payments\Exceptions\GatewayOutcomeUnknown;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\Exceptions\UnknownTransaction;
use App\Integrations\Payments\FakeGateway;
use App\Integrations\Payments\GatewayStatus;
use App\Integrations\Payments\PaymentGateway;
use App\Integrations\Payments\Scenario;
use App\Integrations\Payments\TransactionKind;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/*
| Contract every PaymentGateway must satisfy. Add the real aggregator driver to the dataset when it's
| integrated (with Http::fake() for its API). Fake-specific scenario tests follow below.
*/

dataset('payment gateways', [
    'fake' => fn (): PaymentGateway => new FakeGateway(cache()->store(), 'secret'),
]);

function collection(string $msisdn = '+243812345678', ?string $reference = null, int $amountMinor = 1_500, Currency $currency = Currency::USD): CollectionRequest
{
    return new CollectionRequest(
        reference: $reference ?? (string) Str::ulid(),
        amount: Money::of($amountMinor, $currency),
        rail: MobileMoneyRail::Orange,
        payer: PhoneNumber::fromString($msisdn),
        description: 'Cours Yekkola',
    );
}

function disbursement(string $msisdn = '+243972345678', ?string $reference = null): DisbursementRequest
{
    return new DisbursementRequest(
        reference: $reference ?? (string) Str::ulid(),
        amount: Money::of(10_000, Currency::CDF),
        rail: MobileMoneyRail::Airtel,
        recipient: PhoneNumber::fromString($msisdn),
        recipientName: 'Prof. Mbuyi',
        description: 'Versement Yekkola',
    );
}

it('looks collections up by our reference and confirms amount and currency', function (Closure $make) {
    $gateway = $make();
    $request = collection();

    $started = $gateway->collect($request);
    $status = $gateway->collectionStatus($request->reference);

    expect($started->reference)->toBe($request->reference)
        ->and($started->gatewayReference)->not->toBeEmpty()
        ->and($status->reference)->toBe($request->reference)
        ->and($status->gatewayReference)->toBe($started->gatewayReference)
        ->and($status->status)->toBe(GatewayStatus::Succeeded)
        ->and($status->amount?->equals($request->amount))->toBeTrue()
        ->and($status->operatorReference)->not->toBeNull()
        ->and($status->completedAt)->not->toBeNull();
})->with('payment gateways');

it('never creates a second transaction for a repeated reference', function (Closure $make) {
    $gateway = $make();
    $reference = (string) Str::ulid();

    $first = $gateway->collect(collection(reference: $reference));
    $again = $gateway->collect(collection(reference: $reference));
    $payout = $gateway->disburse(disbursement(reference: $ref = (string) Str::ulid()));
    $payoutAgain = $gateway->disburse(disbursement(reference: $ref));

    expect($again->gatewayReference)->toBe($first->gatewayReference)
        ->and($payoutAgain->gatewayReference)->toBe($payout->gatewayReference);
})->with('payment gateways');

it('looks disbursements up by our reference', function (Closure $make) {
    $gateway = $make();
    $request = disbursement();
    $gateway->disburse($request);

    expect($gateway->disbursementStatus($request->reference)->reference)->toBe($request->reference);
})->with('payment gateways');

it('reports unknown references as unknown, never as failed', function (Closure $make) {
    $make()->collectionStatus('never-sent-'.Str::ulid());
})->with('payment gateways')->throws(UnknownTransaction::class);

it('publishes per-rail amount limits in each currency', function (Closure $make) {
    $limits = $make()->limits(MobileMoneyRail::Mpesa, Currency::CDF);

    expect($limits->min->currency)->toBe(Currency::CDF)
        ->and($limits->max->greaterThanOrEqual($limits->min))->toBeTrue();
})->with('payment gateways');

it('rejects webhooks with a bad signature', function (Closure $make) {
    $request = Request::create('/api/v1/webhooks/payments/x', 'POST', content: '{"event_id":"1"}');
    $request->headers->set(FakeGateway::WEBHOOK_SIGNATURE_HEADER, 'forged');

    $make()->parseWebhook($request);
})->with('payment gateways')->throws(InvalidWebhookSignature::class);

// Request invariants (shared by every driver)

it('refuses invalid transfers before they reach a gateway', function (Closure $build) {
    $build();
})->throws(InvalidArgumentException::class)->with([
    'zero amount' => fn () => collection(amountMinor: 0),
    'negative amount' => fn () => collection(amountMinor: -500),
    'CDF centimes (not collectable)' => fn () => collection(amountMinor: 250_050, currency: Currency::CDF),
    'empty reference' => fn () => collection(reference: ''),
    'reference with spaces' => fn () => collection(reference: 'order 1'),
]);

// Fake-specific behaviour (PRD-05 FR-18)

it('simulates every scenario from the number suffix', function (string $suffix, GatewayStatus $start, array $checks, ?string $failure) {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $request = collection('+243812'.str_pad($suffix, 6, '0', STR_PAD_LEFT));

    expect($gateway->collect($request)->status)->toBe($start);

    foreach ($checks as $expected) {
        expect($gateway->collectionStatus($request->reference)->status)->toBe($expected);
    }

    if ($failure !== null) {
        expect($gateway->collectionStatus($request->reference)->failureCode)->toBe($failure);
    }
})->with([
    'normal' => ['5678', GatewayStatus::Pending, [GatewayStatus::Succeeded], null],
    '0001 insufficient funds' => ['0001', GatewayStatus::Failed, [GatewayStatus::Failed], 'insufficient_funds'],
    '0002 pending forever' => ['0002', GatewayStatus::Pending, [GatewayStatus::Pending, GatewayStatus::Pending], null],
    '0003 late success' => ['0003', GatewayStatus::Pending, [GatewayStatus::Pending, GatewayStatus::Succeeded], null],
    '0004 reversal' => ['0004', GatewayStatus::Pending, [GatewayStatus::Succeeded, GatewayStatus::Reversed], null],
    '0005 fail then succeed' => ['0005', GatewayStatus::Pending, [GatewayStatus::Failed, GatewayStatus::Succeeded], null],
    '0009 rejected by payer' => ['0009', GatewayStatus::Failed, [GatewayStatus::Failed], 'rejected_by_payer'],
]);

it('simulates a gateway outage without recording anything', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $request = collection('+243812340006');

    expect(fn () => $gateway->collect($request))->toThrow(GatewayUnavailable::class)
        ->and(fn () => $gateway->collectionStatus($request->reference))->toThrow(UnknownTransaction::class);
});

it('simulates a timeout whose payment still goes through, reconcilable by reference', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $request = collection('+243812340007');

    expect(fn () => $gateway->collect($request))->toThrow(GatewayOutcomeUnknown::class)
        ->and($gateway->collectionStatus($request->reference)->status)->toBe(GatewayStatus::Succeeded);
});

it('simulates a confirmed amount that differs from the request', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $request = collection('+243812340008', amountMinor: 250_000, currency: Currency::CDF);
    $gateway->collect($request);

    $confirmed = $gateway->collectionStatus($request->reference)->amount;

    expect($confirmed?->amountMinor)->toBe(249_900)
        ->and($confirmed?->equals($request->amount))->toBeFalse();
});

it('simulates an invalid payout recipient', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $request = disbursement('+243972340010');

    expect($gateway->disburse($request)->failureCode)->toBe('invalid_recipient');
});

it('does not move a scenario on when the same reference is started again', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $request = collection('+243812340004'); // reversal: succeeded on check 1, reversed on check 2

    $gateway->collect($request);
    $gateway->collect($request); // client retry
    $gateway->collect($request);

    expect($gateway->collectionStatus($request->reference)->status)->toBe(GatewayStatus::Succeeded);
});

it('can mark a rail unavailable', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $gateway->makeUnavailable(MobileMoneyRail::Orange);

    expect($gateway->supports(MobileMoneyRail::Orange, Currency::USD))->toBeFalse()
        ->and($gateway->supports(MobileMoneyRail::Airtel, Currency::USD))->toBeTrue()
        ->and(fn () => $gateway->collect(collection()))->toThrow(GatewayUnavailable::class);
});

it('lets tests force the next outcome', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $gateway->queue(Scenario::Fail);

    expect($gateway->collect(collection())->status)->toBe(GatewayStatus::Failed)
        ->and($gateway->collect(collection())->status)->toBe(GatewayStatus::Pending);
});

it('emits signed webhooks for a transaction, carrying our reference', function () {
    $gateway = new FakeGateway(cache()->store(), 'secret');
    $request = collection();
    $gateway->collect($request);

    $event = $gateway->parseWebhook($gateway->webhookFor($request->reference, 'evt-1'));

    expect($event->eventId)->toBe('evt-1')
        ->and($event->reference)->toBe($request->reference)
        ->and($event->kind)->toBe(TransactionKind::Collection)
        ->and($event->gatewayReference)->not->toBeNull();
});

it('rejects signed webhooks without an event id or reference', function (array $payload) {
    $gateway = new FakeGateway(cache()->store(), 'secret');

    $gateway->parseWebhook($gateway->webhookRequest($payload));
})->throws(InvalidWebhookPayload::class)->with([
    'no event id' => [['reference' => 'r1', 'kind' => 'collection']],
    'empty event id' => [['event_id' => '', 'reference' => 'r1', 'kind' => 'collection']],
    'no reference' => [['event_id' => 'e1', 'kind' => 'collection']],
    'unknown kind' => [['event_id' => 'e1', 'reference' => 'r1', 'kind' => 'refund']],
]);

it('is bound as a single instance so state is shared within a request', function () {
    expect(app(PaymentGateway::class))->toBe(app(PaymentGateway::class))
        ->toBeInstanceOf(FakeGateway::class);
});
