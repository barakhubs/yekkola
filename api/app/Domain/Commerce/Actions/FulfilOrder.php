<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Models\CouponRedemption;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Learning\Actions\GrantEnrollment;
use App\Domain\Learning\Enums\EnrollmentSource;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Collection;

/**
 * Grants what an order bought (PRD-05 FR-08, BR-01, BR-05): one enrollment per item, in a fixed order so
 * overlapping orders can't deadlock, and the coupon use is recorded. Purchases are fulfilled only by the
 * payment state machine; free (100%-discount) orders at creation. Call inside the caller's transaction.
 */
final class FulfilOrder
{
    public function __construct(
        private readonly GrantEnrollment $grant,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return array{0: Collection<int, OrderItem>, 1: Collection<int, OrderItem>} [granted, already owned]
     */
    public function grant(Order $order): array
    {
        $source = match (true) {
            $order->bundle_id !== null => EnrollmentSource::Bundle,
            $order->total_minor === 0 => EnrollmentSource::Coupon,
            default => EnrollmentSource::Purchase,
        };

        [$granted, $owned] = $order->items->sortBy('course_id')->values()->partition(
            fn (OrderItem $item) => $this->grant->handle($order->beneficiary_id, $item->course_id, $source, $item->id),
        );

        if ($granted->isNotEmpty() && $order->coupon_id !== null) {
            CouponRedemption::query()->createOrFirst(
                ['order_id' => $order->id],
                ['coupon_id' => $order->coupon_id, 'user_id' => $order->beneficiary_id, 'created_at' => now()],
            );
        }

        return [$granted, $owned];
    }

    /** A free order (100% discount): nothing to collect, no ledger posting, access at once. */
    public function free(Order $order): void
    {
        $this->grant($order->load('items'));
        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
        $this->events->dispatch(new OrderPaid($order->id));
    }
}
