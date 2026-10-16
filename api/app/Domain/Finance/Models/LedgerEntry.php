<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\EntryDirection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a ledger transaction. Positive amount; the direction gives the sign.
 *
 * @property string $id
 * @property string $ledger_transaction_id
 * @property string $ledger_account_id
 * @property EntryDirection $direction
 * @property int $amount_minor
 * @property string $currency
 */
final class LedgerEntry extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['direction' => EntryDirection::class, 'amount_minor' => 'integer'];
    }

    /**
     * @return BelongsTo<LedgerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
