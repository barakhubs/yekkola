<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\RefundKind;
use App\Domain\Commerce\Enums\RefundStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Money sent back to the payer of a collection (PRD-05 FR-16). Each payout attempt is a RefundAttempt
 * with its own gateway reference, so a failed one can be retried and a late success is never lost.
 *
 * @property string $id
 * @property RefundKind $kind
 * @property string $order_id
 * @property string|null $order_item_id
 * @property string $payment_id
 * @property int $amount_minor
 * @property string $currency
 * @property RefundStatus $status
 * @property string|null $flag
 * @property string|null $reason
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property string|null $decision_note
 * @property int $attempts
 * @property string|null $failure_code
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Refund extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['attempts' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RefundKind::class,
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'attempts' => 'integer',
            'decided_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<OrderItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return HasMany<RefundAttempt, $this>
     */
    public function payoutAttempts(): HasMany
    {
        return $this->hasMany(RefundAttempt::class)->orderBy('number');
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, Currency::from($this->currency));
    }

    /** Gateway reference for payout attempt number n. */
    public function referenceFor(int $number): string
    {
        return 'R-'.$this->id.'-'.$number;
    }
}
