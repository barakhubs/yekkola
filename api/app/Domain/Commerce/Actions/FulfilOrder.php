<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderFlag;
use App\Domain\Commerce\Models\Coupon;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Learning\Actions\GrantEnrollment;
use App\Domain\Learning\Enums\EnrollmentSource;
use Illuminate\Database\Eloquent\Collection;

/**
 * Grants what an order bought (PRD-05 FR-08, BR-01, BR-05): one enrollment per item, in a fixed order so
 * overlapping orders can't deadlock, and records the coupon use. A late success can take a coupon past its
 * limit (its reservation had lapsed): the use is kept — the money was collected — and the order flagged.
 * Purchases are fulfilled only by the payment state machine; free orders by CompleteFreeOrder. Call inside
 * the caller's transaction, with the order locked.
 */
final class FulfilOrder
{
    public function __construct(
        private readonly GrantEnrollment $grant,
    ) {}

    /**
     * @return array{0: Collection<int, OrderItem>, 1: Collection<int, OrderItem>} [granted, already owned]
     */
    public function handle(Order $order): array
    {
        [$granted, $owned] = $order->items->sortBy('course_id')->values()->partition(
            fn (OrderItem $item) => $this->grant->handle($order->beneficiary_id, $item->course_id, $this->source($order, $item), $item->id),
        );

        // What this order sold: a reversal undoes exactly these, whatever happens to the enrollments later.
        OrderItem::query()->whereIn('id', $granted->modelKeys())->update(['granted_at' => now()]);

        if ($granted->isNotEmpty() && $order->coupon_id !== null) {
            $coupon = Coupon::query()->whereKey($order->coupon_id)->lockForUpdate()->firstOrFail();
            $redemption = CouponRedemption::query()->createOrFirst(
                ['order_id' => $order->id],
                ['coupon_id' => $coupon->id, 'user_id' => $order->beneficiary_id, 'created_at' => now()],
            );

            if ($redemption->wasRecentlyCreated && $this->overLimit($coupon, $order->beneficiary_id)) {
                $order->flag = OrderFlag::raise($order->flag, OrderFlag::CouponOverLimit);
            }
        }

        return [$granted, $owned];
    }

    private function source(Order $order, OrderItem $item): EnrollmentSource
    {
        return match (true) {
            $order->bundle_id !== null => EnrollmentSource::Bundle,
            $item->price_minor === 0 => EnrollmentSource::Coupon,
            default => EnrollmentSource::Purchase,
        };
    }

    private function overLimit(Coupon $coupon, string $userId): bool
    {
        $uses = CouponRedemption::query()->where('coupon_id', $coupon->id);

        return ($coupon->max_redemptions !== null && (clone $uses)->count() > $coupon->max_redemptions)
            || ($coupon->per_user_limit !== null && (clone $uses)->where('user_id', $userId)->count() > $coupon->per_user_limit);
    }
}
