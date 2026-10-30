<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Commerce\Enums\MobileMoneyRail;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One professor's payout in a batch. The payout method is snapshotted when the batch is built.
 *
 * @property string $id
 * @property string $payout_batch_id
 * @property string $professor_profile_id
 * @property int $amount_minor
 * @property string $currency
 * @property MobileMoneyRail $rail
 * @property string $msisdn
 * @property string $name
 * @property string $reference
 * @property PayoutStatus $status
 * @property string|null $gateway
 * @property string|null $gateway_reference
 * @property string|null $operator_reference
 * @property int|null $fee_minor
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property string|null $excluded_reason
 * @property int $checks
 * @property \Illuminate\Support\Carbon|null $sent_at
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class Payout extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => PayoutStatus::class,
            'rail' => MobileMoneyRail::class,
            'msisdn' => 'encrypted',
            'sent_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function amount(): Money
    {
        return Money::of($this->amount_minor, Currency::from($this->currency));
    }

    /**
     * @return BelongsTo<PayoutBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(PayoutBatch::class, 'payout_batch_id');
    }

    /**
     * @return BelongsTo<ProfessorProfile, $this>
     */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(ProfessorProfile::class, 'professor_profile_id');
    }
}
