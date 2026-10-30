<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\RefundKind;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Events\RefundRequested;
use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Platform\Settings\CommerceSettings;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;

/**
 * A student (or whoever bought the course for them) asks for a course to be refunded (PRD-05 FR-16): the
 * order is paid, within the refund window, the course was granted by this order and is still active, and
 * progress is below the limit (settings). One live request per course; staff decide (PRD-09 FR-12).
 */
final class RequestRefund
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CommerceSettings $settings,
        private readonly Dispatcher $events,
    ) {}

    public function handle(User $user, Order $order, string $orderItemId, ?string $reason): Refund
    {
        $refund = $this->db->transaction(function () use ($user, $order, $orderItemId, $reason): Refund {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $item = $order->items()->whereKey($orderItemId)->firstOrFail();

            $open = Refund::query()->where('order_item_id', $item->id)->whereNotIn('status', [RefundStatus::Rejected, RefundStatus::Cancelled])->exists();
            $refusal = $this->refusal($order, $item, Enrollment::query()->active()->where('order_item_id', $item->id)->first(), $open);
            if ($refusal !== null) {
                throw $refusal;
            }

            return Refund::query()->create([
                'kind' => RefundKind::Item,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'payment_id' => $order->paid_payment_id,
                'amount_minor' => $item->price_minor,
                'currency' => $item->currency,
                'status' => RefundStatus::Requested,
                'reason' => $reason,
                'requested_by' => $user->id,
            ]);
        });

        $this->events->dispatch(new RefundRequested($refund->id));

        return $refund;
    }

    /**
     * Why this item can't be refunded now, or null when a request would be accepted (FR-16, BR-06). Also tells the
     * orders page whether to offer the request.
     */
    public function refusal(Order $order, OrderItem $item, ?Enrollment $activeEnrollment, bool $hasOpenRefund): ?CommerceException
    {
        if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true) || $order->paid_payment_id === null || $item->price_minor === 0) {
            return CommerceException::notRefundable();
        }
        if ($order->paid_at === null || $order->paid_at->lt(now()->subDays($this->settings->refund_window_days))) {
            return CommerceException::refundWindowClosed($this->settings->refund_window_days);
        }
        if ($activeEnrollment === null) {
            return CommerceException::notRefundable(); // granted elsewhere, or already revoked
        }
        if ($activeEnrollment->progress_pct > $this->settings->refund_max_progress_pct) {
            return CommerceException::refundProgressTooHigh($this->settings->refund_max_progress_pct);
        }

        return $hasOpenRefund ? CommerceException::refundAlreadyRequested() : null;
    }
}
