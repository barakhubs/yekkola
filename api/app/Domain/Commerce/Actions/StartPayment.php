<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Integrations\Payments\CollectionRequest;
use App\Integrations\Payments\Exceptions\GatewayOutcomeUnknown;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Database\ConnectionInterface;

/**
 * Starts a mobile-money payment on an order (PRD-05 FR-05, FR-09): the payer gets a prompt on their phone.
 * One attempt in flight per order; a failed attempt can be retried with the same or another operator until
 * the order expires. The payment row is committed before the gateway call, so a crash mid-call is found by
 * reconciliation (our payment id is the gateway reference). Same `Idempotency-Key` → same payment.
 */
final class StartPayment
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PaymentGateway $gateway,
        private readonly AvailableRails $rails,
        private readonly PaymentLifecycle $lifecycle,
    ) {}

    public function handle(Order $order, MobileMoneyRail $rail, PhoneNumber $payer, string $idempotencyKey): Payment
    {
        $existing = Payment::query()->where('order_id', $order->id)->where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        $currency = Currency::from($order->currency);
        if (! $this->rails->allows($rail, $currency)) {
            throw CommerceException::railUnavailable();
        }
        if (! $this->gateway->limits($rail, $currency)->allows($order->total())) {
            throw CommerceException::amountOutOfRange();
        }

        $payment = $this->db->transaction(function () use ($order, $rail, $payer, $idempotencyKey): Payment {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->with('items')->firstOrFail();

            $replay = Payment::query()->where('order_id', $order->id)->where('idempotency_key', $idempotencyKey)->first();
            if ($replay !== null) {
                return $replay; // a concurrent request with the same key got here first
            }

            if (! $order->status->acceptsPayment()) {
                throw CommerceException::notPayable();
            }
            if ($order->isExpired()) {
                throw CommerceException::orderExpired();
            }
            if (Payment::query()->where('order_id', $order->id)->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])->exists()) {
                throw CommerceException::paymentInProgress();
            }
            // Money already collected on this order (e.g. a different amount was confirmed): staff settle it first.
            if (Payment::query()->where('order_id', $order->id)->where('status', PaymentStatus::Succeeded)->exists()) {
                throw CommerceException::notPayable();
            }
            // Bought meanwhile through another order: don't charge twice. Only for the student's own orders —
            // telling someone else would reveal what the student owns (their payment is refunded instead).
            if ($order->buyer_id === $order->beneficiary_id) {
                $owned = Enrollment::query()->active()->where('user_id', $order->beneficiary_id)->whereIn('course_id', $order->items->pluck('course_id'))->count();
                if ($owned === $order->items->count()) {
                    throw CommerceException::nothingToBuy();
                }
            }

            $payment = Payment::query()->create([
                'order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'gateway' => $this->gateway->name(),
                'rail' => $rail,
                'msisdn' => $payer->e164,
                'amount_minor' => $order->total_minor,
                'currency' => $order->currency,
                'status' => PaymentStatus::Initiated,
            ]);
            $order->forceFill(['status' => OrderStatus::AwaitingPayment])->save();

            return $payment;
        });

        if (! $payment->wasRecentlyCreated) {
            return $payment;
        }

        try {
            $result = $this->gateway->collect(new CollectionRequest(
                reference: $payment->id,
                amount: $order->total(),
                rail: $rail,
                payer: $payer,
                description: "Yekkola {$order->number}",
            ));
        } catch (GatewayUnavailable) {
            return $this->lifecycle->failToStart($payment, 'gateway_unavailable');
        } catch (GatewayOutcomeUnknown) {
            return $this->lifecycle->outcomeUnknown($payment);
        }

        return $this->lifecycle->apply($payment, $result);
    }
}
