<?php

declare(strict_types=1);

namespace App\Domain\Commerce;

use App\Domain\Commerce\Enums\RefundKind;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\MoneyFormatter;
use App\Domain\Shared\ValueObjects\Money;

/**
 * Receipt of a paid order (PRD-05 FR-15): order number, items, amounts, currency, operator, payment
 * references, date, and refunds (paid back, and pending) — in the reader's language. Payer numbers are masked. No tax lines yet
 * (VAT treatment is an open decision, TODO 0.5).
 */
final class Receipt
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Order $order, string $locale, bool $forGuest = false): array
    {
        $currency = Currency::from($order->currency);
        $money = fn (int $minor) => ['amount_minor' => $minor, 'currency' => $currency->value, 'formatted' => MoneyFormatter::format(Money::of($minor, $currency), $locale)];
        /** @var Payment|null $payment */
        $payment = $order->payments->firstWhere('id', $order->paid_payment_id);
        $refunds = Refund::query()->where('order_id', $order->id)->where('kind', RefundKind::Item)
            ->whereIn('status', [RefundStatus::Approved, RefundStatus::Processing, RefundStatus::Completed, RefundStatus::Failed])->get();
        $paidBack = $refunds->where('status', RefundStatus::Completed);

        return [
            'number' => $order->number,
            'status' => $order->status->value,
            'issued_at' => ($order->paid_at ?? $order->created_at)->toIso8601String(),
            // A guest typed a phone number: they never learn whose account it is.
            'student' => $forGuest ? null : $order->beneficiary?->name,
            'currency' => $currency->value,
            'items' => $order->items->map(fn (OrderItem $i) => [
                'title' => $i->title_snapshot,
                'list_price' => $money($i->list_price_minor),
                'discount' => $money($i->discount_minor),
                'price' => $money($i->price_minor),
                'refunded' => $refunds->contains('order_item_id', $i->id),
            ])->all(),
            'subtotal' => $money($order->subtotal_minor),
            'discount' => $money($order->discount_minor),
            'total' => $money($order->total_minor),
            'refunded' => $money((int) $paidBack->sum('amount_minor')),
            'refund_pending' => $money((int) $refunds->sum('amount_minor') - (int) $paidBack->sum('amount_minor')),
            'payment' => $payment === null ? null : [
                'rail' => $payment->rail->value,
                'payer' => $payment->payer()->masked(),
                'reference' => $payment->id,
                'operator_reference' => $payment->operator_reference,
                'paid_at' => $payment->succeeded_at?->toIso8601String(),
            ],
        ];
    }
}
