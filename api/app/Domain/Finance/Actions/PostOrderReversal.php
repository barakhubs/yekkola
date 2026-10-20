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
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Support\Collection;

/**
 * The operator took a paid collection back (PRD-05 BR-04): every course this order sold is taken back from
 * the professor and the platform — except courses whose refund money had already gone out (or may have):
 * their share was already taken back by the refund, and the payer now has that money twice, so it is
 * recorded as owed by the payer instead. The gateway fee legs are mirrored. One posting per order.
 */
final class PostOrderReversal
{
    public function __construct(private readonly PostLedgerTransaction $post) {}

    /**
     * @param  Collection<int, OrderItem>  $sold  Items this order granted access for.
     * @param  list<string>  $paidOutItemIds  Of those, items whose refund is being or was paid out.
     */
    public function handle(Order $order, Payment $payment, Collection $sold, array $paidOutItemIds): ?LedgerTransaction
    {
        $lines = [];

        foreach ($sold as $item) {
            if (in_array($item->id, $paidOutItemIds, true)) {
                $lines[] = EntryLine::debit(LedgerAccountType::PayerReceivable, $item->price());
                $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $item->price());

                continue;
            }

            [$professor, $platform, $platformDiscount] = SaleSplit::of($item);
            $lines[] = EntryLine::debit(LedgerAccountType::ProfessorHeld, $professor, $item->professor_profile_id);
            $lines[] = EntryLine::debit(LedgerAccountType::PlatformRevenue, $platform);
            $lines[] = EntryLine::credit(LedgerAccountType::DiscountsPlatform, $platformDiscount);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayClearing, $item->price());
        }

        if ($payment->fee_minor !== null && $payment->fee_minor > 0) {
            $fee = Money::of($payment->fee_minor, $payment->amount()->currency);
            $lines[] = EntryLine::debit(LedgerAccountType::GatewayClearing, $fee);
            $lines[] = EntryLine::credit(LedgerAccountType::GatewayFees, $fee);
        }

        return $this->post->handle(LedgerTransactionType::SaleReversal, PostSale::REFERENCE, $order->id, $lines, memo: "Reversal of order {$order->number}");
    }
}
