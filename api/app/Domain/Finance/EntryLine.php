<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Finance\Enums\EntryDirection;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Shared\ValueObjects\Money;
use LogicException;

/**
 * One line of a posting: account (type + owner) and a positive amount on one side.
 */
final readonly class EntryLine
{
    private function __construct(
        public LedgerAccountType $account,
        public ?string $ownerId,
        public EntryDirection $direction,
        public Money $amount,
    ) {
        if ($account->ownedByProfessor() === ($ownerId === null)) {
            throw new LogicException("Account {$account->value} ".($ownerId === null ? 'needs' : 'takes no').' owner.');
        }
    }

    public static function debit(LedgerAccountType $account, Money $amount, ?string $ownerId = null): self
    {
        return new self($account, $ownerId, EntryDirection::Debit, $amount);
    }

    public static function credit(LedgerAccountType $account, Money $amount, ?string $ownerId = null): self
    {
        return new self($account, $ownerId, EntryDirection::Credit, $amount);
    }
}
