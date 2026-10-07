<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\Enums\Currency;
use App\Integrations\InvalidWebhookSignature;
use Illuminate\Http\Request;

/**
 * Mobile-money aggregator: collections from students and disbursements to professors (PRD-05, PRD-08).
 *
 * Mobile money is asynchronous — collect()/disburse() usually return `pending`; the final status arrives
 * by webhook and must always be re-confirmed with collectionStatus()/disbursementStatus() before acting on it.
 * Implementations must be safe to call again with the same `reference` (our idempotent id).
 */
interface PaymentGateway
{
    public function name(): string;

    public function supports(MobileMoneyRail $rail, Currency $currency): bool;

    /** Ask the payer to approve a payment (USSD/push prompt on their phone). */
    public function collect(CollectionRequest $request): GatewayResult;

    public function collectionStatus(string $gatewayReference): GatewayResult;

    /** Send money to a mobile-money account (professor payouts, refunds). */
    public function disburse(DisbursementRequest $request): GatewayResult;

    public function disbursementStatus(string $gatewayReference): GatewayResult;

    /**
     * Verify and parse an inbound webhook. Never trust the body alone — re-query the status afterwards.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): GatewayWebhookEvent;
}
