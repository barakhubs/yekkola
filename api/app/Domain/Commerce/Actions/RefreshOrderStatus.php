<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;

/**
 * Waiting-screen poll (PRD-05 FR-06): a payment still in flight is re-checked with the gateway, at most
 * every few seconds, so the screen moves on even if a webhook is late.
 */
final class RefreshOrderStatus
{
    private const RECHECK_SECONDS = 10;

    public function __construct(private readonly SyncPayment $sync) {}

    public function handle(Order $order): Order
    {
        $latest = Payment::query()->where('order_id', $order->id)->latest()->first();

        if ($latest !== null && $latest->status->inFlight()
            && ($latest->last_checked_at === null || $latest->last_checked_at->lt(now()->subSeconds(self::RECHECK_SECONDS)))) {
            $this->sync->handle($latest);
        }

        return $order->refresh();
    }
}
