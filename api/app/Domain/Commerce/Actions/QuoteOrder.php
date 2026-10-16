<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Quote;
use App\Domain\Commerce\QuoteLine;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Server-side price of a purchase (PRD-05 FR-02, FR-04): listed paid courses only, one currency, courses
 * the beneficiary already has are left out. Each line carries the revenue-share snapshot (course override,
 * then professor override, then platform default). Client totals are never trusted.
 */
final class QuoteOrder
{
    public function __construct(private readonly CommerceSettings $settings) {}

    /**
     * @param  list<string>  $courseIds
     */
    public function handle(User $beneficiary, array $courseIds): Quote
    {
        $courses = Course::query()->listed()->whereIn('id', $courseIds)->with('professor', 'liveVersion')->get()->keyBy('id');

        if ($courses->count() !== count(array_unique($courseIds))) {
            throw CommerceException::courseUnavailable();
        }

        if ($courses->contains(fn (Course $c) => $c->is_free)) {
            throw CommerceException::freeCourse();
        }

        $currencies = $courses->map(fn (Course $c) => (string) $c->currency)->unique();
        if ($currencies->count() !== 1 || ! in_array($currencies->first(), $this->settings->enabled_currencies, true)) {
            throw CommerceException::mixedCurrency();
        }

        $owned = Enrollment::query()->active()->where('user_id', $beneficiary->id)->whereIn('course_id', $courses->keys())->pluck('course_id')->all();
        $currency = Currency::from((string) $currencies->first());

        $lines = [];
        foreach (array_unique($courseIds) as $id) {
            $course = $courses[$id];
            if (in_array($course->id, $owned, true)) {
                continue;
            }
            $price = $course->price() ?? throw CommerceException::courseUnavailable();
            $lines[] = new QuoteLine(
                course: $course,
                listPrice: $price,
                discount: Money::zero($currency),
                revenueShareBps: $course->revenue_share_bps ?? $course->professor->revenue_share_bps ?? $this->settings->default_revenue_share_bps,
            );
        }

        if ($lines === []) {
            throw CommerceException::nothingToBuy();
        }

        return new Quote($currency, $lines, excludedCourseIds: array_values($owned));
    }
}
