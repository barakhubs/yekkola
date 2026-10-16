<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\Actions\PostLedgerTransaction;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Refund ledger postings (PRD-08 FR-04), one of each per refund:
 * - approved: the course's sale is taken back — professor and platform parts (and the platform-funded
 *   discount) from the item snapshot — into `refunds_payable`, owed to the payer;
 * - paid out: `refunds_payable` → gateway clearing (money left), plus any disbursement fee (borne by the
 *   platform, provisional setting).
 * Unapplied money is already in `refunds_payable`, so only its payout is posted.
 *
 * TODO(1.10): once held earnings are released, take the professor part from available (may go negative).
 */
final class RefundPostings
{
    public const REFERENCE = 'refund';

    public function __construct(private readonly PostLedgerTransaction $post) {}

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

    public function paid(Refund $refund): ?LedgerTransaction
    {
        $lines = [
            EntryLine::debit(LedgerAccountType::RefundsPayable, $refund->amount()),
            EntryLine::credit(LedgerAccountType::GatewayClearing, $refund->amount()),
        ];

        if ($refund->fee_minor !== null && $refund->fee_minor > 0) {
            $fee = Money::of($refund->fee_minor, $refund->amount()->currency);
            $lines[] = EntryLine::debit(LedgerAccountType::GatewayFees, $fee);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $fee);
        }

        return $this->post->handle(LedgerTransactionType::RefundPaid, self::REFERENCE, $refund->id, $lines, memo: "Refund {$refund->id} paid out");
    }
}
