<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Models;

use App\Domain\Commerce\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payout of a refund at the gateway, with its own reference and ledger postings.
 *
 * @property string $id
 * @property string $refund_id
 * @property int $number
 * @property string $reference
 * @property string $gateway
 * @property AttemptStatus $status
 * @property string|null $outcome completed | overpaid
 * @property int|null $confirmed_amount_minor
 * @property int|null $fee_minor
 * @property string|null $gateway_reference
 * @property string|null $operator_reference
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property int $checks
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon $created_at
 */
final class RefundAttempt extends Model
{
    use HasUlids;

    public const OUTCOME_COMPLETED = 'completed';

    public const OUTCOME_OVERPAID = 'overpaid';

    protected $guarded = [];

    protected $attributes = ['checks' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'number' => 'integer',
            'confirmed_amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'checks' => 'integer',
            'last_checked_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Refund, $this>
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
