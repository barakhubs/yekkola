<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
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
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Shared\ValueObjects\Money;
use App\Integrations\Payments\DisbursementRequest;
use App\Integrations\Payments\Exceptions\GatewayOutcomeUnknown;
use App\Integrations\Payments\Exceptions\GatewayUnavailable;
use App\Integrations\Payments\Exceptions\UnknownTransaction;
use App\Integrations\Payments\GatewayResult;
use App\Integrations\Payments\GatewayStatus;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * The refund state machine (PRD-05 FR-16, PRD-09 FR-12). Staff approve or reject a request; approval
 * removes access, takes the sale back into `refunds_payable`, and queues the payout to the original payer.
 * Money collected but never applied is sent back the same way (no sale to take back). Each payout attempt
 * has its own gateway reference; results are re-queried (webhook, reconciliation) and applied idempotently.
 *
 *   requested → approved → processing → completed
 *            ↘ rejected              ↘ failed → (retry) processing
 */
final class RefundLifecycle
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PaymentGateway $gateway,
        private readonly RefundPostings $postings,
        private readonly RevokeEnrollments $revoke,
        private readonly RecordAudit $recordAudit,
        private readonly Dispatcher $events,
        private readonly LoggerInterface $log,
    ) {}

    public function approve(Refund $refund, User $staff, ?string $note): Refund
    {
        $refund = $this->locked($refund, function (Refund $refund, Order $order) use ($staff, $note): void {
            if ($refund->status !== RefundStatus::Requested) {
                throw CommerceException::refundInvalidTransition();
            }

            /** @var OrderItem $item */
            $item = $order->items->firstWhere('id', $refund->order_item_id);
            $refund->forceFill(['status' => RefundStatus::Approved, 'decided_by' => $staff->id, 'decided_at' => now(), 'decision_note' => $note])->save();

            $this->revoke->handle([$item->id], 'refunded');
            $this->postings->approved($refund, $item);
            $order->forceFill(['status' => $this->allItemsRefunded($order) ? OrderStatus::Refunded : OrderStatus::PartiallyRefunded])->save();

            $this->recordAudit->handle('refund.approved', $refund, ['note' => $note, 'order_id' => $order->id], causer: $staff);
        });

        $this->events->dispatch(new RefundDecided($refund->id));
        ExecuteRefund::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    public function reject(Refund $refund, User $staff, string $note): Refund
    {
        $refund = $this->locked($refund, function (Refund $refund) use ($staff, $note): void {
            if ($refund->status !== RefundStatus::Requested) {
                throw CommerceException::refundInvalidTransition();
            }

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
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $owed = $this->unappliedAmount($payment);

            if ($owed === null || ! $owed->isPositive()) {
                throw CommerceException::nothingUnapplied();
            }
            if (Refund::query()->where('payment_id', $payment->id)->where('kind', RefundKind::Unapplied)->where('status', '!=', RefundStatus::Rejected)->exists()) {
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

    /** A failed payout tried again, under a new gateway reference. */
    public function retry(Refund $refund, User $staff): Refund
    {
        $refund = $this->locked($refund, function (Refund $refund) use ($staff): void {
            if ($refund->status !== RefundStatus::Failed) {
                throw CommerceException::refundInvalidTransition();
            }

            $refund->forceFill(['status' => RefundStatus::Approved, 'failure_code' => null, 'failure_message' => null])->save();
            $this->recordAudit->handle('refund.retried', $refund, [], causer: $staff);
        });

        ExecuteRefund::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    /** Starts the payout of an approved refund to the original payer. */
    public function execute(Refund $refund): Refund
    {
        $started = false;
        $refund = $this->locked($refund, function (Refund $refund) use (&$started): void {
            if ($refund->status !== RefundStatus::Approved) {
                return; // already started, or done
            }
            $refund->forceFill(['status' => RefundStatus::Processing, 'attempt' => $refund->attempt + 1, 'gateway' => $this->gateway->name()])->save();
            $started = true;
        });

        if (! $started) {
            return $refund;
        }

        $payment = $refund->payment;

        try {
            $result = $this->gateway->disburse(new DisbursementRequest(
                reference: $refund->reference(),
                amount: $refund->amount(),
                rail: $payment->rail,
                recipient: $payment->payer(),
                recipientName: '',
                description: "Yekkola refund {$refund->order->number}",
                relatedReference: $payment->id,
            ));
        } catch (GatewayUnavailable) {
            return $this->apply($refund, new GatewayResult($refund->reference(), '', GatewayStatus::Failed, failureCode: 'gateway_unavailable'));
        } catch (GatewayOutcomeUnknown) {
            return $this->locked($refund, fn (Refund $r) => $r->forceFill(['checks' => $r->checks + 1, 'last_checked_at' => now()])->save());
        }

        return $this->apply($refund, $result);
    }

    /** Re-queries a payout in progress (webhook, reconciliation). */
    public function sync(Refund $refund): Refund
    {
        if ($refund->status !== RefundStatus::Processing) {
            return $refund;
        }

        try {
            return $this->apply($refund, $this->gateway->disbursementStatus($refund->reference()));
        } catch (UnknownTransaction|GatewayUnavailable) {
            return $this->locked($refund, fn (Refund $r) => $r->forceFill(['checks' => $r->checks + 1, 'last_checked_at' => now()])->save());
        }
    }

    private function apply(Refund $refund, GatewayResult $result): Refund
    {
        return $this->locked($refund, function (Refund $refund) use ($result): void {
            $refund->forceFill(['checks' => $refund->checks + 1, 'last_checked_at' => now()]);

            if ($refund->status !== RefundStatus::Processing || $result->reference !== $refund->reference()) {
                $refund->save(); // a stale or foreign answer never moves a refund

                return;
            }

            $refund->forceFill([
                'gateway_reference' => $refund->gateway_reference ?? ($result->gatewayReference !== '' ? $result->gatewayReference : null),
                'operator_reference' => $refund->operator_reference ?? $result->operatorReference,
            ]);

            match ($result->status) {
                GatewayStatus::Pending => null,
                GatewayStatus::Succeeded => $this->completed($refund, $result),
                GatewayStatus::Failed, GatewayStatus::Reversed => $refund->forceFill([
                    'status' => RefundStatus::Failed,
                    'failure_code' => $result->failureCode ?? 'failed',
                    'failure_message' => $result->failureMessage === null ? null : mb_substr($result->failureMessage, 0, 255),
                ]),
            };

            $refund->save();
        });
    }

    private function completed(Refund $refund, GatewayResult $result): void
    {
        if ($result->amount !== null && ! $result->amount->equals($refund->amount())) {
            $this->log->critical('Refund paid out with a different amount', ['refund_id' => $refund->id, 'confirmed_minor' => $result->amount->amountMinor]);
        }

        $fee = $result->fee !== null && $result->fee->currency === $refund->amount()->currency ? $result->fee : null;
        $refund->forceFill(['status' => RefundStatus::Completed, 'completed_at' => $result->completedAt ?? now(), 'fee_minor' => $fee?->amountMinor]);
        $this->postings->paid($refund);
    }

    /** What a payment's unapplied money still owes the payer, or null if it never had any. */
    private function unappliedAmount(Payment $payment): ?Money
    {
        $types = [LedgerTransactionType::UnappliedReceipt, LedgerTransactionType::UnappliedReceiptReversal];
        $entries = LedgerEntry::query()
            ->whereHas('account', fn ($q) => $q->where('type', LedgerAccountType::RefundsPayable))
            ->whereIn('ledger_transaction_id', fn ($q) => $q->select('id')->from('ledger_transactions')
                ->whereIn('type', array_map(fn ($t) => $t->value, $types))
                ->where('reference_type', 'payment')->where('reference_id', $payment->id))
            ->get();

        if ($entries->isEmpty()) {
            return null;
        }

        $credit = $entries->sum(fn (LedgerEntry $e) => $e->direction->value === 'credit' ? $e->amount_minor : -$e->amount_minor);

        return Money::of((int) $credit, $payment->amount()->currency);
    }

    private function allItemsRefunded(Order $order): bool
    {
        $refunded = Refund::query()->where('order_id', $order->id)->where('kind', RefundKind::Item)
            ->whereNotIn('status', [RefundStatus::Requested, RefundStatus::Rejected])->distinct()->count('order_item_id');

        return $refunded >= $order->items->where('price_minor', '>', 0)->count();
    }

    /**
     * @param  callable(Refund, Order): mixed  $apply
     */
    private function locked(Refund $refund, callable $apply): Refund
    {
        return $this->db->transaction(function () use ($refund, $apply): Refund {
            $order = Order::query()->whereKey($refund->order_id)->lockForUpdate()->with('items')->firstOrFail();
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->with('payment', 'order')->firstOrFail();
            $apply($locked, $order);

            return $locked;
        });
    }
}
