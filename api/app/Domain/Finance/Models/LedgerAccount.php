<?php

declare(strict_types=1);

namespace App\Domain\Finance\Models;

use App\Domain\Finance\Enums\LedgerAccountType;
use App\Domain\Professors\Models\ProfessorProfile;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ledger account per type, owner, and currency (PRD-08 FR-01). Balances are derived from entries.
 *
 * @property string $id
 * @property LedgerAccountType $type
 * @property string $owner_type
 * @property string|null $owner_id
 * @property string $currency
 */
final class LedgerAccount extends Model
{
    use HasUlids;

    public const OWNER_PLATFORM = 'platform';

    public const OWNER_PROFESSOR = 'professor';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['type' => LedgerAccountType::class];
    }

    /**
     * @return HasMany<LedgerEntry, $this>
     */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /**
     * The professor a professor account belongs to (null for platform accounts).
     *
     * @return BelongsTo<ProfessorProfile, $this>
     */
    public function professor(): BelongsTo
    {
        return $this->belongsTo(ProfessorProfile::class, 'owner_id');
    }
}
