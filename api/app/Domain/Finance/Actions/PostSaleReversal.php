<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\EntryDirection;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Reverses an order's sale posting entry by entry when the operator reverses the payment (PRD-05 BR-04).
 * Idempotent per order; does nothing if the sale was never posted.
 *
 * TODO(1.10): once held earnings are released, take the professor part from available (may go negative,
 * PRD-08 FR-05) instead of held.
 */
final class PostSaleReversal
{
    public function __construct(private readonly PostLedgerTransaction $post) {}

    public function handle(Order $order): ?LedgerTransaction
    {
        $sale = LedgerTransaction::query()
            ->where('type', LedgerTransactionType::Sale)
            ->where('reference_type', PostSale::REFERENCE)
            ->where('reference_id', $order->id)
            ->with('entries.account')
            ->first();

        if ($sale === null) {
            return null;
        }

        $lines = $sale->entries->map(function (LedgerEntry $entry): EntryLine {
            $amount = Money::of($entry->amount_minor, Currency::from($entry->currency));

            return $entry->direction === EntryDirection::Debit
                ? EntryLine::credit($entry->account->type, $amount, $entry->account->owner_id)
                : EntryLine::debit($entry->account->type, $amount, $entry->account->owner_id);
        })->values()->all();

        return $this->post->handle(LedgerTransactionType::SaleReversal, PostSale::REFERENCE, $order->id, $lines, memo: "Reversal of order {$order->number}");
    }
}
