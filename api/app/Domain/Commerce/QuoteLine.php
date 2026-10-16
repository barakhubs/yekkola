<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Authoring\Models\Course;
use App\Domain\Shared\ValueObjects\Money;

final readonly class QuoteLine
{
    public function __construct(
        public Course $course,
        public Money $listPrice,
        public Money $discount,
        /** Professor share in basis points, snapshotted on the order item. */
        public int $revenueShareBps,
    ) {}

    public function price(): Money
    {
        return $this->listPrice->minus($this->discount);
    }
}
