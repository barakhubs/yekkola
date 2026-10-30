<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Domain\Finance\Enums\PayoutBatchStatus;
use App\Domain\Finance\Enums\PayoutStatus;
use App\Domain\Finance\Models\Payout;
use App\Domain\Finance\Models\PayoutBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout batch with its totals (PRD-08 FR-07); its lines on the detail view.
 *
 * @mixin PayoutBatch
 */
final class PayoutBatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $lines = $this->payouts;
        $sent = $lines->reject(fn (Payout $p) => $p->status === PayoutStatus::Excluded);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'currency' => $this->currency,
            'total' => ['amount_minor' => (int) $sent->sum('amount_minor'), 'currency' => $this->currency],
            'paid_total' => ['amount_minor' => (int) $lines->where('status', PayoutStatus::Paid)->sum('amount_minor'), 'currency' => $this->currency],
            'counts' => $this->counts($lines),
            'built_by_schedule' => $this->created_by === null,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            /** What the back office may offer (the API still checks). */
            'can' => ['approve' => $this->status === PayoutBatchStatus::Draft, 'exclude' => $this->status === PayoutBatchStatus::Draft],
            'payouts' => PayoutResource::collection($this->whenLoaded('payouts')),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Payout>  $lines
     * @return array<string, int>
     */
    private function counts($lines): array
    {
        $counts = [];
        foreach (PayoutStatus::cases() as $status) {
            $counts[$status->value] = $lines->where('status', $status)->count();
        }

        return $counts;
    }
}
