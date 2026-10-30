<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;

/**
 * Where a sold item's professor share sits (PRD-08 FR-03–FR-05): held until the earnings hold period ends, then
 * available. Each item is released by its own `release` transaction, so the ledger itself says which.
 */
final class EarningsRelease
{
    public const REFERENCE = 'order_item';

    public static function isReleased(OrderItem $item): bool
    {
        return LedgerTransaction::query()
            ->where('type', LedgerTransactionType::Release)
            ->where('reference_type', self::REFERENCE)
            ->where('reference_id', $item->id)
            ->exists();
    }

    /**
     * The account a refund or reversal takes the professor share back from: available once released (it may go
     * negative there and is netted against later earnings, FR-05), held before. Callers hold the order lock, as
     * the release does, so the answer can't change under them.
     */
    public static function professorAccount(OrderItem $item): LedgerAccountType
    {
        return self::isReleased($item) ? LedgerAccountType::ProfessorAvailable : LedgerAccountType::ProfessorHeld;
    }
}
