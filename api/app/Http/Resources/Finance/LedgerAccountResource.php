<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Domain\Finance\Models\LedgerAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ledger account with its totals (PRD-09 finance views). `balance_minor` = debits − credits: positive for what
 * the platform holds or spent, negative for what it owes (professor accounts, refunds payable) or earned.
 *
 * @mixin LedgerAccount
 */
final class LedgerAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'owner_type' => $this->owner_type,
            'owner_id' => $this->owner_id,
            /** @var string|null */
            'owner_name' => $this->professor?->display_name,
            'currency' => $this->currency,
            'debits_minor' => $this->debits(),
            'credits_minor' => $this->credits(),
            'balance_minor' => $this->debits() - $this->credits(),
        ];
    }

    private function debits(): int
    {
        return (int) $this->getAttribute('debits_minor');
    }

    private function credits(): int
    {
        return (int) $this->getAttribute('credits_minor');
    }
}
