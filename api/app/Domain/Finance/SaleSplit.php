<?php

declare(strict_types=1);

namespace App\Domain\Finance;

use App\Domain\Commerce\Enums\DiscountFunder;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Shared\ValueObjects\Money;

/**
 * How one order item's price is shared (PRD-08 FR-02), from its snapshot — used by the sale posting and, in
 * reverse, by refunds (never recomputed as price × share).
 */
final class SaleSplit
{
    /**
     * Professor part, platform part, and the platform-funded discount for one item, from its snapshot
     * (DiscountFunder). Always: professor + platform = price + platform-funded discount.
     *
     * @return array{0: Money, 1: Money, 2: Money}
     */
    public static function of(OrderItem $item): array
    {
        $price = $item->price();
        $list = Money::of($item->list_price_minor, $price->currency);
        $zero = Money::zero($price->currency);

        if ($price->isZero()) {
            return [$zero, $zero, $zero]; // discounted to nothing: nobody earns, whatever else is in the order
        }
        $funder = $item->discount_minor === 0 ? null : DiscountFunder::tryFrom((string) $item->discount_funded_by);

        switch ($funder) {
            case DiscountFunder::Platform:
                // The professor earns their share of the list price, but never more than was paid for the item
                // (so nobody profits from buying a discounted course); the platform's discounts account pays
                // the difference to its usual share.
                $share = $list->shareBps($item->revenue_share_bps);
                $professor = $price->greaterThanOrEqual($share) ? $share : $price;
                $platform = $list->minus($share);

                return [$professor, $platform, $professor->plus($platform)->minus($price)];

            case DiscountFunder::Professor:
                // The platform keeps its share of the list price; the professor gets the rest, never below 0.
                $platform = $list->minus($list->shareBps($item->revenue_share_bps));
                if ($platform->greaterThanOrEqual($price)) {
                    return [$zero, $price, $zero];
                }

                return [$price->minus($platform), $platform, $zero];

            default:
                // No discount, or shared: both shares apply to the price paid.
                $professor = $price->shareBps($item->revenue_share_bps);

                return [$professor, $price->minus($professor), $zero];
        }
    }
}
