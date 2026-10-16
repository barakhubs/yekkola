<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/** What a coupon applies to (PRD-05 FR-11). */
enum CouponScope: string
{
    case Platform = 'platform';
    case Professor = 'professor';
    case Course = 'course';
}
