<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\Enums\Currency;
use App\Integrations\InvalidWebhookSignature;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Fake mobile-money gateway for local, staging, and tests (PRD-05 FR-18). Refused in production.
 *
 * Outcome is chosen by the last 4 digits of the payer/recipient number, so staging testers can
 * trigger every path from the real UI:
 *
 *   …0001 → failed (insufficient_funds)        …0003 → pending first, succeeds on re-check ("late success")
 *   …0002 → stays pending forever (timeout)     …0004 → succeeds, then reversed on re-check
 *   anything else → pending first, succeeded on re-check (the normal flow)
 *
 * Tests can also force the next outcomes with queue(). State lives in the cache so webhooks and
 * re-checks in later requests see it.
 */
final class FakeGateway implements PaymentGateway
{
    public const WEBHOOK_SECRET_HEADER = 'X-Fake-Signature';

    private const CACHE_PREFIX = 'fake-gateway:';

    /** @var list<Scenario> */
    private array $queued = [];

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
        return true;
    }

    /** Force the outcome of the next collect()/disburse() calls, in order. */
    public function queue(Scenario ...$scenarios): void
    {
        array_push($this->queued, ...$scenarios);
    }

    public function collect(CollectionRequest $request): GatewayResult
    {
        return $this->start('collection', $request->reference, $request->payer->e164);
    }

    public function collectionStatus(string $gatewayReference): GatewayResult
    {
        return $this->check($gatewayReference);
    }

    public function disburse(DisbursementRequest $request): GatewayResult
    {
        return $this->start('disbursement', $request->reference, $request->recipient->e164);
    }

    public function disbursementStatus(string $gatewayReference): GatewayResult
    {
        return $this->check($gatewayReference);
    }

    public function parseWebhook(Request $request): GatewayWebhookEvent
    {
        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSecret);

        if (! hash_equals($expected, (string) $request->header(self::WEBHOOK_SECRET_HEADER))) {
            throw InvalidWebhookSignature::make();
        }

        return new GatewayWebhookEvent(
            eventId: (string) $request->input('event_id'),
            gatewayReference: (string) $request->input('reference'),
            kind: (string) $request->input('kind'),
            payload: $request->all(),
        );
    }

    /**
     * Build a correctly signed webhook request (tests and staging tools).
     *
     * @param  array<string, mixed>  $payload
     */
    public function webhookRequest(array $payload): Request
    {
        $body = (string) json_encode($payload);

        $request = Request::create('/api/v1/webhooks/payments/fake', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        $request->headers->set(self::WEBHOOK_SECRET_HEADER, hash_hmac('sha256', $body, $this->webhookSecret));

        return $request;
    }

    private function start(string $kind, string $reference, string $msisdn): GatewayResult
    {
        // Same reference → same transaction (idempotent like a real gateway).
        $existing = $this->cache->get(self::CACHE_PREFIX.'ref:'.$reference);
        if (is_string($existing)) {
            return $this->check($existing);
        }

        $scenario = array_shift($this->queued) ?? Scenario::fromMsisdn($msisdn);
        $gatewayReference = 'fake-'.Str::ulid();

        $this->cache->forever(self::CACHE_PREFIX.'ref:'.$reference, $gatewayReference);
        $this->cache->forever(self::CACHE_PREFIX.$gatewayReference, ['scenario' => $scenario->value, 'kind' => $kind, 'checks' => 0]);

        return $scenario === Scenario::Fail
            ? new GatewayResult($gatewayReference, GatewayStatus::Failed, 'insufficient_funds', 'Insufficient balance.')
            : new GatewayResult($gatewayReference, GatewayStatus::Pending);
    }

    private function check(string $gatewayReference): GatewayResult
    {
        /** @var array{scenario: string, kind: string, checks: int}|null $state */
        $state = $this->cache->get(self::CACHE_PREFIX.$gatewayReference);

        if ($state === null) {
            return new GatewayResult($gatewayReference, GatewayStatus::Failed, 'not_found', 'Unknown transaction.');
        }

        $state['checks']++;
        $this->cache->forever(self::CACHE_PREFIX.$gatewayReference, $state);

        $status = match (Scenario::from($state['scenario'])) {
            Scenario::Fail => GatewayStatus::Failed,
            Scenario::PendingForever => GatewayStatus::Pending,
            Scenario::Succeed => GatewayStatus::Succeeded,
            Scenario::LateSuccess => $state['checks'] >= 2 ? GatewayStatus::Succeeded : GatewayStatus::Pending,
            Scenario::Reversal => $state['checks'] >= 2 ? GatewayStatus::Reversed : GatewayStatus::Succeeded,
        };

        return new GatewayResult(
            $gatewayReference,
            $status,
            $status === GatewayStatus::Failed ? 'insufficient_funds' : null,
            raw: ['fake' => true, 'checks' => $state['checks']],
        );
    }
}
