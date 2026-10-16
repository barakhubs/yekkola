<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Commerce\Models\Payment;
use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Money a gateway really collected that we can't apply to an order — a confirmed amount that differs, or a
 * second success on an order that is already paid. It is recorded as owed back to the payer
 * (`refunds_payable`) so clearing always matches what the gateway holds; the refund flow (1.7c) or staff
 * settle it. Idempotent per payment.
 */
final class PostUnappliedReceipt
{
    public const REFERENCE = 'payment';

    public function __construct(private readonly PostLedgerTransaction $post) {}

    public function handle(Payment $payment, Money $collected, string $reason): LedgerTransaction
    {
        return $this->post->handle(
            LedgerTransactionType::UnappliedReceipt,
            self::REFERENCE,
            $payment->id,
            [
                EntryLine::debit(LedgerAccountType::GatewayClearing, $collected),
                EntryLine::credit(LedgerAccountType::RefundsPayable, $collected),
            ],
            memo: "Unapplied payment {$payment->id}: {$reason}",
        );
    }
}
