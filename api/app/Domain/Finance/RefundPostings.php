<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Actions\PostLedgerTransaction;
use App\Domain\Finance\Actions\ReversePosting;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Refund ledger postings (PRD-08 FR-04), each at most once per refund:
 * - approved: the course's sale is taken back — professor and platform parts (and the platform-funded
 *   discount) from the item snapshot — into `refunds_payable`, owed to the payer;
 * - paid out: `refunds_payable` → gateway clearing for the amount the gateway confirmed, plus any
 *   disbursement fee (borne by the platform for refunds);
 * - called off (payment reversed first): the approval posting mirrored;
 * - settled: a payout that failed after the operator already gave the payer their money back;
 * - overpaid: an earlier attempt also went through — the payer owes it back (`payer_receivable`);
 * - payout reversed by the operator: the paid-out posting mirrored.
 * Unapplied money is already in `refunds_payable`, so only its payout is posted.
 *
 * TODO(1.10): once held earnings are released, take the professor part from available (may go negative).
 */
final class RefundPostings
{
    public const REFERENCE = 'refund';

    public function __construct(
        private readonly PostLedgerTransaction $post,
        private readonly ReversePosting $reverse,
    ) {}

    public function approved(Refund $refund, OrderItem $item): ?LedgerTransaction
    {
        [$professor, $platform, $platformDiscount] = SaleSplit::of($item);

        return $this->post->handle(LedgerTransactionType::Refund, self::REFERENCE, $refund->id, [
            EntryLine::debit(LedgerAccountType::ProfessorHeld, $professor, $item->professor_profile_id),
            EntryLine::debit(LedgerAccountType::PlatformRevenue, $platform),
            EntryLine::credit(LedgerAccountType::DiscountsPlatform, $platformDiscount),
            EntryLine::credit(LedgerAccountType::RefundsPayable, $item->price()),
        ], memo: "Refund {$refund->id}");
    }

    public function paid(Refund $refund, Money $confirmed, ?Money $fee): ?LedgerTransaction
    {
        $lines = [
            EntryLine::debit(LedgerAccountType::RefundsPayable, $confirmed),
            EntryLine::credit(LedgerAccountType::GatewayClearing, $confirmed),
        ];

        if ($fee !== null && $fee->isPositive()) {
            $lines[] = EntryLine::debit(LedgerAccountType::GatewayFees, $fee);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $fee);
        }

        return $this->post->handle(LedgerTransactionType::RefundPaid, self::REFERENCE, $refund->id, $lines, memo: "Refund {$refund->id} paid out");
    }

    public function cancelled(Refund $refund): ?LedgerTransaction
    {
        return $this->reverse->handle(LedgerTransactionType::Refund, LedgerTransactionType::RefundReversal, self::REFERENCE, $refund->id, "Refund {$refund->id} called off");
    }

    public function settled(Refund $refund): ?LedgerTransaction
    {
        return $this->post->handle(LedgerTransactionType::RefundSettled, self::REFERENCE, $refund->id, [
            EntryLine::debit(LedgerAccountType::RefundsPayable, $refund->amount()),
            EntryLine::credit(LedgerAccountType::PayerReceivable, $refund->amount()),
        ], memo: "Refund {$refund->id}: payer already repaid by the reversal");
    }

    public function overpaid(Refund $refund, Money $amount): ?LedgerTransaction
    {
        return $this->post->handle(LedgerTransactionType::RefundOverpaid, self::REFERENCE, $refund->id, [
            EntryLine::debit(LedgerAccountType::PayerReceivable, $amount),
            EntryLine::credit(LedgerAccountType::GatewayClearing, $amount),
        ], memo: "Refund {$refund->id}: paid out twice");
    }

    public function payoutReversed(Refund $refund): ?LedgerTransaction
    {
        return $this->reverse->handle(LedgerTransactionType::RefundPaid, LedgerTransactionType::RefundPaidReversal, self::REFERENCE, $refund->id, "Refund {$refund->id} payout reversed");
    }
}
