<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;

/**
 * Waiting-screen poll (PRD-05 FR-06): a payment still in flight is re-checked with the gateway, at most
 * every few seconds, so the screen moves on even if a webhook is late. An order past its payment deadline is
 * expired here rather than waiting for the reconcile run, so the student isn't offered a retry that can't work.
 */
final class RefreshOrderStatus
{
    private const RECHECK_SECONDS = 10;

    public function __construct(
        private readonly SyncPayment $sync,
        private readonly PaymentLifecycle $lifecycle,
    ) {}

    public function handle(Order $order): Order
    {
        $latest = Payment::query()->where('order_id', $order->id)->latest()->latest('id')->first();

        if ($latest !== null && $latest->status->inFlight()
            && ($latest->last_checked_at === null || $latest->last_checked_at->lt(now()->subSeconds(self::RECHECK_SECONDS)))) {
            $this->sync->handle($latest);
        }

        $order->refresh();
        if ($order->status->acceptsPayment() && $order->expires_at->isPast()) {
            // After the re-check above, so a payment confirmed at the last second still wins.
            $this->lifecycle->expire($order);
            $order->refresh();
        }

        return $order;
    }
}
