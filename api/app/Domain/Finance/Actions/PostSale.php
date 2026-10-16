<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Finance\EntryLine;
use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Finance\Enums\LedgerTransactionType;
use App\Domain\Finance\Models\LedgerTransaction;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Collection;

/**
 * Sale posting on payment success (PRD-08 FR-02) for the items that granted access: debit gateway clearing
 * with their price; credit each professor's held earnings with their snapshot share; credit platform revenue
 * with the rest (items already owned are posted as unapplied money instead).
 * A gateway fee moves from clearing to gateway fees (borne by the platform). Idempotent per order.
 *
 * TODO(1.7b): discount lines per funder once coupons exist.
 */
final class PostSale
{
    public const REFERENCE = 'order';

    public function __construct(private readonly PostLedgerTransaction $post) {}

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    public function handle(Order $order, Payment $payment, Collection $items): LedgerTransaction
    {
        $currency = Currency::from($order->currency);
        $platform = Money::zero($currency);
        $sold = $items->reduce(fn (Money $sum, OrderItem $i) => $sum->plus($i->price()), Money::zero($currency));
        $lines = [EntryLine::debit(LedgerAccountType::GatewayClearing, $sold)];

        foreach ($items as $item) {
            $share = $item->price()->shareBps($item->revenue_share_bps);
            $lines[] = EntryLine::credit(LedgerAccountType::ProfessorHeld, $share, $item->professor_profile_id);
            $platform = $platform->plus($item->price()->minus($share));
        }

        $lines[] = EntryLine::credit(LedgerAccountType::PlatformRevenue, $platform);

        if ($payment->fee_minor !== null && $payment->fee_minor > 0) {
            $fee = Money::of($payment->fee_minor, $currency);
            $lines[] = EntryLine::debit(LedgerAccountType::GatewayFees, $fee);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $fee);
        }

        return $this->post->handle(LedgerTransactionType::Sale, self::REFERENCE, $order->id, $lines, memo: "Order {$order->number}");
    }
}
