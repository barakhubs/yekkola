<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/** Access checks look only at active enrollments. */
enum EnrollmentStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
