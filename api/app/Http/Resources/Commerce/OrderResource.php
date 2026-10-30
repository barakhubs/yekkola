<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Actions\RequestRefund;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Commerce\Models\Refund;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its buyer sees it (PRD-05 FR-06, FR-17). Staff flags stay internal.
 *
 * @mixin Order
 */
final class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currency = Currency::from($this->currency);
        $money = fn (int $minor) => Money::of($minor, $currency)->toArray();
        $payments = $this->relationLoaded('payments') ? $this->payments->sortByDesc(fn (Payment $p) => [$p->created_at, $p->id])->values() : collect();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal' => $money($this->subtotal_minor),
            'discount' => $money($this->discount_minor),
            'total' => $money($this->total_minor),
            'expires_at' => $this->expires_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            /** Bought for another student (FR-14): the courses are on their account, not the buyer's. */
            'is_gift' => $this->isGift(),
            'items' => $this->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'course_id' => $item->course_id,
                'course_slug' => $item->course?->slug,
                'title' => $item->title_snapshot,
                'list_price' => $money($item->list_price_minor),
                'discount' => $money($item->discount_minor),
                'price' => $money($item->price_minor),
                'refund' => $this->refund($item),
            ])->all(),
            'payments' => $payments->map(fn (Payment $p) => new PaymentResource($p))->values(),
        ];
    }

    private function isGift(): bool
    {
        return $this->buyer_id !== null && $this->beneficiary_id !== $this->buyer_id;
    }

    /**
     * The item's latest refund, and whether a new request would be accepted. Answered only where the order list or
     * order page loaded what it needs (`items.activeEnrollment`, `items.refunds`).
     *
     * @return array{status: string|null, can_request: bool}
     */
    private function refund(OrderItem $item): array
    {
        if (! $item->relationLoaded('refunds') || ! $item->relationLoaded('activeEnrollment')) {
            return ['status' => null, 'can_request' => false];
        }

        $latest = $item->refunds->sortByDesc(fn (Refund $r) => [$r->created_at, $r->id])->first();
        $open = $item->refunds->contains(fn (Refund $r) => ! in_array($r->status, [RefundStatus::Rejected, RefundStatus::Cancelled], true));

        return [
            'status' => $latest?->status->value,
            'can_request' => app(RequestRefund::class)->refusal($this->resource, $item, $item->activeEnrollment, $open) === null,
        ];
    }
}
