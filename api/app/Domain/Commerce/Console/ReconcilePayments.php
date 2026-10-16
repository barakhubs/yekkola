<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Console;

use App\Domain\Commerce\Actions\PaymentLifecycle;
use App\Domain\Commerce\Actions\SyncPayment;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Reconciliation (PRD-05 FR-10), every 5 minutes: expires orders past their payment timeout, re-queries
 * payments still in flight after 2 minutes, and keeps checking recently failed or expired ones for a late
 * success (which still grants access, flagged for review).
 */
final class ReconcilePayments extends Command
{
    protected $signature = 'commerce:reconcile';

    protected $description = 'Expire unpaid orders and re-check payments with the gateway';

    /** In-flight payments: re-check after this many minutes without news. */
    private const IN_FLIGHT_AFTER_MINUTES = 2;

    /** Failed/expired payments: look for a late success this long, this often. */
    private const LATE_WINDOW_HOURS = 24;

    private const LATE_EVERY_MINUTES = 60;

    public function handle(PaymentLifecycle $lifecycle, SyncPayment $sync): int
    {
        $expired = 0;
        Order::query()
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::AwaitingPayment])
            ->where('expires_at', '<', now())
            ->lazyById(100)
            ->each(function (Order $order) use ($lifecycle, &$expired): void {
                try {
                    $lifecycle->expire($order);
                    $expired++;
                } catch (Throwable $e) {
                    report($e); // one bad row never stops the run
                }
            });

        $checked = 0;
        $this->due()->lazyById(100)->each(function (Payment $payment) use ($sync, &$checked): void {
            try {
                $sync->handle($payment);
                $checked++;
            } catch (Throwable $e) {
                report($e);
            }
        });

        $this->info("Expired {$expired} orders, checked {$checked} payments.");

        return self::SUCCESS;
    }

    /**
     * @return Builder<Payment>
     */
    private function due(): Builder
    {
        $inFlightBefore = now()->subMinutes(self::IN_FLIGHT_AFTER_MINUTES);
        $lateBefore = now()->subMinutes(self::LATE_EVERY_MINUTES);

        return Payment::query()->where(fn (Builder $q) => $q
            ->where(fn (Builder $f) => $f
                ->whereIn('status', [PaymentStatus::Initiated, PaymentStatus::Pending])
                ->where('created_at', '<', $inFlightBefore)
                ->where(fn (Builder $c) => $c->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $inFlightBefore)))
            ->orWhere(fn (Builder $l) => $l
                ->whereIn('status', [PaymentStatus::Failed, PaymentStatus::Expired])
                // gateway_unavailable never reached the gateway: nothing to look up
                ->where(fn (Builder $c) => $c->whereNull('failure_code')->orWhere('failure_code', '!=', 'gateway_unavailable'))
                ->where('created_at', '>', now()->subHours(self::LATE_WINDOW_HOURS))
                ->where(fn (Builder $c) => $c->whereNull('last_checked_at')->orWhere('last_checked_at', '<', $lateBefore))));
    }
}
