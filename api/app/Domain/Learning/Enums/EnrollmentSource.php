<?php

declare(strict_types=1);

namespace App\Domain\Learning\Enums;

/** How a student got access (PRD-06). */
enum EnrollmentSource: string
{
    case Purchase = 'purchase';
    case Free = 'free';
    case Coupon = 'coupon';
    case Bundle = 'bundle';
    case Admin = 'admin';
}
