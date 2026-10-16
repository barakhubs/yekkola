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
}
