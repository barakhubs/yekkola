<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Authoring\Models\Course;
use App\Domain\Commerce\CouponRules;
use App\Domain\Commerce\Discounts;
use App\Domain\Commerce\Enums\BundleStatus;
use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Models\Bundle;
use App\Domain\Commerce\Quote;
use App\Domain\Commerce\QuoteLine;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\CommerceSettings;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Integrations\Payments\PaymentGateway;
use Illuminate\Database\Eloquent\Collection;

/**
 * Server-side price of a purchase (PRD-05 FR-02, FR-04, FR-11, FR-13): listed paid courses — or an active
 * bundle — in one currency; courses the beneficiary already has are left out (a bundle's price drops
 * pro-rata, provisional). A coupon then discounts the lines in its scope. A paid total is never left below
 * the gateway minimum (BR-05): the discount shrinks instead; a 100% discount makes the order free. Each line
 * carries the revenue-share snapshot (course override, then professor, then platform default).
 */
final class QuoteOrder
{
    public function __construct(
        private readonly CommerceSettings $settings,
        private readonly CouponRules $coupons,
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * @param  list<string>  $courseIds
     */
    public function handle(User $beneficiary, array $courseIds, ?string $bundleId = null, ?string $couponCode = null): Quote
    {
        $bundle = null;
        if ($bundleId !== null) {
            $bundle = Bundle::query()->where('status', BundleStatus::Active)->findOrFail($bundleId);
            $courseIds = $bundle->courses()->pluck('courses.id')->all();
        }

        $courses = Course::query()->listed()->whereIn('id', $courseIds)->with('professor', 'liveVersion')->get()->keyBy('id');
        $courseIds = array_values(array_unique($courseIds));

        if ($courseIds === [] || $courses->count() !== count($courseIds)) {
            throw CommerceException::courseUnavailable();
        }
        if ($courses->contains(fn (Course $c) => $c->is_free)) {
            throw CommerceException::freeCourse();
        }

        $currency = $this->currency($courses, $bundle);
        $owned = Enrollment::query()->active()->where('user_id', $beneficiary->id)->whereIn('course_id', $courseIds)->pluck('course_id')->all();

        $lines = [];
        foreach ($courseIds as $id) {
            $course = $courses[$id];
            if (in_array($course->id, $owned, true)) {
                continue;
            }
            $lines[] = new QuoteLine(
                course: $course,
                listPrice: $course->price() ?? throw CommerceException::courseUnavailable(),
                discount: Money::zero($currency),
                revenueShareBps: $course->revenue_share_bps ?? $course->professor->revenue_share_bps ?? $this->settings->default_revenue_share_bps,
                bundleId: $bundle?->id,
            );
        }

        if ($lines === []) {
            throw CommerceException::nothingToBuy();
        }

        if ($bundle !== null) {
            if ($couponCode !== null) {
                throw CommerceException::couponNotApplicable(); // bundles are already discounted
            }
            $lines = $this->priceBundle($bundle, $lines, $courses, $currency);
        }

        $coupon = null;
        if ($couponCode !== null) {
            $coupon = $this->coupons->find($couponCode);
            $lines = $this->coupons->apply($coupon, $lines, $currency, $beneficiary->id);
            $lines = $this->keepAboveMinimum($lines, $currency);
        }

        return new Quote($currency, $lines, excludedCourseIds: array_values($owned), coupon: $coupon, bundle: $bundle);
    }

    /**
     * @param  Collection<string, Course>  $courses
     */
    private function currency(Collection $courses, ?Bundle $bundle): Currency
    {
        $currencies = $courses->map(fn (Course $c) => (string) $c->currency)->unique();
        if ($currencies->count() !== 1 || ($bundle !== null && $bundle->currency !== $currencies->first())) {
            throw CommerceException::mixedCurrency();
        }
        if (! in_array($currencies->first(), $this->settings->enabled_currencies, true)) {
            throw CommerceException::currencyUnavailable();
        }

        return Currency::from((string) $currencies->first());
    }

    /**
     * Bundle price spread over the lines by list price. Courses already owned lower the price pro-rata
     * (provisional, PRD-05 §10). The discount is shared between professor and platform.
     *
     * @param  list<QuoteLine>  $lines
     * @param  Collection<string, Course>  $courses
     * @return list<QuoteLine>
     */
    private function priceBundle(Bundle $bundle, array $lines, Collection $courses, Currency $currency): array
    {
        $allList = $courses->sum(fn (Course $c) => (int) $c->price_minor);
        $remainingList = array_sum(array_map(fn (QuoteLine $l) => $l->listPrice->amountMinor, $lines));

        $price = Discounts::floorToStep(Money::of(intdiv($bundle->price_minor * $remainingList, max(1, $allList)), $currency));
        if ($price->amountMinor > $remainingList) {
            $price = Money::of($remainingList, $currency); // a bundle never costs more than its courses
        }

        $prices = Discounts::allocate($price, array_map(fn (QuoteLine $l) => $l->listPrice->amountMinor, $lines));

        return array_map(fn (QuoteLine $l, Money $p) => $l->withDiscount($l->listPrice->minus($p), DiscountFunder::Shared), $lines, $prices);
    }

    /**
     * BR-05: a paid total below what any enabled operator can collect is raised to that minimum by
     * shrinking the discounts; a total of zero stays zero (free order).
     *
     * @param  list<QuoteLine>  $lines
     * @return list<QuoteLine>
     */
    private function keepAboveMinimum(array $lines, Currency $currency): array
    {
        $subtotal = array_sum(array_map(fn (QuoteLine $l) => $l->listPrice->amountMinor, $lines));
        $discount = array_sum(array_map(fn (QuoteLine $l) => $l->discount->amountMinor, $lines));
        $total = $subtotal - $discount;
        $minimum = $this->minimum($currency);

        if ($total === 0 || $minimum === null || $total >= $minimum->amountMinor || $subtotal < $minimum->amountMinor) {
            return $lines;
        }

        $reduced = Discounts::allocate(Money::of($subtotal - $minimum->amountMinor, $currency), array_map(fn (QuoteLine $l) => $l->discount->amountMinor, $lines));

        return array_map(fn (QuoteLine $l, Money $d) => $l->funder === null ? $l : $l->withDiscount($d, $l->funder), $lines, $reduced);
    }

    private function minimum(Currency $currency): ?Money
    {
        $minimums = [];
        foreach (MobileMoneyRail::cases() as $rail) {
            if (in_array($rail->value, $this->settings->enabled_rails, true) && $this->gateway->supports($rail, $currency)) {
                $minimums[] = $this->gateway->limits($rail, $currency)->min->amountMinor;
            }
        }

        return $minimums === [] ? null : Money::of(max($minimums), $currency);
    }
}
