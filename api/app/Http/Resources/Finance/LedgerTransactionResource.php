<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Domain\Finance\Models\LedgerEntry;
use App\Domain\Finance\Models\LedgerTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A ledger transaction with its entries (PRD-09 finance views).
 *
 * @mixin LedgerTransaction
 */
final class LedgerTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'memo' => $this->memo,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at->toIso8601String(),
            'entries' => $this->entryLines(),
        ];
    }

    /**
     * @return list<array{account_id: string, account_type: string, owner_name: string|null, direction: string, amount_minor: int, currency: string}>
     */
    private function entryLines(): array
    {
        return $this->entries->map(fn (LedgerEntry $e) => [
            'account_id' => $e->ledger_account_id,
            'account_type' => $e->account->type->value,
            'owner_name' => $e->account->professor?->display_name,
            'direction' => $e->direction->value,
            'amount_minor' => $e->amount_minor,
            'currency' => $e->currency,
        ])->values()->all();
    }
}
