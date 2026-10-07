<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\Enums\Currency;
use LogicException;

/**
 * Mixing currencies is a programming error (orders are single-currency), so it surfaces as a 500 and is reported.
 */
final class CurrencyMismatch extends LogicException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self("Cannot combine {$a->value} with {$b->value}.");
    }
}
