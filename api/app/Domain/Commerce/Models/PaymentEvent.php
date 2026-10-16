<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Payment webhook inbox, deduplicated by gateway event id (PRD-05 FR-07).
 *
 * @property string $id
 * @property string $gateway
 * @property string $event_id
 * @property string $kind
 * @property string $reference
 * @property array<string, mixed> $payload
 * @property \Illuminate\Support\Carbon $received_at
 * @property \Illuminate\Support\Carbon|null $processed_at
 * @property string|null $error
 * @property int $attempts
 */
final class PaymentEvent extends Model
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
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }
}
