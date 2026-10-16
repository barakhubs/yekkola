<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/** Why an order or payment needs staff attention (PRD-05 FR-10, BR-04). */
enum OrderFlag: string
{
    case LateSuccess = 'late_success';
    case AmountMismatch = 'amount_mismatch';
    case Reversed = 'reversed';
    case DuplicatePayment = 'duplicate_payment';
    case AlreadyEnrolled = 'already_enrolled';

    /** Keeps the more serious of two flags, so a later event never hides an earlier problem. */
    public static function raise(?self $current, self $new): self
    {
        return $current !== null && $current->severity() >= $new->severity() ? $current : $new;
    }

    private function severity(): int
    {
        return match ($this) {
            self::AmountMismatch => 5,
            self::DuplicatePayment => 4,
            self::AlreadyEnrolled => 3,
            self::Reversed => 2,
            self::LateSuccess => 1,
        };
    }
}
