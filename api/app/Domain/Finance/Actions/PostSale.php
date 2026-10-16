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
use App\Domain\Finance\SaleSplit;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Collection;

/**
 * Sale posting on payment success (PRD-08 FR-02) for the items that granted access: debit gateway clearing
 * with their price; credit each professor's held earnings with their snapshot share; credit platform revenue
 * with the rest (items already owned are posted as unapplied money instead). A platform-funded discount is
 * debited to the platform's discounts account (PRD-08 FR-02).
 * A gateway fee moves from clearing to gateway fees (borne by the platform). Idempotent per order.
 */
final class PostSale
{
    public const REFERENCE = 'order';

    public function __construct(private readonly PostLedgerTransaction $post) {}

    /**
     * @param  Collection<int, OrderItem>  $items
     */
    public function handle(Order $order, Payment $payment, Collection $items): ?LedgerTransaction
    {
        $currency = Currency::from($order->currency);
        $sold = Money::zero($currency);
        $platform = Money::zero($currency);
        $platformDiscounts = Money::zero($currency);
        $lines = [];

        foreach ($items as $item) {
            [$professor, $platformPart, $funded] = SaleSplit::of($item);
            $sold = $sold->plus($item->price());
            $platform = $platform->plus($platformPart);
            $platformDiscounts = $platformDiscounts->plus($funded);
            $lines[] = EntryLine::credit(LedgerAccountType::ProfessorHeld, $professor, $item->professor_profile_id);
        }

        $lines[] = EntryLine::debit(LedgerAccountType::GatewayClearing, $sold);
        $lines[] = EntryLine::debit(LedgerAccountType::DiscountsPlatform, $platformDiscounts);
        $lines[] = EntryLine::credit(LedgerAccountType::PlatformRevenue, $platform);

        if ($payment->fee_minor !== null && $payment->fee_minor > 0) {
            $fee = Money::of($payment->fee_minor, $currency);
            $lines[] = EntryLine::debit(LedgerAccountType::GatewayFees, $fee);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $fee);
        }

        return $this->post->handle(LedgerTransactionType::Sale, self::REFERENCE, $order->id, $lines, memo: "Order {$order->number}");
    }
}
