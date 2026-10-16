<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Commerce\Enums\OrderFlag;
use App\Domain\Commerce\Enums\PaymentStatus;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to pay an order through a gateway (PRD-05 FR-05). Our id is the gateway reference.
 *
 * @property string $id
 * @property string $order_id
 * @property string $idempotency_key
 * @property string $gateway
 * @property MobileMoneyRail $rail
 * @property string $msisdn
 * @property int $amount_minor
 * @property string $currency
 * @property int|null $confirmed_amount_minor
 * @property int|null $fee_minor
 * @property PaymentStatus $status
 * @property string|null $gateway_reference
 * @property string|null $operator_reference
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property OrderFlag|null $flag
 * @property int $checks
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $succeeded_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Payment extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['checks' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rail' => MobileMoneyRail::class,
            'status' => PaymentStatus::class,
            'flag' => OrderFlag::class,
            'msisdn' => 'encrypted',
            'last_result' => 'encrypted:array',
            'amount_minor' => 'integer',
            'confirmed_amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'checks' => 'integer',
            'last_checked_at' => 'datetime',
            'succeeded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, Currency::from($this->currency));
    }

    public function payer(): PhoneNumber
    {
        return PhoneNumber::fromString($this->msisdn);
    }
}
