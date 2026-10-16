<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Payment;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\Exceptions\UnknownTransaction;
use App\Integrations\Payments\PaymentGateway;

/**
 * Re-queries a payment's status from the gateway and applies it (PRD-05 FR-07). Used by webhooks, client
 * polling, and reconciliation — the gateway's status API is the only source of truth.
 */
final class SyncPayment
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly PaymentLifecycle $lifecycle,
    ) {}

    public function handle(Payment $payment): Payment
    {
        if ($payment->gateway !== $this->gateway->name()) {
            return $payment; // started through another gateway; its driver must reconcile it
        }

        try {
            return $this->lifecycle->apply($payment, $this->gateway->collectionStatus($payment->id));
        } catch (UnknownTransaction|GatewayUnavailable) {
            return $this->lifecycle->checked($payment); // look again later
        }
    }
}
