<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\AttemptStatus;
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
use App\Domain\Commerce\Models\RefundAttempt;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\RefundPostings;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Actions\RevokeEnrollments;
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
 * refund (→ attempt), the payment state machine's order, and re-checks that the payment still stands; a
 * reversal calls off what has not gone out (SettleRefundsOnReversal).
 *
 * Each payout attempt is a RefundAttempt with its own reference and postings. Whatever an attempt reports,
 * whenever it reports it, is accounted for: the first success pays the refund; any other success — a late
 * one, or one after the operator already repaid the payer — is recorded as owed by the payer and flagged.
 * An attempt the gateway never registered is re-sent under the same reference, and given up after a day.
 *
 *   requested → approved → processing → completed
 *            ↘ rejected   ↘ cancelled ↘ failed → (retry) approved
 */
final class RefundLifecycle
{
    /** An attempt the gateway still doesn't know after this long is given up (staff can retry). */
    public const GIVE_UP_HOURS = 24;

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
     * A failed payout tried again under a new reference — after checking the last attempt didn't go
     * through after all, and never while one is still in flight.
     */
    public function retry(Refund $refund, User $staff, string $note): Refund
    {
        $last = RefundAttempt::query()->where('refund_id', $refund->id)->orderByDesc('number')->first();
        if ($last !== null) {
            $this->syncAttempt($last);
        }

        $refund = $this->locked($refund, function (Refund $refund, Order $order, Payment $payment) use ($staff, $note): void {
            if (RefundAttempt::query()->where('refund_id', $refund->id)->where('status', AttemptStatus::Processing)->exists()) {
                throw CommerceException::payoutInFlight();
            }
            // Paid twice already (an older attempt went through): the payer has the money — no third payout.
            if ($refund->status !== RefundStatus::Failed || $payment->status === PaymentStatus::Reversed || $refund->flag === 'paid_twice') {
                throw CommerceException::refundInvalidTransition();
            }

            $refund->forceFill(['status' => RefundStatus::Approved, 'failure_code' => null])->save();
            $this->recordAudit->handle('refund.retried', $refund, ['note' => $note], causer: $staff);
        });

        ExecuteRefund::dispatch($refund->id)->afterCommit();

        return $refund;
    }

    /** Starts a payout attempt for an approved refund (left approved while the rail is paused). */
    public function execute(Refund $refund): Refund
    {
        $attempt = null;
        $refund = $this->locked($refund, function (Refund $refund, Order $order, Payment $payment) use (&$attempt): void {
            if ($refund->status !== RefundStatus::Approved || $payment->status === PaymentStatus::Reversed) {
                return; // already started, done, or called off
            }
            if (! $this->rails->allows($payment->rail, Currency::from($refund->currency))) {
                return; // paused: reconciliation tries again
            }

            $number = $refund->attempts + 1;
            $refund->forceFill(['status' => RefundStatus::Processing, 'attempts' => $number])->save();
            $attempt = RefundAttempt::query()->create([
                'refund_id' => $refund->id,
                'number' => $number,
                'reference' => $refund->referenceFor($number),
                'gateway' => $this->gateway->name(),
                'status' => AttemptStatus::Processing,
            ]);
        });

        if ($attempt !== null) {
            $this->send($refund, $attempt);
        }

        return $refund->refresh();
    }

    /**
     * A webhook or poll about one attempt, by its gateway reference.
     *
     * @return bool Whether the gateway answered (false: try again later).
     */
    public function syncReference(string $reference): bool
    {
        $attempt = RefundAttempt::query()->where('reference', $reference)->first();

        return $attempt === null || $this->syncAttempt($attempt);
    }

    /**
     * Re-queries an attempt: in flight, recently failed (it may still succeed), or succeeded (it may be
     * reversed). The gateway's status API is the only source of truth.
     *
     * @return bool Whether the gateway answered (false: try again later).
     */
    public function syncAttempt(RefundAttempt $attempt): bool
    {
        try {
            $this->apply($attempt, $this->gateway->disbursementStatus($attempt->reference));
        } catch (UnknownTransaction) {
            if ($attempt->status === AttemptStatus::Processing) {
                $this->unknownAtGateway($attempt);
            }
        } catch (GatewayUnavailable) {
            $this->touch($attempt);

            return false;
        }

        return true;
    }

    private function send(Refund $refund, RefundAttempt $attempt, bool $resend = false): void
    {
        try {
            $this->apply($attempt, $this->gateway->disburse($this->request($refund, $attempt)));
        } catch (GatewayUnavailable) {
            if ($resend) {
                $this->touch($attempt); // the first send may still surface: keep looking

                return;
            }
            $this->apply($attempt, new GatewayResult($attempt->reference, '', GatewayStatus::Failed, failureCode: 'gateway_unavailable'));
        } catch (Throwable $e) {
            // Unknown outcome (timeout, driver error): stays in flight; sync looks it up and re-sends safely.
            $this->log->warning('Refund payout outcome unknown', ['attempt_id' => $attempt->id, 'exception' => $e::class]);
            $this->touch($attempt);
        }
    }

    /** Same reference again (the gateway creates the transfer only once); after a day, given up. */
    private function unknownAtGateway(RefundAttempt $attempt): void
    {
        if ($attempt->created_at->lt(now()->subHours(self::GIVE_UP_HOURS))) {
            $this->apply($attempt, new GatewayResult($attempt->reference, '', GatewayStatus::Failed, failureCode: 'not_found_at_gateway'), giveUp: true);

            return;
        }

        $refund = Refund::query()->with('payment', 'order')->findOrFail($attempt->refund_id);
        $this->send($refund, $attempt, resend: true);
    }

    private function apply(RefundAttempt $attempt, GatewayResult $result, bool $giveUp = false): void
    {
        $this->lockedAttempt($attempt, function (RefundAttempt $attempt, Refund $refund, Payment $payment) use ($result, $giveUp): void {
            $attempt->forceFill(['checks' => $attempt->checks + 1, 'last_checked_at' => now()]);

            if ($result->reference !== $attempt->reference) {
                $this->log->warning('Refund payout result for another reference ignored', ['attempt_id' => $attempt->id, 'reference' => $result->reference]);
                $attempt->save();

                return;
            }

            $attempt->forceFill([
                'gateway_reference' => $attempt->gateway_reference ?? ($result->gatewayReference !== '' ? $result->gatewayReference : null),
                'operator_reference' => $attempt->operator_reference ?? $result->operatorReference,
            ]);

            match (true) {
                $result->status === GatewayStatus::Succeeded && in_array($attempt->status, [AttemptStatus::Processing, AttemptStatus::Failed], true) => $this->succeeded($attempt, $refund, $payment, $result),
                $result->status === GatewayStatus::Reversed && $attempt->status === AttemptStatus::Succeeded => $this->reversed($attempt, $refund, $payment),
                in_array($result->status, [GatewayStatus::Failed, GatewayStatus::Reversed], true) && $attempt->status === AttemptStatus::Processing => $this->failed($attempt, $refund, $payment, $result, $giveUp),
                default => null, // pending, or nothing new
            };

            $attempt->save();
            $refund->save();
        });
    }

    private function succeeded(RefundAttempt $attempt, Refund $refund, Payment $payment, GatewayResult $result): void
    {
        $confirmed = $result->amount ?? $refund->amount();
        $attempt->forceFill(['status' => AttemptStatus::Succeeded, 'finished_at' => $result->completedAt ?? now(), 'confirmed_amount_minor' => $confirmed->amountMinor]);

        if ($confirmed->currency !== $refund->amount()->currency) {
            $refund->flag = 'amount_mismatch'; // nothing posted in a foreign currency: staff settle it
            $this->log->critical('Refund paid out in another currency', ['attempt_id' => $attempt->id]);

            return;
        }

        $fee = $result->fee !== null && $result->fee->currency === $confirmed->currency ? $result->fee : null;
        if ($result->fee !== null && $fee === null) {
            $this->log->critical('Refund payout fee in another currency ignored', ['attempt_id' => $attempt->id]);
        }
        $attempt->fee_minor = $fee?->amountMinor;

        // The first success pays the refund; any other means the payer now has the money twice.
        if (in_array($refund->status, [RefundStatus::Approved, RefundStatus::Processing, RefundStatus::Failed], true)) {
            $attempt->outcome = RefundAttempt::OUTCOME_COMPLETED;
            $this->postings->paid($attempt, $confirmed, $fee);
            $refund->forceFill(['status' => RefundStatus::Completed, 'completed_at' => $attempt->finished_at, 'failure_code' => null]);
            if (! $confirmed->equals($refund->amount())) {
                $refund->flag = 'amount_mismatch'; // the difference stays visible in refunds_payable
                $this->log->critical('Refund paid out with a different amount', ['attempt_id' => $attempt->id, 'confirmed_minor' => $confirmed->amountMinor]);
            }
            if ($payment->status === PaymentStatus::Reversed) {
                $refund->flag = 'paid_after_reversal'; // the reversal recorded the payer's debt
            }

            return;
        }

        $attempt->outcome = RefundAttempt::OUTCOME_OVERPAID;
        $this->postings->overpaid($attempt, $confirmed, $fee);
        $refund->flag = 'paid_twice';
        $this->log->critical('Refund paid out when nothing was owed any more', ['attempt_id' => $attempt->id, 'refund_status' => $refund->status->value]);
    }

    private function failed(RefundAttempt $attempt, Refund $refund, Payment $payment, GatewayResult $result, bool $giveUp): void
    {
        $attempt->forceFill([
            'status' => AttemptStatus::Failed,
            'finished_at' => now(),
            'failure_code' => $result->failureCode ?? 'failed',
            'failure_message' => $result->failureMessage === null ? null : mb_substr($result->failureMessage, 0, 255),
        ]);

        if ($refund->status !== RefundStatus::Processing || $attempt->number !== $refund->attempts) {
            return; // an older attempt: the refund follows its latest one
        }

        if ($payment->status === PaymentStatus::Reversed) {
            // The operator already gave the payer their money back: the debt and what we owed cancel out.
            $refund->forceFill(['status' => RefundStatus::Cancelled, 'decision_note' => 'payment_reversed']);
            $this->postings->settled($refund);

            return;
        }

        $refund->forceFill(['status' => RefundStatus::Failed, 'failure_code' => $attempt->failure_code]);
        if ($giveUp) {
            $refund->flag = 'stuck';
            $this->log->critical('Refund payout never reached the gateway', ['attempt_id' => $attempt->id]);
        }
    }

    private function reversed(RefundAttempt $attempt, Refund $refund, Payment $payment): void
    {
        $this->postings->attemptReversed($attempt);
        $attempt->status = AttemptStatus::Reversed;
        $this->log->critical('Refund payout reversed by the operator', ['attempt_id' => $attempt->id]);

        if ($attempt->outcome !== RefundAttempt::OUTCOME_COMPLETED || $refund->status !== RefundStatus::Completed) {
            return; // an overpayment came back: nothing more owed either way
        }

        if ($payment->status === PaymentStatus::Reversed) {
            $refund->forceFill(['status' => RefundStatus::Cancelled, 'decision_note' => 'payment_reversed']);
            $this->postings->settled($refund);

            return;
        }

        $refund->forceFill(['status' => RefundStatus::Failed, 'failure_code' => 'payout_reversed', 'completed_at' => null]);
        $refund->flag = $refund->flag === 'paid_twice' ? 'paid_twice' : 'payout_reversed'; // never hide a double payment
    }

    private function touch(RefundAttempt $attempt): void
    {
        $this->lockedAttempt($attempt, fn (RefundAttempt $a) => $a->forceFill(['checks' => $a->checks + 1, 'last_checked_at' => now()])->save());
    }

    private function request(Refund $refund, RefundAttempt $attempt): DisbursementRequest
    {
        $payment = $refund->payment;

        return new DisbursementRequest(
            reference: $attempt->reference,
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

    /** Every course this order sold has a refund approved or further. */
    private function allSoldItemsRefunded(Order $order): bool
    {
        $sold = $order->items->filter(fn (OrderItem $i) => $i->price_minor > 0 && $i->granted_at !== null)->pluck('id');
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

    /**
     * Locks order → payment → refund → attempt, then applies.
     *
     * @param  callable(RefundAttempt, Refund, Payment): mixed  $apply
     */
    private function lockedAttempt(RefundAttempt $attempt, callable $apply): void
    {
        $refund = Refund::query()->findOrFail($attempt->refund_id);

        $this->locked($refund, function (Refund $refund, Order $order, Payment $payment) use ($attempt, $apply): void {
            $locked = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $apply($locked, $refund, $payment);
        });
    }
}
