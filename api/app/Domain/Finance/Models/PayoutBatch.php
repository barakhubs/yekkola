<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\PayoutBatchStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payout run in one currency (PRD-08 FR-06–FR-08): built as a draft, reviewed, approved, then paid line by line.
 *
 * @property string $id
 * @property PayoutBatchStatus $status
 * @property string $currency
 * @property string|null $created_by
 * @property string|null $approved_by
 * @property \Illuminate\Support\Carbon|null $approved_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class PayoutBatch extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => PayoutBatchStatus::class, 'approved_at' => 'datetime'];
    }

    /**
     * @return HasMany<Payout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
