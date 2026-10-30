<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Finance\EarningsRelease;
use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\SaleSplit;
use App\Domain\Platform\Settings\PayoutSettings;
use Illuminate\Database\ConnectionInterface;

/**
 * Moves a paid order's professor shares from held to available once the earnings hold period has passed
 * (PRD-08 FR-03). One `release` transaction per sold item, so it runs safely again; items never sold (already
 * owned) or whose refund was already decided (it took the share back from held) are skipped.
 */
final class ReleaseEarnings
{
    /** Refunds that have taken the professor share back. */
    public const DECIDED = [RefundStatus::Approved, RefundStatus::Processing, RefundStatus::Completed, RefundStatus::Failed];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PostLedgerTransaction $post,
        private readonly PayoutSettings $settings,
    ) {}

    /** Number of items released. */
    public function handle(Order $order): int
    {
        return $this->db->transaction(function () use ($order): int {
            // Same lock as refunds and reversals, which decide held vs available from the ledger.
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->with('items')->firstOrFail();

            if (! in_array($order->status, [OrderStatus::Paid, OrderStatus::PartiallyRefunded], true)
                || $order->paid_at === null
                || $order->paid_at->gt(now()->subDays($this->settings->earnings_hold_days))) {
                return 0;
            }

            $refunded = Refund::query()->where('order_id', $order->id)->whereIn('status', self::DECIDED)->pluck('order_item_id')->all();
            $released = 0;

            foreach ($order->items as $item) {
                // Only items this order sold: a course the student already owned was never posted as a sale
                // (its money went back through an unapplied-money refund).
                if ($item->granted_at === null || in_array($item->id, $refunded, true) || EarningsRelease::isReleased($item)) {
                    continue;
                }
                if ($this->release($order, $item)) {
                    $released++;
                }
            }

            return $released;
        });
    }

    private function release(Order $order, OrderItem $item): bool
    {
        [$professor] = SaleSplit::of($item);

        return $this->post->handle(LedgerTransactionType::Release, EarningsRelease::REFERENCE, $item->id, [
            EntryLine::debit(LedgerAccountType::ProfessorHeld, $professor, $item->professor_profile_id),
            EntryLine::credit(LedgerAccountType::ProfessorAvailable, $professor, $item->professor_profile_id),
        ], memo: "Release from order {$order->number}") !== null;
    }
}
