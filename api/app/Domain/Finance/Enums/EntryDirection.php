<?php

declare(strict_types=1);

namespace App\Domain\Finance\Enums;

/** Side of a ledger entry. */
enum EntryDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';
}
