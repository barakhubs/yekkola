<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Enums\RefundKind;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Events\RefundDecided;
use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Jobs\ExecuteRefund;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\RefundPostings;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\RevokeEnrollments;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Integrations\Payments\DisbursementRequest;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\Exceptions\UnknownTransaction;
use App\Integrations\Payments\GatewayResult;
use App\Integrations\Payments\GatewayStatus;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The refund state machine (PRD-05 FR-16, PRD-09 FR-12). Staff approve or reject a request; approval
 * removes access, takes the sale back into `refunds_payable`, and queues the payout to the original payer.
 * Money collected but never applied is sent back the same way. Every transition locks order → payment →
 * refund (the payment state machine's order) and re-checks that the payment still stands: a reversal
 * calls off what has not been paid out (SettleRefundsOnReversal).
 *
 * Each payout attempt has its own gateway reference (`R-{id}-{n}`); results are always re-queried and
 * applied idempotently. An attempt the gateway never registered is sent again under the same reference,
 * then given up after a day. A late success of an earlier attempt, or of a payout after the operator
 * already repaid the payer, is recorded as owed by the payer and flagged.
 *
 *   requested → approved → processing → completed
 *            ↘ rejected   ↘ cancelled ↘ failed → (retry) processing
 */
final class RefundLifecycle
{
    /** An attempt the gateway still doesn't know after this long is given up (staff can retry). */
    private const GIVE_UP_HOURS = 24;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PaymentGateway $gateway,
        private readonly AvailableRails $rails,
        private readonly RefundPostings $postings,
        private readonly RevokeEnrollments $revoke,
        private readonly RecordAudit $recordAudit,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $log,
    ) {}

    public function approve(Refund $refund, User $staff, ?string $note): Refund
    {
        $refund = $this->locked($refund, function (Refund $refund, Order $order, Payment $payment) use ($staff, $note): void {
            if ($refund->status !== RefundStatus::Requested) {
                throw CommerceException::refundInvalidTransition();
            }
            $this->assertNotOwn($refund, $order, $staff);
            if ($payment->status !== PaymentStatus::Succeeded || ! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)) {
                throw CommerceException::notRefundable(); // reversed (or otherwise settled) since the request
            }

            /** @var OrderItem $item */
            $item = $order->items->firstWhere('id', $refund->order_item_id);
            $this->assertWithinCollected($refund, $payment);

            $refund->forceFill(['status' => RefundStatus::Approved, 'decided_by' => $staff->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->revoke->handle([$item->id], 'refunded');
            $this->postings->approved($refund, $item);
            $order->forceFill(['status' => $this->allSoldItemsRefunded($order) ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded])->save();

            $this->recordAudit->handle('refund.approved', $refund, ['note' => $note, 'order_id' => $order->id], causer: $staff);
        });

        $this->events->dispatch(new RefundDecided($refund->id));
        ExecuteRefund::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    public function reject(Refund $refund, User $staff, string $note): Refund
    {
        $refund = $this->locked($refund, function (Refund $refund, Order $order) use ($staff, $note): void {
            if ($refund->status !== RefundStatus::Requested) {
                throw CommerceException::refundInvalidTransition();
            }
            $this->assertNotOwn($refund, $order, $staff);

            $refund->forceFill(['status' => RefundStatus::Rejected, 'decided_by' => $staff->id, 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->recordAudit->handle('refund.rejected', $refund, ['note' => $note], causer: $staff);
        });

        $this->events->dispatch(new RefundDecided($refund->id));

        return $refund;
    }

    /**
     * Sends back money a payment collected that never bought anything (flagged amount mismatch, duplicate
     * payment, courses already owned). Approved at once by the staff member who starts it.
     */
    public function settleUnapplied(Payment $payment, User $staff, ?string $note): Refund
    {
        $refund = $this->db->transaction(function () use ($payment, $staff, $note): Refund {
            Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $owed = $this->unappliedAmount($payment);

            if ($payment->status === PaymentStatus::Reversed || $owed === null || ! $owed->isPositive()) {
                throw CommerceException::nothingUnapplied();
            }
            if (Refund::query()->where('payment_id', $payment->id)->where('kind', RefundKind::Unapplied)->whereNotIn('status', [RefundStatus::Rejected, RefundStatus::Cancelled])->exists()) {
                throw CommerceException::refundAlreadyRequested();
            }

            $refund = Refund::query()->create([
                'kind' => RefundKind::Unapplied,
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'amount_minor' => $owed->amountMinor,
                'currency' => $owed->currency->value,
                'status' => RefundStatus::Approved,
                'decided_by' => $staff->id,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);
            $this->recordAudit->handle('refund.unapplied_settled', $refund, ['payment_id' => $payment->id, 'note' => $note], causer: $staff);

            return $refund;
        });

        ExecuteRefund::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    /**
     * A failed payout tried again, under a new gateway reference — after checking the failed attempt
     * didn't go through after all.
     */
    public function retry(Refund $refund, User $staff, string $note): Refund
    {
        $refund = $this->sync($refund); // a "failed" attempt can still succeed late

        $refund = $this->locked($refund, function (Refund $refund, Order $order, Payment $payment) use ($staff, $note): void {
            if ($refund->status !== RefundStatus::Failed) {
                throw $refund->status === RefundStatus::Processing ? CommerceException::payoutInFlight() : CommerceException::refundInvalidTransition();
            }
            if ($payment->status === PaymentStatus::Reversed) {
                throw CommerceException::notRefundable();
            }

            $refund->forceFill(['status' => RefundStatus::Approved, 'failure_code' => null, 'failure_message' => null])->save();
            $this->recordAudit->handle('refund.retried', $refund, ['note' => $note], causer: $staff);
        });

        ExecuteRefund::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    /** Starts the payout of an approved refund to the original payer (left approved while the rail is paused). */
    public function execute(Refund $refund): Refund
    {
        $started = false;
        $refund = $this->locked($refund, function (Refund $refund, Order $order, Payment $payment) use (&$started): void {
            if ($refund->status !== RefundStatus::Approved || $payment->status === PaymentStatus::Reversed) {
                return; // already started, done, or called off
            }
            if (! $this->rails->allows($payment->rail, Currency::from($refund->currency))) {
                return; // paused: reconciliation tries again
            }

            $refund->forceFill([
                'status' => RefundStatus::Processing,
                'attempt' => $refund->attempt + 1,
                'attempted_at' => now(),
                'gateway' => $this->gateway->name(),
            ])->save();
            $started = true;
        });

        return $started ? $this->send($refund) : $refund;
    }

    /** Re-queries the current attempt (webhook, reconciliation); a "failed" one may still succeed late. */
    public function sync(Refund $refund): Refund
    {
        if (! in_array($refund->status, [RefundStatus::Processing, RefundStatus::Failed, RefundStatus::Completed], true) || $refund->attempt === 0) {
            return $refund;
        }

        try {
            return $this->apply($refund, $this->gateway->disbursementStatus($refund->reference()));
        } catch (UnknownTransaction) {
            return $refund->status === RefundStatus::Processing ? $this->unknownAtGateway($refund) : $refund;
        } catch (GatewayUnavailable) {
            return $this->touch($refund);
        }
    }

    /**
     * A webhook about a specific attempt. An earlier attempt reporting success means the payer was paid
     * twice: recorded as owed by the payer and flagged.
     */
    public function syncReference(Refund $refund, string $reference): Refund
    {
        if ($reference === $refund->reference()) {
            return $this->sync($refund);
        }

        try {
            $result = $this->gateway->disbursementStatus($reference);
        } catch (UnknownTransaction|GatewayUnavailable) {
            return $refund;
        }

        if ($result->status !== GatewayStatus::Succeeded) {
            return $refund;
        }

        return $this->locked($refund, function (Refund $refund) use ($result, $reference): void {
            $this->postings->overpaid($refund, $result->amount ?? $refund->amount());
            $refund->forceFill(['flag' => 'old_attempt_paid'])->save();
            $this->log->critical('An earlier refund payout attempt also went through', ['refund_id' => $refund->id, 'reference' => $reference]);
        });
    }

    private function send(Refund $refund): Refund
    {
        try {
            return $this->apply($refund, $this->gateway->disburse($this->request($refund)));
        } catch (GatewayUnavailable) {
            return $this->apply($refund, new GatewayResult($refund->reference(), '', GatewayStatus::Failed, failureCode: 'gateway_unavailable'));
        } catch (Throwable $e) {
            // Unknown outcome (timeout, driver error): stay in progress; sync looks it up and re-sends safely.
            $this->log->warning('Refund payout outcome unknown', ['refund_id' => $refund->id, 'exception' => $e::class]);

            return $this->touch($refund);
        }
    }

    /** Same reference again: the gateway creates the transfer only once. After a day, give up (staff retry). */
    private function unknownAtGateway(Refund $refund): Refund
    {
        if ($refund->attempted_at !== null && $refund->attempted_at->lt(now()->subHours(self::GIVE_UP_HOURS))) {
            return $this->locked($refund, function (Refund $refund): void {
                if ($refund->status === RefundStatus::Processing) {
                    $refund->forceFill(['status' => RefundStatus::Failed, 'failure_code' => 'not_found_at_gateway', 'flag' => 'stuck'])->save();
                    $this->log->critical('Refund payout never reached the gateway', ['refund_id' => $refund->id]);
                }
            });
        }

        return $this->send($refund);
    }

    private function apply(Refund $refund, GatewayResult $result): Refund
    {
        return $this->locked($refund, function (Refund $refund, Order $order, Payment $payment) use ($result): void {
            $refund->forceFill(['checks' => $refund->checks + 1, 'last_checked_at' => now()]);

            if ($result->reference !== $refund->reference()) {
                $this->log->warning('Refund result for another reference ignored', ['refund_id' => $refund->id, 'reference' => $result->reference]);
                $refund->save();

                return;
            }

            $refund->forceFill([
                'gateway_reference' => $refund->gateway_reference ?? ($result->gatewayReference !== '' ? $result->gatewayReference : null),
                'operator_reference' => $refund->operator_reference ?? $result->operatorReference,
            ]);

            match (true) {
                $result->status === GatewayStatus::Succeeded && in_array($refund->status, [RefundStatus::Processing, RefundStatus::Failed], true) => $this->completed($refund, $payment, $result),
                $result->status === GatewayStatus::Reversed && $refund->status === RefundStatus::Completed => $this->payoutReversed($refund),
                in_array($result->status, [GatewayStatus::Failed, GatewayStatus::Reversed], true) && $refund->status === RefundStatus::Processing => $this->failed($refund, $payment, $result),
                default => null, // pending, or nothing new
            };

            $refund->save();
        });
    }

    private function completed(Refund $refund, Payment $payment, GatewayResult $result): void
    {
        $confirmed = $result->amount ?? $refund->amount();
        if ($confirmed->currency !== $refund->amount()->currency) {
            $refund->flag = 'amount_mismatch'; // staff settle it; nothing posted in a foreign currency
            $this->log->critical('Refund paid out in another currency', ['refund_id' => $refund->id]);

            return;
        }
        if (! $confirmed->equals($refund->amount())) {
            $refund->flag = 'amount_mismatch'; // the difference stays visible in refunds_payable
            $this->log->critical('Refund paid out with a different amount', ['refund_id' => $refund->id, 'confirmed_minor' => $confirmed->amountMinor]);
        }

        $fee = $result->fee !== null && $result->fee->currency === $confirmed->currency ? $result->fee : null;
        if ($result->fee !== null && $fee === null) {
            $this->log->critical('Refund payout fee in another currency ignored', ['refund_id' => $refund->id]);
        }

        $refund->forceFill(['status' => RefundStatus::Completed, 'completed_at' => $result->completedAt ?? now(), 'fee_minor' => $fee?->amountMinor, 'failure_code' => null]);
        $this->postings->paid($refund, $confirmed, $fee);

        if ($payment->status === PaymentStatus::Reversed) {
            $refund->flag = 'paid_after_reversal'; // the reversal already recorded the payer's debt
        }
    }

    private function failed(Refund $refund, Payment $payment, GatewayResult $result): void
    {
        if ($payment->status === PaymentStatus::Reversed) {
            // The operator already gave the payer their money back: nothing is owed any more.
            $refund->forceFill(['status' => RefundStatus::Cancelled, 'decision_note' => 'payment_reversed']);
            if ($refund->kind === RefundKind::Item) {
                $this->postings->settled($refund);
            }

            return;
        }

        $refund->forceFill([
            'status' => RefundStatus::Failed,
            'failure_code' => $result->failureCode ?? 'failed',
            'failure_message' => $result->failureMessage === null ? null : mb_substr($result->failureMessage, 0, 255),
        ]);
    }

    private function payoutReversed(Refund $refund): void
    {
        $this->postings->payoutReversed($refund);
        $refund->forceFill(['status' => RefundStatus::Failed, 'failure_code' => 'payout_reversed', 'flag' => 'payout_reversed']);
        $this->log->critical('Refund payout reversed by the operator', ['refund_id' => $refund->id]);
    }

    private function touch(Refund $refund): Refund
    {
        return $this->locked($refund, fn (Refund $r) => $r->forceFill(['checks' => $r->checks + 1, 'last_checked_at' => now()])->save());
    }

    private function request(Refund $refund): DisbursementRequest
    {
        $payment = $refund->payment;

        return new DisbursementRequest(
            reference: $refund->reference(),
            amount: $refund->amount(),
            rail: $payment->rail,
            recipient: $payment->payer(),
            recipientName: '',
            description: "Yekkola refund {$refund->order->number}",
            relatedReference: $payment->id,
        );
    }

    /** Staff never decide on an order they bought, received, or asked a refund for. */
    private function assertNotOwn(Refund $refund, Order $order, User $staff): void
    {
        if (in_array($staff->id, [$refund->requested_by, $order->buyer_id, $order->beneficiary_id], true)) {
            throw CommerceException::refundOwnOrder();
        }
    }

    /** Never more back than the payment collected (item refunds plus unapplied money). */
    private function assertWithinCollected(Refund $refund, Payment $payment): void
    {
        $refunded = Refund::query()->where('payment_id', $payment->id)->whereKeyNot($refund->id)
            ->whereNotIn('status', [RefundStatus::Requested, RefundStatus::Rejected, RefundStatus::Cancelled])->sum('amount_minor');

        if ((int) $refunded + $refund->amount_minor > (int) ($payment->confirmed_amount_minor ?? $payment->amount_minor)) {
            throw CommerceException::notRefundable();
        }
    }

    /** What a payment's unapplied money still owes the payer, or null if it never had any. */
    private function unappliedAmount(Payment $payment): ?Money
    {
        $types = [LedgerTransactionType::UnappliedReceipt->value, LedgerTransactionType::UnappliedReceiptReversal->value];
        $entries = LedgerEntry::query()
            ->whereHas('account', fn ($q) => $q->where('type', LedgerAccountType::RefundsPayable))
            ->whereIn('ledger_transaction_id', fn ($q) => $q->select('id')->from('ledger_transactions')
                ->whereIn('type', $types)->where('reference_type', 'payment')->where('reference_id', $payment->id))
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $credit = $entries->sum(fn (LedgerEntry $e) => $e->direction->value === 'credit' ? $e->amount_minor : -$e->amount_minor);

        return Money::of((int) $credit, $payment->amount()->currency);
    }

    /** Every course this order sold (granted access for) has a refund approved or further. */
    private function allSoldItemsRefunded(Order $order): bool
    {
        $sold = $order->items->filter(fn (OrderItem $i) => $i->price_minor > 0 && Enrollment::query()->where('order_item_id', $i->id)->exists())->pluck('id');
        $refunded = Refund::query()->where('order_id', $order->id)->where('kind', RefundKind::Item)
            ->whereNotIn('status', [RefundStatus::Requested, RefundStatus::Rejected, RefundStatus::Cancelled])->pluck('order_item_id');

        return $sold->diff($refunded)->isEmpty();
    }

    /**
     * Locks order → payment → refund (the payment state machine's order), then applies.
     *
     * @param  callable(Refund, Order, Payment): mixed  $apply
     */
    private function locked(Refund $refund, callable $apply): Refund
    {
        return $this->db->transaction(function () use ($refund, $apply): Refund {
            $order = Order::query()->whereKey($refund->order_id)->lockForUpdate()->with('items')->firstOrFail();
            $payment = Payment::query()->whereKey($refund->payment_id)->lockForUpdate()->firstOrFail();
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->with('payment', 'order')->firstOrFail();
            $apply($locked, $order, $payment);

            return $locked;
        });
    }
}
