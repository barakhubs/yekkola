<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A refund as the student sees it (PRD-05 FR-16). Staff see AdminRefundResource.
 *
 * @mixin Refund
 */
final class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'order_id' => $this->order_id,
            'order_number' => $this->order?->number,
            'course' => $this->item?->title_snapshot,
            'amount' => $this->amount()->toArray(),
            'status' => $this->status->value,
            'reason' => $this->reason,
            'decision_note' => $this->decision_note,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'failure_code' => $this->failure_code,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
