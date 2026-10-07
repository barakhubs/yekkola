<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\Enums\Currency;

final class CurrencyMismatch extends DomainException
{
    public static function between(Currency $a, Currency $b): self
    {
        return new self(
            errorCode: 'money.currency_mismatch',
            status: 422,
            context: ['currencies' => [$a->value, $b->value]],
            message: "Cannot combine {$a->value} with {$b->value}.",
        );
    }
}
