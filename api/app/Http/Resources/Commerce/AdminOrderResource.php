<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Payment;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order in the back office list (PRD-09 FR-11). Phone numbers are masked.
 *
 * @mixin Order
 */
final class AdminOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latest = $this->payments->sortByDesc(fn (Payment $p) => [$p->created_at, $p->id])->first();

        return [
            'id' => $this->id,
            'number' => $this->number,
            'status' => $this->status->value,
            /** @var string|null late_success, amount_mismatch, reversed, duplicate_payment, already_enrolled, coupon_over_limit */
            'flag' => $this->flag?->value,
            'total' => Money::of($this->total_minor, Currency::from($this->currency))->toArray(),
            'student' => self::person($this->beneficiary),
            /** @var array{name: string|null, phone: string|null}|null The person who paid, when not the student. */
            'buyer' => $this->buyer_id !== null && $this->buyer_id !== $this->beneficiary_id ? self::person($this->buyer) : null,
            'courses' => $this->items->map(fn (OrderItem $i) => $i->title_snapshot)->values()->all(),
            /** @var string|null */
            'latest_payment_status' => $latest?->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{name: string|null, phone: string|null}
     */
    public static function person(?User $user): array
    {
        return ['name' => $user?->name, 'phone' => $user?->maskedPhone()];
    }
}
