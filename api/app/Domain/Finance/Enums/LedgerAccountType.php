<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** Ledger account types (architecture §3.7). */
enum LedgerAccountType: string
{
    case GatewayClearing = 'gateway_clearing';
    case GatewayFees = 'gateway_fees';
    case PlatformRevenue = 'platform_revenue';
    case ProfessorHeld = 'professor_held';
    case ProfessorAvailable = 'professor_available';
    case ProfessorPaidOut = 'professor_paid_out';
    case RefundsPayable = 'refunds_payable';
    case DiscountsPlatform = 'discounts_platform';
    /** Money paid out twice to a payer (a refund plus an operator reversal, two payout attempts): they owe it back. */
    case PayerReceivable = 'payer_receivable';

    public function ownedByProfessor(): bool
    {
        return in_array($this, [self::ProfessorHeld, self::ProfessorAvailable, self::ProfessorPaidOut], true);
    }
}
