<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\OrderFlag;
use App\Domain\Commerce\Enums\OrderStatus;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a student buys (PRD-05). Single currency; items snapshot prices and revenue shares at creation.
 *
 * @property string $id
 * @property string $number
 * @property string $buyer_id
 * @property string $beneficiary_id
 * @property OrderStatus $status
 * @property string $currency
 * @property int $subtotal_minor
 * @property int $discount_minor
 * @property int $total_minor
 * @property string $idempotency_key
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property string|null $paid_payment_id
 * @property OrderFlag|null $flag
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Order extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected $attributes = ['discount_minor' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'flag' => OrderFlag::class,
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(User::class, 'beneficiary_id');
    }

    public function total(): Money
    {
        return Money::of($this->total_minor, Currency::from($this->currency));
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
