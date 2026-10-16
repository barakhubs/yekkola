<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Commerce\Models\Bundle;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use LogicException;

/**
 * A priced purchase (PRD-05 FR-02): lines in one currency, totals derived from them.
 */
final readonly class Quote
{
    /**
     * @param  list<QuoteLine>  $lines
     * @param  list<string>  $excludedCourseIds  Already owned by the beneficiary.
     */
    public function __construct(
        public Currency $currency,
        public array $lines,
        public array $excludedCourseIds = [],
        public ?Coupon $coupon = null,
        public ?Bundle $bundle = null,
    ) {
        if ($this->total()->amountMinor % $currency->collectionStepMinor() !== 0) {
            throw new LogicException('Quote total must be a multiple of the currency collection step.');
        }
    }

    public function subtotal(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, QuoteLine $l) => $sum->plus($l->listPrice), Money::zero($this->currency));
    }

    public function discount(): Money
    {
        return array_reduce($this->lines, fn (Money $sum, QuoteLine $l) => $sum->plus($l->discount), Money::zero($this->currency));
    }

    public function isFree(): bool
    {
        return $this->total()->isZero();
    }

    public function total(): Money
    {
        return $this->subtotal()->minus($this->discount());
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'currency' => $this->currency->value,
            'items' => array_map(fn (QuoteLine $l) => [
                'course_id' => $l->course->id,
                'title' => $l->course->liveVersion?->title,
                'list_price' => $l->listPrice->toArray(),
                'discount' => $l->discount->toArray(),
                'price' => $l->price()->toArray(),
            ], $this->lines),
            'is_free' => $this->total()->isZero(),
            'excluded_course_ids' => $this->excludedCourseIds,
            'coupon' => $this->coupon === null ? null : ['code' => $this->coupon->code],
            'bundle' => $this->bundle === null ? null : ['id' => $this->bundle->id, 'slug' => $this->bundle->slug, 'title' => $this->bundle->title],
            'subtotal' => $this->subtotal()->toArray(),
            'discount' => $this->discount()->toArray(),
            'total' => $this->total()->toArray(),
        ];
    }
}
