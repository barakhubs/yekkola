<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderFlag;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Events\PaymentReversed;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Finance\Actions\PostOrderReversal;
use App\Domain\Finance\Actions\PostSale;
use App\Domain\Finance\Actions\PostUnappliedReceipt;
use App\Domain\Finance\Actions\ReversePosting;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Learning\Actions\RevokeEnrollments;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Integrations\Payments\GatewayResult;
use App\Integrations\Payments\GatewayStatus;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * The payment state machine (PRD-05 FR-07–10, BR-01, BR-04). Every gateway result — start, webhook re-query,
 * poll, reconciliation — goes through apply(), which locks the payment and its order and is safe to run any
 * number of times. Success is the only path to purchase enrollments and the sale posting, all in one
 * transaction. Money collected that can't be applied (amount mismatch, second success) is posted as owed
 * back to the payer, so the ledger always matches what the gateway holds. Nothing here trusts a webhook
 * body: results come from the gateway's status API.
 *
 *   initiated → pending → succeeded → reversed
 *                       ↘ failed / expired → succeeded (late success, flagged)
 */
final class PaymentLifecycle
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FulfilOrder $fulfil,
        private readonly RevokeEnrollments $revoke,
        private readonly PostSale $postSale,
        private readonly PostUnappliedReceipt $postUnapplied,
        private readonly ReversePosting $reverse,
        private readonly PostOrderReversal $postReversal,
        private readonly SettleRefundsOnReversal $settleRefunds,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $log,
    ) {}

    public function apply(Payment $payment, GatewayResult $result): Payment
    {
        return $this->locked($payment, function (Payment $payment, Order $order) use ($result): void {
            if ($result->reference !== $payment->id) {
                // A driver bug answering for another transaction must never move this one.
                $this->log->critical('Gateway result for another reference ignored', ['payment_id' => $payment->id, 'reference' => $result->reference]);
                $payment->forceFill(['checks' => $payment->checks + 1, 'last_checked_at' => now()])->save();

                return;
            }

            $payment->forceFill([
                'checks' => $payment->checks + 1,
                'last_checked_at' => now(),
                'gateway_reference' => $payment->gateway_reference ?? ($result->gatewayReference !== '' ? $result->gatewayReference : null),
                'operator_reference' => $payment->operator_reference ?? $result->operatorReference,
                'last_result' => ['status' => $result->status->value, 'failure_code' => $result->failureCode, 'raw' => $result->raw],
            ]);

            match ($result->status) {
                GatewayStatus::Pending => $this->pending($payment),
                GatewayStatus::Failed => $this->failed($payment, $result->failureCode, $result->failureMessage),
                GatewayStatus::Succeeded => $this->succeeded($payment, $order, $result),
                GatewayStatus::Reversed => $this->reversed($payment, $order),
            };

            $payment->save();
            $order->save();
        });
    }

    /** Nothing reached the payer (gateway unavailable): the order can be retried at once. */
    public function failToStart(Payment $payment, string $code): Payment
    {
        return $this->locked($payment, function (Payment $payment) use ($code): void {
            $this->failed($payment, $code, null);
            $payment->save();
        });
    }

    /** The start call's outcome is unknown: keep it in flight; reconciliation looks it up by our reference. */
    public function outcomeUnknown(Payment $payment): Payment
    {
        return $this->locked($payment, function (Payment $payment): void {
            $this->pending($payment);
            $payment->save();
        });
    }

    /** A status lookup that could not answer (unknown yet, gateway down): only record that we looked. */
    public function checked(Payment $payment): Payment
    {
        return $this->locked($payment, function (Payment $payment): void {
            $payment->forceFill(['checks' => $payment->checks + 1, 'last_checked_at' => now()])->save();
        });
    }

    /** Order timed out (FR-10): in-flight attempts stop counting; a late success still completes it. */
    public function expire(Order $order): void
    {
        $this->db->transaction(function () use ($order): void {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->status->acceptsPayment()) {
                return;
            }

            Payment::query()->where('order_id', $order->id)->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])
                ->update(['status' => PaymentStatus::Expired, 'updated_at' => now()]);
            $order->forceFill(['status' => OrderStatus::Expired])->save();
        });
    }

    private function pending(Payment $payment): void
    {
        if ($payment->status === PaymentStatus::Initiated) {
            $payment->status = PaymentStatus::Pending;
        }
    }

    private function failed(Payment $payment, ?string $code, ?string $message): void
    {
        if (! $payment->status->inFlight()) {
            return; // a stale "failed" never undoes a success or an expiry
        }

        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failure_code' => $code ?? 'failed',
            'failure_message' => $message === null ? null : mb_substr($message, 0, 255),
        ]);
    }

    private function succeeded(Payment $payment, Order $order, GatewayResult $result): void
    {
        if (in_array($payment->status, [PaymentStatus::Succeeded, PaymentStatus::Reversed], true)) {
            return; // already handled
        }

        $currency = Currency::from($payment->currency);
        $fee = $result->fee !== null && $result->fee->currency === $currency ? $result->fee : null;
        if ($result->fee !== null && $fee === null) {
            $this->log->critical('Gateway fee in another currency ignored', ['payment_id' => $payment->id]);
        }

        $payment->forceFill([
            'status' => PaymentStatus::Succeeded,
            'succeeded_at' => $result->completedAt ?? now(),
            'confirmed_amount_minor' => $result->amount?->amountMinor,
            'fee_minor' => $fee?->amountMinor,
            'failure_code' => null,
            'failure_message' => null,
        ]);

        if ($result->amount === null || ! $result->amount->equals($payment->amount())) {
            // Money moved but not what we asked for: no access; it is owed back until staff settle it, and the
            // order takes no new attempt meanwhile (StartPayment).
            $this->flag($payment, $order, OrderFlag::AmountMismatch);
            if ($result->amount !== null && $result->amount->isPositive()) {
                $this->postUnapplied->handle($payment, $result->amount, OrderFlag::AmountMismatch->value);
            }
            $this->log->critical('Payment confirmed with a different amount', [
                'payment_id' => $payment->id,
                'expected_minor' => $payment->amount_minor,
                'confirmed_minor' => $result->amount?->amountMinor,
                'currency' => $result->amount->currency->value ?? $currency->value,
            ]);

            return;
        }

        $this->completeOrder($order, $payment);
    }

    private function completeOrder(Order $order, Payment $payment): void
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::AwaitingPayment, OrderStatus::Expired, OrderStatus::Cancelled], true)) {
            $this->duplicate($order, $payment); // already paid by another attempt (or reversed/refunded)

            return;
        }

        // Per item: only access newly granted is sold (and uses the coupon).
        [$granted, $owned] = $this->fulfil->handle($order);

        if ($granted->isEmpty()) {
            // Everything was already owned, e.g. bought again after this order expired: a second charge.
            $this->duplicate($order, $payment);

            return;
        }

        $order->forceFill([
            'status' => OrderStatus::Paid,
            'paid_at' => $payment->succeeded_at ?? now(),
            'paid_payment_id' => $payment->id,
        ]);
        if (in_array($order->getOriginal('status'), [OrderStatus::Expired, OrderStatus::Cancelled], true)) {
            $this->flag($payment, $order, OrderFlag::LateSuccess);
        }

        $this->postSale->handle($order, $payment, $granted);

        if ($owned->isNotEmpty()) {
            // Some courses were already owned: their price is owed back (staff refund those items).
            $this->flag($payment, $order, OrderFlag::AlreadyEnrolled);
            $this->postUnapplied->handle($payment, $owned->reduce(fn (Money $sum, OrderItem $i) => $sum->plus($i->price()), Money::zero(Currency::from($order->currency))), OrderFlag::AlreadyEnrolled->value);
        }

        $this->events->dispatch(new OrderPaid($order->id));
    }

    /** A success that buys nothing new: the money is owed back to the payer, no sale, no access change. */
    private function duplicate(Order $order, Payment $payment): void
    {
        $this->flag($payment, $order, OrderFlag::DuplicatePayment);
        $this->postUnapplied->handle($payment, $payment->amount(), OrderFlag::DuplicatePayment->value);
        $this->log->critical('Successful payment that buys nothing new', ['order_id' => $order->id, 'payment_id' => $payment->id]);
    }

    private function flag(Payment $payment, Order $order, OrderFlag $flag): void
    {
        $payment->flag = OrderFlag::raise($payment->flag, $flag);
        $order->flag = OrderFlag::raise($order->flag, $flag);
    }

    private function reversed(Payment $payment, Order $order): void
    {
        if ($payment->status === PaymentStatus::Reversed) {
            return;
        }
        $wasPaidByThis = $payment->status === PaymentStatus::Succeeded && $order->paid_payment_id === $payment->id
            && in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded, OrderStatus::Refunded], true);

        $payment->status = PaymentStatus::Reversed;
        $payment->flag = OrderFlag::raise($payment->flag, OrderFlag::Reversed);

        // The payer has all their money back: refunds not paid out yet are called off.
        $paidOut = $this->settleRefunds->handle($payment);

        // Money we held as owed back (mismatch, duplicate) went back to the payer.
        $this->reverse->handle(LedgerTransactionType::UnappliedReceipt, LedgerTransactionType::UnappliedReceiptReversal, PostUnappliedReceipt::REFERENCE, $payment->id, "Reversal of payment {$payment->id}");

        if (! $wasPaidByThis) {
            return; // never granted anything: nothing else to undo
        }

        // BR-04: access removed, sale reversed (courses already refunded become a payer debt), staff alerted.
        $sold = $order->items->filter(fn (OrderItem $item) => $item->granted_at !== null)->values();
        $order->status = OrderStatus::Reversed;
        $order->flag = OrderFlag::raise($order->flag, OrderFlag::Reversed);
        $this->revoke->handle($order->items->pluck('id')->all(), 'payment_reversed');
        CouponRedemption::query()->where('order_id', $order->id)->delete(); // the coupon use is given back
        $this->postReversal->handle($order, $payment, $sold, $paidOut);
        $this->log->critical('Payment reversed after success', ['order_id' => $order->id, 'payment_id' => $payment->id]);
        $this->events->dispatch(new PaymentReversed($order->id, $payment->id));
    }

    /**
     * @param  callable(Payment, Order): void  $apply
     */
    private function locked(Payment $payment, callable $apply): Payment
    {
        return $this->db->transaction(function () use ($payment, $apply): Payment {
            // Order first, then payment: the same lock order as StartPayment and expire().
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->with('items')->firstOrFail();
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $apply($locked, $order);

            return $locked;
        });
    }
}
