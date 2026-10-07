<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Shared\ValueObjects\Money;

final readonly class AmountLimits
{
    public function __construct(
        public Money $min,
        public Money $max,
    ) {}

    public function allows(Money $amount): bool
    {
        return $amount->greaterThanOrEqual($this->min) && $this->max->greaterThanOrEqual($amount);
    }
}
