<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Events\OrderPaid;
use App\Domain\Commerce\Models\Order;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * A 100%-discount order (PRD-05 BR-05): a free enrollment with source `coupon` — nothing to collect, no
 * ledger posting, nobody earns. If nothing new was granted (bought meanwhile), the order is cancelled.
 * Call inside the order-creation transaction.
 */
final class CompleteFreeOrder
{
    public function __construct(
        private readonly FulfilOrder $fulfil,
        private readonly Dispatcher $events,
    ) {}

    public function handle(Order $order): void
    {
        [$granted] = $this->fulfil->handle($order->load('items'));

        if ($granted->isEmpty()) {
            $order->forceFill(['status' => OrderStatus::Cancelled])->save();

            return;
        }

        $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
        $this->events->dispatch(new OrderPaid($order->id));
    }
}
