<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Commerce\Enums\CouponScope;
use App\Domain\Commerce\Enums\DiscountType;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Cache\RateLimiter;

/**
 * Coupon rules (PRD-05 FR-11): active and within its window, in scope for at least one line, currency of a
 * fixed amount matches, and within its limits. Uses are counted on payment success, and open orders using
 * the coupon count as reservations — so limits hold under concurrent checkouts (re-checked under a lock
 * when the order is created). Percent coupons discount each eligible line; fixed ones are split across
 * eligible lines by list price. Discounts are whole collection steps and never exceed a line's price.
 */
final class CouponRules
{
    /** Wrong codes a user may try per day before every code is refused (stops guessing). */
    public const DAILY_FAILED_LOOKUPS = 30;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function find(string $code, string $userId): Coupon
    {
        $key = 'coupon-failures:'.$userId;
        if ($this->limiter->tooManyAttempts($key, self::DAILY_FAILED_LOOKUPS)) {
            throw CommerceException::couponInvalid();
        }

        $coupon = Coupon::query()->where('code', Coupon::normalize($code))->first();

        if ($coupon === null || ! $coupon->isRedeemableNow()) {
            $this->limiter->hit($key, 86_400);

            throw CommerceException::couponInvalid();
        }

        return $coupon;
    }

    /**
     * @param  list<QuoteLine>  $lines
     * @return list<QuoteLine>
     */
    public function apply(Coupon $coupon, array $lines, Currency $currency, string $beneficiaryId): array
    {
        $eligible = array_keys(array_filter($lines, fn (QuoteLine $l) => $this->inScope($coupon, $l)));

        if ($eligible === [] || ($coupon->discount_type === DiscountType::Fixed && $coupon->currency !== $currency->value)) {
            throw CommerceException::couponNotApplicable();
        }

        $this->assertWithinLimits($coupon, $beneficiaryId);

        $discounts = $coupon->discount_type === DiscountType::Percent
            ? array_map(fn (int $i) => Discounts::floorToStep($lines[$i]->listPrice->shareBps($coupon->value * 100)), $eligible)
            : $this->splitFixed($coupon, array_map(fn (int $i) => $lines[$i], $eligible), $currency);

        foreach ($eligible as $n => $i) {
            $lines[$i] = $lines[$i]->withDiscount($discounts[$n], $coupon->funded_by);
        }

        return $lines;
    }

    public function assertWithinLimits(Coupon $coupon, string $beneficiaryId, ?string $exceptOrderId = null): void
    {
        if ($coupon->max_redemptions !== null && $this->uses($coupon, null, $exceptOrderId) >= $coupon->max_redemptions) {
            throw CommerceException::couponExhausted();
        }

        if ($coupon->per_user_limit !== null && $this->uses($coupon, $beneficiaryId, $exceptOrderId) >= $coupon->per_user_limit) {
            throw CommerceException::couponAlreadyUsed();
        }
    }

    private function inScope(Coupon $coupon, QuoteLine $line): bool
    {
        return match ($coupon->scope) {
            CouponScope::Platform => true,
            CouponScope::Professor => $line->course->professor_profile_id === $coupon->professor_profile_id,
            CouponScope::Course => $line->course->id === $coupon->course_id,
        };
    }

    /**
     * @param  list<QuoteLine>  $lines
     * @return list<Money>
     */
    private function splitFixed(Coupon $coupon, array $lines, Currency $currency): array
    {
        $eligibleTotal = array_reduce($lines, fn (Money $sum, QuoteLine $l) => $sum->plus($l->listPrice), Money::zero($currency));
        $amount = Money::of(min($coupon->value, $eligibleTotal->amountMinor), $currency);

        return Discounts::allocate(Discounts::floorToStep($amount), array_map(fn (QuoteLine $l) => $l->listPrice->amountMinor, $lines));
    }

    /** Redemptions plus open orders still holding the coupon. */
    private function uses(Coupon $coupon, ?string $userId, ?string $exceptOrderId): int
    {
        $redeemed = CouponRedemption::query()->where('coupon_id', $coupon->id)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->count();

        // An order that expires with a payment still unanswered can succeed late; that rare overrun is
        // recorded and flagged (FulfilOrder) rather than holding the slot for a day.
        $reserved = Order::query()->where('coupon_id', $coupon->id)
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::AwaitingPayment])
            ->where('expires_at', '>', now())
            ->when($userId !== null, fn ($q) => $q->where('beneficiary_id', $userId))
            ->when($exceptOrderId !== null, fn ($q) => $q->whereKeyNot($exceptOrderId))
            ->count();

        return $redeemed + $reserved;
    }
}
