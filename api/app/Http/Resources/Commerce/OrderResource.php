<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
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
            'items' => $this->items->map(fn (OrderItem $item) => [
                'course_id' => $item->course_id,
                'course_slug' => $item->course?->slug,
                'title' => $item->title_snapshot,
                'list_price' => $money($item->list_price_minor),
                'discount' => $money($item->discount_minor),
                'price' => $money($item->price_minor),
            ])->all(),
            'payments' => $payments->map(fn (Payment $p) => new PaymentResource($p))->values(),
        ];
    }
}
