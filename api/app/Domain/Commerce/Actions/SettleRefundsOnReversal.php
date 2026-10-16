<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\RefundKind;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\RefundPostings;
use Psr\Log\LoggerInterface;

/**
 * When the operator reverses a payment, the payer already has all their money back: refunds on it whose
 * money has not gone out are called off — requests rejected, approved or failed ones cancelled (their
 * posting mirrored; a failed attempt that still succeeds later is then recorded as an overpayment).
 * Refunds being or already paid out mean the payer was paid twice: flagged for staff, and the debt recorded
 * — for courses by the order reversal (PostOrderReversal), for unapplied money here.
 * Call inside the payment state machine's transaction, with the order and payment locked.
 */
final class SettleRefundsOnReversal
{
    public function __construct(
        private readonly RefundPostings $postings,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @return list<string> Order items whose refund money is out (or may be).
     */
    public function handle(Payment $payment): array
    {
        $paidOut = [];

        foreach (Refund::query()->where('payment_id', $payment->id)->orderBy('id')->lockForUpdate()->get() as $refund) {
            match ($refund->status) {
                RefundStatus::Requested => $refund->forceFill([
                    'status' => RefundStatus::Rejected,
                    'decided_at' => now(),
                    'decision_note' => 'payment_reversed',
                ])->save(),
                RefundStatus::Approved, RefundStatus::Failed => $this->cancel($refund),
                RefundStatus::Processing, RefundStatus::Completed => $paidOut[] = $this->paidTwice($refund),
                default => null,
            };
        }

        return array_values(array_filter($paidOut));
    }

    private function cancel(Refund $refund): void
    {
        $refund->forceFill(['status' => RefundStatus::Cancelled, 'decision_note' => 'payment_reversed'])->save();

        if ($refund->kind === RefundKind::Item) {
            $this->postings->cancelled($refund);
        }
    }

    private function paidTwice(Refund $refund): ?string
    {
        $refund->forceFill(['flag' => 'paid_after_reversal'])->save();
        $this->log->critical('Payment reversed after its refund was paid out (or is being paid)', ['refund_id' => $refund->id, 'payment_id' => $refund->payment_id]);

        if ($refund->kind === RefundKind::Unapplied) {
            $this->postings->debt($refund); // the unapplied reversal emptied refunds_payable: the payout is now a debt

            return null;
        }

        return $refund->order_item_id;
    }
}
