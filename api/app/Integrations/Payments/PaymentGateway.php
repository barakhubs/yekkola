<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Shared\Enums\Currency;
use App\Integrations\InvalidWebhookSignature;
use App\Integrations\Payments\Exceptions\GatewayOutcomeUnknown;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\Exceptions\UnknownTransaction;
use Illuminate\Http\Request;

/**
 * Mobile-money aggregator: collections from students and disbursements to professors and refunded payers
 * (PRD-05, PRD-08). Refunds are disbursements to the original payer, linked by `relatedReference`.
 *
 * Rules every driver and caller must follow:
 * - `reference` is OUR id (payment/payout/refund id). Status lookups use it, so a payment can be
 *   reconciled even when the start call timed out and we never saw the gateway's id.
 * - Calling collect()/disburse() again with the same reference must never create a second transaction.
 * - Mobile money is asynchronous: a start usually returns `pending`. Any status can still change later
 *   (late success after "failed", reversal after "succeeded") — reconciliation decides what to do.
 * - Webhooks only tell us something changed: verify the signature, then re-query the status. Never act on
 *   the webhook body alone. Drivers must enforce the provider's replay window where it has one.
 * - Always compare the confirmed amount/currency in the result with what we asked for.
 */
interface PaymentGateway
{
    public function name(): string;

    public function supports(MobileMoneyRail $rail, Currency $currency): bool;

    /** Per-transaction limits the gateway enforces for this rail and currency. */
    public function limits(MobileMoneyRail $rail, Currency $currency): AmountLimits;

    /**
     * Ask the payer to approve a payment (USSD/push prompt on their phone).
     *
     * @throws GatewayUnavailable nothing was sent — safe to retry later with the same reference
     * @throws GatewayOutcomeUnknown the request may have reached the payer — reconcile by reference, never start a new one
     */
    public function collect(CollectionRequest $request): GatewayResult;

    /**
     * @throws UnknownTransaction the gateway has no record (yet) — keep the payment pending and retry later
     * @throws GatewayUnavailable
     */
    public function collectionStatus(string $reference): GatewayResult;

    /**
     * Send money to a mobile-money account (professor payouts, refunds).
     *
     * @throws GatewayUnavailable
     * @throws GatewayOutcomeUnknown
     */
    public function disburse(DisbursementRequest $request): GatewayResult;

    /**
     * @throws UnknownTransaction
     * @throws GatewayUnavailable
     */
    public function disbursementStatus(string $reference): GatewayResult;

    /**
     * Verify and parse an inbound webhook.
     *
     * @throws InvalidWebhookSignature
     */
    public function parseWebhook(Request $request): GatewayWebhookEvent;
}
