<?php

declare(strict_types=1);

namespace App\Domain\Identity\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case ChangePhone = 'change_phone';
    /** Confirms a payout-method change; the code goes to the account's own number (PRD-02 FR-09). */
    case PayoutMethod = 'payout_method';
}
