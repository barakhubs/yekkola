<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * One line of a professor's statement (PRD-08 FR-09): positive amounts are owed to the professor.
 *
 * @property stdClass $resource
 */
final class StatementLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->id,
            'date' => Carbon::parse((string) $this->resource->created_at)->toIso8601String(),
            /** sale, sale_reversal, release, refund, refund_reversal, payout, payout_failed, adjustment */
            'type' => (string) $this->resource->type,
            /** professor_held or professor_available */
            'account' => (string) $this->resource->account,
            'amount' => $this->amount(),
            /** Order number, payout reference, or the adjustment's label. */
            'memo' => $this->resource->memo === null ? null : (string) $this->resource->memo,
            'reference_type' => (string) $this->resource->reference_type,
            'reference_id' => (string) $this->resource->reference_id,
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string}
     */
    private function amount(): array
    {
        $minor = (int) $this->resource->amount_minor;

        return [
            'amount_minor' => $this->resource->direction === 'credit' ? $minor : -$minor,
            'currency' => (string) $this->resource->currency,
        ];
    }
}
