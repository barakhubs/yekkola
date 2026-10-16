<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\RefundKind;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Events\RefundRequested;
use App\Domain\Commerce\Exceptions\CommerceException;
use App\Domain\Commerce\Models\Order;
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

            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true) || $order->paid_payment_id === null || $item->price_minor === 0) {
                throw CommerceException::notRefundable();
            }
            if ($order->paid_at === null || $order->paid_at->lt(now()->subDays($this->settings->refund_window_days))) {
                throw CommerceException::refundWindowClosed($this->settings->refund_window_days);
            }

            $enrollment = Enrollment::query()->active()->where('order_item_id', $item->id)->first();
            if ($enrollment === null) {
                throw CommerceException::notRefundable(); // granted elsewhere, or already revoked
            }
            if ($enrollment->progress_pct > $this->settings->refund_max_progress_pct) {
                throw CommerceException::refundProgressTooHigh($this->settings->refund_max_progress_pct);
            }

            $existing = Refund::query()->where('order_item_id', $item->id)->where('status', '!=', RefundStatus::Rejected)->exists();
            if ($existing) {
                throw CommerceException::refundAlreadyRequested();
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
}
