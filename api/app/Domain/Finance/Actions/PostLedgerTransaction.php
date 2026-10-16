<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\EntryDirection;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerAccount;
use App\Domain\Finance\Models\LedgerTransaction;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * The only way money is written (PRD-08 FR-01, BR-02): one balanced, single-currency transaction per
 * (type, reference). Posting the same type for the same reference again returns the existing transaction,
 * so replayed handlers never double-post. Zero lines are dropped; a posting with nothing left is skipped
 * (returns null) — e.g. a sale of courses discounted to zero.
 */
final class PostLedgerTransaction
{
    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @param  list<EntryLine>  $lines
     */
    public function handle(
        LedgerTransactionType $type,
        string $referenceType,
        string $referenceId,
        array $lines,
        ?string $memo = null,
        ?string $createdBy = null,
    ): ?LedgerTransaction {
        $lines = array_values(array_filter($lines, fn (EntryLine $l) => ! $l->amount->isZero()));
        if ($lines === []) {
            return null;
        }
        $this->assertBalanced($lines);

        return $this->db->transaction(function () use ($type, $referenceType, $referenceId, $lines, $memo, $createdBy): LedgerTransaction {
            $transaction = LedgerTransaction::query()->createOrFirst(
                ['type' => $type, 'reference_type' => $referenceType, 'reference_id' => $referenceId],
                ['memo' => $memo, 'created_by' => $createdBy, 'created_at' => now()],
            );

            if (! $transaction->wasRecentlyCreated) {
                return $transaction; // already posted
            }

            // Accounts in a fixed order, so concurrent first postings can't deadlock on account creation.
            usort($lines, fn (EntryLine $a, EntryLine $b) => [$a->account->value, (string) $a->ownerId] <=> [$b->account->value, (string) $b->ownerId]);

            foreach ($lines as $line) {
                $transaction->entries()->create([
                    'ledger_account_id' => $this->account($line)->id,
                    'direction' => $line->direction,
                    'amount_minor' => $line->amount->amountMinor,
                    'currency' => $line->amount->currency->value,
                    'created_at' => now(),
                ]);
            }

            return $transaction;
        });
    }

    /**
     * @param  list<EntryLine>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        $currency = $lines[0]->amount->currency;
        $balance = 0;

        foreach ($lines as $line) {
            if ($line->amount->currency !== $currency) {
                throw new LogicException('A ledger transaction must use a single currency.');
            }
            if ($line->amount->isNegative()) {
                throw new LogicException('Ledger entry amounts are positive; the direction gives the sign.');
            }
            $balance += $line->direction === EntryDirection::Debit ? $line->amount->amountMinor : -$line->amount->amountMinor;
        }

        if ($balance !== 0) {
            throw new LogicException("Unbalanced ledger transaction (debits − credits = {$balance}).");
        }
    }

    private function account(EntryLine $line): LedgerAccount
    {
        return LedgerAccount::query()->createOrFirst([
            'type' => $line->account,
            'owner_type' => $line->ownerId === null ? LedgerAccount::OWNER_PLATFORM : LedgerAccount::OWNER_PROFESSOR,
            'owner_id' => $line->ownerId,
            'currency' => $line->amount->currency->value,
        ]);
    }
}
