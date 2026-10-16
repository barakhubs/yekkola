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

/**
 * Money sent back to the payer of a collection (PRD-05 FR-16). Each disbursement attempt has its own
 * gateway reference, so a failed one can be retried without the gateway treating it as the same transfer.
 *
 * @property string $id
 * @property RefundKind $kind
 * @property string $order_id
 * @property string|null $order_item_id
 * @property string $payment_id
 * @property int $amount_minor
 * @property string $currency
 * @property RefundStatus $status
 * @property string|null $reason
 * @property string|null $requested_by
 * @property string|null $decided_by
 * @property \Illuminate\Support\Carbon|null $decided_at
 * @property string|null $decision_note
 * @property int $attempt
 * @property \Illuminate\Support\Carbon|null $attempted_at
 * @property string|null $flag
 * @property string|null $gateway
 * @property string|null $gateway_reference
 * @property string|null $operator_reference
 * @property int|null $fee_minor
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int $checks
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Refund extends Model
{
    use HasUlids;

    public const REFERENCE_PREFIX = 'R-';

    protected $guarded = [];

    protected $attributes = ['attempt' => 0, 'checks' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RefundKind::class,
            'status' => RefundStatus::class,
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'attempt' => 'integer',
            'checks' => 'integer',
            'decided_at' => 'datetime',
            'attempted_at' => 'datetime',
            'last_checked_at' => 'datetime',
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

    public function amount(): Money
    {
        return Money::of($this->amount_minor, Currency::from($this->currency));
    }

    /** The gateway reference of the current attempt. */
    public function reference(): string
    {
        return self::REFERENCE_PREFIX.$this->id.'-'.$this->attempt;
    }

    /** The refund id inside a gateway reference, or null if it isn't one of ours. */
    public static function idFromReference(string $reference): ?string
    {
        return preg_match('/^R-([0-9A-Za-z]{26})-\d+$/', $reference, $m) === 1 ? strtolower($m[1]) : null;
    }
}
