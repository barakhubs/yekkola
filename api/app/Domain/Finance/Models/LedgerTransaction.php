<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\LedgerTransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A balanced, immutable posting (PRD-08 BR-02). Create only through PostLedgerTransaction.
 *
 * @property string $id
 * @property LedgerTransactionType $type
 * @property string $reference_type
 * @property string $reference_id
 * @property string|null $memo
 * @property string|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 */
final class LedgerTransaction extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => LedgerTransactionType::class];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
