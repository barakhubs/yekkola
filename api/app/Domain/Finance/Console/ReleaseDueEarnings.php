<?php

declare(strict_types=1);

namespace App\Domain\Finance\Console;

use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Finance\Actions\ReleaseEarnings;
use App\Domain\Finance\EarningsRelease;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Platform\Settings\PayoutSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Throwable;

/**
 * Hourly (PRD-08 FR-03): releases professor earnings from paid orders past the earnings hold period.
 */
final class ReleaseDueEarnings extends Command
{
    protected $signature = 'finance:release-earnings';

    protected $description = 'Move professor earnings past the hold period from held to available';

    public function handle(ReleaseEarnings $release, PayoutSettings $settings): int
    {
        $released = 0;

        Order::query()
            ->whereIn('status', [OrderStatus::Paid, OrderStatus::PartiallyRefunded])
            ->where('paid_at', '<=', now()->subDays($settings->earnings_hold_days))
            // Orders with an item still to release: sold by this order, the professor earns from it, no refund took
            // it back, no release yet. (A professor-funded discount can still leave a zero share: such an order is
            // looked at again each run, harmlessly.)
            ->whereHas('items', fn ($items) => $items->whereNotNull('granted_at')->where('price_minor', '>', 0)->where('revenue_share_bps', '>', 0)
                ->whereDoesntHave('refunds', fn ($r) => $r->whereIn('status', ReleaseEarnings::DECIDED))
                ->whereNotExists(
                    fn (Builder $q) => $q->from('ledger_transactions')
                        ->whereColumn('ledger_transactions.reference_id', 'order_items.id')
                        ->where('ledger_transactions.reference_type', EarningsRelease::REFERENCE)
                        ->where('ledger_transactions.type', LedgerTransactionType::Release->value),
                ))
            ->lazyById(100)
            ->each(function (Order $order) use ($release, &$released): void {
                try {
                    $released += $release->handle($order);
                } catch (Throwable $e) {
                    report($e); // one bad order never stops the run
                }
            });

        $this->info("Released {$released} item(s).");

        return self::SUCCESS;
    }
}
