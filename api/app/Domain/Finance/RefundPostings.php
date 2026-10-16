<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Commerce\Models\RefundAttempt;
use App\Domain\Finance\Actions\PostLedgerTransaction;
use App\Domain\Finance\Actions\ReversePosting;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Refund ledger postings (PRD-08 FR-04). Per refund, at most once each:
 * - approved: the course's sale taken back (snapshot split) into `refunds_payable`, owed to the payer;
 * - cancelled: the approval mirrored (payment reversed before any money went out);
 * - debt: the operator repaid the payer while our payout was out or in flight → `payer_receivable`;
 * - settled: that payout then failed or came back — the debt is cleared against what we owed.
 * Per payout attempt, at most once each:
 * - paid: `refunds_payable` → gateway clearing for the confirmed amount (+ fee, borne by the platform);
 * - overpaid: an attempt that went through when nothing was owed any more → `payer_receivable`;
 * - reversed: the operator gave a payout back — that attempt's posting mirrored.
 * Unapplied money is already in `refunds_payable`, so only its payout is posted.
 *
 * TODO(1.10): once held earnings are released, take the professor part from available (may go negative).
 */
final class RefundPostings
{
    public const REFUND = 'refund';

    public const ATTEMPT = 'refund_attempt';

    public function __construct(
        private readonly PostLedgerTransaction $post,
        private readonly ReversePosting $reverse,
    ) {}

    public function approved(Refund $refund, OrderItem $item): ?LedgerTransaction
    {
        [$professor, $platform, $platformDiscount] = SaleSplit::of($item);

        return $this->post->handle(LedgerTransactionType::Refund, self::REFUND, $refund->id, [
            EntryLine::debit(LedgerAccountType::ProfessorHeld, $professor, $item->professor_profile_id),
            EntryLine::debit(LedgerAccountType::PlatformRevenue, $platform),
            EntryLine::credit(LedgerAccountType::DiscountsPlatform, $platformDiscount),
            EntryLine::credit(LedgerAccountType::RefundsPayable, $item->price()),
        ], memo: "Refund {$refund->id}");
    }

    public function cancelled(Refund $refund): ?LedgerTransaction
    {
        return $this->reverse->handle(LedgerTransactionType::Refund, LedgerTransactionType::RefundReversal, self::REFUND, $refund->id, "Refund {$refund->id} called off");
    }

    public function debt(Refund $refund): ?LedgerTransaction
    {
        return $this->post->handle(LedgerTransactionType::RefundDebt, self::REFUND, $refund->id, [
            EntryLine::debit(LedgerAccountType::PayerReceivable, $refund->amount()),
            EntryLine::credit(LedgerAccountType::RefundsPayable, $refund->amount()),
        ], memo: "Refund {$refund->id}: payer also repaid by the operator");
    }

    public function settled(Refund $refund): ?LedgerTransaction
    {
        return $this->post->handle(LedgerTransactionType::RefundSettled, self::REFUND, $refund->id, [
            EntryLine::debit(LedgerAccountType::RefundsPayable, $refund->amount()),
            EntryLine::credit(LedgerAccountType::PayerReceivable, $refund->amount()),
        ], memo: "Refund {$refund->id}: payer already repaid by the operator");
    }

    public function paid(RefundAttempt $attempt, Money $confirmed, ?Money $fee): ?LedgerTransaction
    {
        return $this->payout(LedgerTransactionType::RefundPaid, LedgerAccountType::RefundsPayable, $attempt, $confirmed, $fee);
    }

    public function overpaid(RefundAttempt $attempt, Money $confirmed, ?Money $fee): ?LedgerTransaction
    {
        return $this->payout(LedgerTransactionType::RefundOverpaid, LedgerAccountType::PayerReceivable, $attempt, $confirmed, $fee);
    }

    public function attemptReversed(RefundAttempt $attempt): ?LedgerTransaction
    {
        $type = $attempt->outcome === RefundAttempt::OUTCOME_OVERPAID ? LedgerTransactionType::RefundOverpaid : LedgerTransactionType::RefundPaid;

        return $this->reverse->handle($type, LedgerTransactionType::RefundPaidReversal, self::ATTEMPT, $attempt->id, "Payout {$attempt->reference} reversed");
    }

    private function payout(LedgerTransactionType $type, LedgerAccountType $from, RefundAttempt $attempt, Money $confirmed, ?Money $fee): ?LedgerTransaction
    {
        $lines = [
            EntryLine::debit($from, $confirmed),
            EntryLine::credit(LedgerAccountType::GatewayClearing, $confirmed),
        ];

        if ($fee !== null && $fee->isPositive()) {
            $lines[] = EntryLine::debit(LedgerAccountType::GatewayFees, $fee);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $fee);
        }

        return $this->post->handle($type, self::ATTEMPT, $attempt->id, $lines, memo: "Payout {$attempt->reference}");
    }
}
