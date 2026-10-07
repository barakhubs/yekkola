<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Integrations\InvalidWebhookPayload;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Payments\Exceptions\GatewayOutcomeUnknown;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\Exceptions\UnknownTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fake mobile-money gateway for local, staging, and tests (PRD-05 FR-18). Refused in production.
 *
 * The outcome comes from the last 4 digits of the payer/recipient number (see Scenario), or from queue()
 * in tests. Each status lookup is one "check"; scenarios that change over time move on per check.
 * Starting again with the same reference returns the existing transaction without moving it on.
 *
 * State is kept in the cache (so later requests — webhooks, re-checks — see it). Staging should use a
 * cache store that doesn't evict; if state is lost, lookups throw UnknownTransaction (never "failed").
 */
final class FakeGateway implements PaymentGateway
{
    public const WEBHOOK_SIGNATURE_HEADER = 'X-Fake-Signature';

    private const PREFIX = 'fake-gateway:';

    /** @var list<Scenario> */
    private array $queued = [];

    /** @var list<MobileMoneyRail> */
    private array $unavailableRails = [];

    public function __construct(
        private readonly Cache $cache,
        private readonly string $webhookSecret,
    ) {}

    public function name(): string
    {
        return 'fake';
    }

    public function supports(MobileMoneyRail $rail, Currency $currency): bool
    {
        return ! in_array($rail, $this->unavailableRails, true);
    }

    public function limits(MobileMoneyRail $rail, Currency $currency): AmountLimits
    {
        return match ($currency) {
            Currency::USD => new AmountLimits(Money::of(100, $currency), Money::of(500_000, $currency)),
            Currency::CDF => new AmountLimits(Money::of(10_000, $currency), Money::of(1_500_000_000, $currency)),
        };
    }

    /** Force the outcome of the next start calls, in order (tests). */
    public function queue(Scenario ...$scenarios): void
    {
        array_push($this->queued, ...$scenarios);
    }

    /** Simulate a rail outage (tests). */
    public function makeUnavailable(MobileMoneyRail $rail): void
    {
        $this->unavailableRails[] = $rail;
    }

    public function collect(CollectionRequest $request): GatewayResult
    {
        return $this->start(TransactionKind::Collection, $request->reference, $request->amount, $request->rail, $request->payer->e164);
    }

    public function collectionStatus(string $reference): GatewayResult
    {
        return $this->check($reference, TransactionKind::Collection);
    }

    public function disburse(DisbursementRequest $request): GatewayResult
    {
        return $this->start(TransactionKind::Disbursement, $request->reference, $request->amount, $request->rail, $request->recipient->e164);
    }

    public function disbursementStatus(string $reference): GatewayResult
    {
        return $this->check($reference, TransactionKind::Disbursement);
    }

    public function parseWebhook(Request $request): GatewayWebhookEvent
    {
        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSecret);

        if (! hash_equals($expected, (string) $request->header(self::WEBHOOK_SIGNATURE_HEADER))) {
            throw InvalidWebhookSignature::make();
        }

        foreach (['event_id', 'reference'] as $field) {
            if (! is_string($request->input($field)) || $request->input($field) === '') {
                throw InvalidWebhookPayload::missing($field);
            }
        }

        $kind = TransactionKind::tryFrom((string) $request->input('kind'))
            ?? throw InvalidWebhookPayload::missing('kind');

        return new GatewayWebhookEvent(
            eventId: (string) $request->input('event_id'),
            kind: $kind,
            reference: (string) $request->input('reference'),
            gatewayReference: is_string($request->input('gateway_reference')) ? $request->input('gateway_reference') : null,
            payload: $request->all(),
        );
    }

    /**
     * A correctly signed webhook announcing a change on one of our transactions (tests, staging tools).
     */
    public function webhookFor(string $reference, ?string $eventId = null): Request
    {
        $state = $this->state($reference);

        return $this->webhookRequest([
            'event_id' => $eventId ?? 'evt-'.Str::ulid(),
            'kind' => $state['kind'] ?? TransactionKind::Collection->value,
            'reference' => $reference,
            'gateway_reference' => $state['gateway_reference'] ?? null,
        ]);
    }

    /**
     * A correctly signed webhook with any payload (tests).
     *
     * @param  array<string, mixed>  $payload
     */
    public function webhookRequest(array $payload): Request
    {
        $body = (string) json_encode($payload);

        $request = Request::create('/api/v1/webhooks/payments/fake', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set(self::WEBHOOK_SIGNATURE_HEADER, hash_hmac('sha256', $body, $this->webhookSecret));

        return $request;
    }

    private function start(TransactionKind $kind, string $reference, Money $amount, MobileMoneyRail $rail, string $msisdn): GatewayResult
    {
        if (! $this->supports($rail, $amount->currency)) {
            throw new GatewayUnavailable("Rail {$rail->value} is unavailable.");
        }

        // Same reference → same transaction, unchanged (like a real gateway).
        if ($this->state($reference) !== null) {
            return $this->result($reference, advance: false);
        }

        $scenario = array_shift($this->queued) ?? Scenario::fromMsisdn($msisdn);

        if ($scenario === Scenario::Unavailable) {
            throw new GatewayUnavailable('Simulated gateway outage.');
        }

        $created = $this->cache->add(self::PREFIX.'tx:'.$reference, [
            'scenario' => $scenario->value,
            'kind' => $kind->value,
            'gateway_reference' => 'fake-'.Str::ulid(),
            'amount_minor' => $amount->amountMinor,
            'currency' => $amount->currency->value,
        ], now()->addDays(30));

        // Lost a race with a concurrent start for the same reference: return that one.
        if (! $created) {
            return $this->result($reference, advance: false);
        }

        if ($scenario === Scenario::OutcomeUnknown) {
            throw new GatewayOutcomeUnknown('Simulated timeout after the request was sent.');
        }

        $result = $this->result($reference, advance: false);

        return new GatewayResult(
            reference: $reference,
            gatewayReference: $result->gatewayReference,
            status: $scenario->startStatus(),
            failureCode: $scenario->startStatus() === GatewayStatus::Failed ? $scenario->failureCode() : null,
            raw: ['fake' => true, 'scenario' => $scenario->value],
        );
    }

    private function check(string $reference, TransactionKind $kind): GatewayResult
    {
        $state = $this->state($reference);

        if ($state === null || $state['kind'] !== $kind->value) {
            throw new UnknownTransaction("No {$kind->value} with reference [{$reference}].");
        }

        return $this->result($reference, advance: true);
    }

    private function result(string $reference, bool $advance): GatewayResult
    {
        $state = $this->state($reference) ?? throw new UnknownTransaction("No transaction with reference [{$reference}].");

        $checksKey = self::PREFIX.'checks:'.$reference;
        if ($advance) {
            $this->cache->add($checksKey, 0, now()->addDays(30));
            $checks = (int) $this->cache->increment($checksKey);
        } else {
            $checks = (int) $this->cache->get($checksKey, 0);
        }

        $scenario = Scenario::from($state['scenario']);
        $status = $checks === 0 ? $scenario->startStatus() : $scenario->statusAt($checks);
        $currency = Currency::from($state['currency']);
        $amountMinor = $scenario === Scenario::AmountMismatch
            ? $state['amount_minor'] - $currency->collectionStepMinor()
            : $state['amount_minor'];
        $settled = in_array($status, [GatewayStatus::Succeeded, GatewayStatus::Reversed], true);

        return new GatewayResult(
            reference: $reference,
            gatewayReference: $state['gateway_reference'],
            status: $status,
            amount: $settled ? Money::of($amountMinor, $currency) : null,
            fee: $settled ? Money::zero($currency) : null,
            operatorReference: $settled ? 'OP'.strtoupper(substr(hash('sha256', $reference), 0, 10)) : null,
            completedAt: $status->isPending() ? null : CarbonImmutable::now(),
            failureCode: $status === GatewayStatus::Failed ? $scenario->failureCode() : null,
            raw: ['fake' => true, 'scenario' => $scenario->value, 'checks' => $checks],
        );
    }

    /**
     * @return array{scenario: string, kind: string, gateway_reference: string, amount_minor: int, currency: string}|null
     */
    private function state(string $reference): ?array
    {
        $state = $this->cache->get(self::PREFIX.'tx:'.$reference);

        return is_array($state) ? $state : null;
    }
}
