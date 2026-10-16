<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Shared\ValueObjects\Money;

final readonly class QuoteLine
{
    public function __construct(
        public Course $course,
        public Money $listPrice,
        public Money $discount,
        /** Professor share in basis points, snapshotted on the order item. */
        public int $revenueShareBps,
        public ?DiscountFunder $funder = null,
        public ?string $bundleId = null,
    ) {}

    public function price(): Money
    {
        return $this->listPrice->minus($this->discount);
    }

    public function withDiscount(Money $discount, DiscountFunder $funder): self
    {
        return new self($this->course, $this->listPrice, $discount, $this->revenueShareBps, $discount->isZero() ? null : $funder, $this->bundleId);
    }
}
