<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Enums;

/**
 * Who pays for a discount (PRD-05 FR-11, PRD-08 FR-02). Provisional definitions (TODO 0.5):
 * - platform:  the professor earns their share of the list price; the platform's discounts account pays.
 * - professor: the platform keeps its share of the list price; the professor gets the rest (never below 0).
 * - shared:    both shares apply to the discounted price (bundles use this).
 */
enum DiscountFunder: string
{
    case Platform = 'platform';
    case Professor = 'professor';
    case Shared = 'shared';
}
