<?php

declare(strict_types=1);

namespace App\Integrations\Payments;

use App\Domain\Shared\ValueObjects\Money;
use InvalidArgumentException;

/**
 * Invariants for anything sent to a gateway. Violations are programming errors.
 */
final class TransferGuard
{
    public const MAX_REFERENCE_LENGTH = 64;

    public const MAX_DESCRIPTION_LENGTH = 100;

    public static function assertValid(string $reference, Money $amount, string $description): void
    {
        if ($reference === '' || strlen($reference) > self::MAX_REFERENCE_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $reference) !== 1) {
            throw new InvalidArgumentException('Reference must be 1–64 characters of letters, digits, "-" or "_".');
        }

        if (! $amount->isPositive()) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        if ($amount->amountMinor % $amount->currency->collectionStepMinor() !== 0) {
            throw new InvalidArgumentException("Amount must be a multiple of {$amount->currency->collectionStepMinor()} minor units for {$amount->currency->value}.");
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException('Description must be at most 100 characters.');
        }
    }
}
