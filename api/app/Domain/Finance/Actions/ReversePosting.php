<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\EntryDirection;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Mirrors a posting entry by entry (e.g. sale → sale_reversal when the operator reverses a payment,
 * PRD-05 BR-04). Idempotent per reference; does nothing if the original was never posted.
 *
 * TODO(1.10): once held earnings are released, a sale reversal must take the professor part from
 * available (may go negative, PRD-08 FR-05). TODO(aggregator): if the aggregator keeps its fee on a
 * reversal, post the fee legs from the reversal result instead of mirroring them.
 */
final class ReversePosting
{
    public function __construct(private readonly PostLedgerTransaction $post) {}

    public function handle(LedgerTransactionType $original, LedgerTransactionType $reversal, string $referenceType, string $referenceId, string $memo): ?LedgerTransaction
    {
        $posted = LedgerTransaction::query()
            ->where('type', $original)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->with('entries.account')
            ->first();

        if ($posted === null) {
            return null;
        }

        $lines = $posted->entries->map(function (LedgerEntry $entry): EntryLine {
            $amount = Money::of($entry->amount_minor, Currency::from($entry->currency));

            return $entry->direction === EntryDirection::Debit
                ? EntryLine::credit($entry->account->type, $amount, $entry->account->owner_id)
                : EntryLine::debit($entry->account->type, $amount, $entry->account->owner_id);
        })->values()->all();

        return $this->post->handle($reversal, $referenceType, $referenceId, $lines, memo: $memo);
    }
}
