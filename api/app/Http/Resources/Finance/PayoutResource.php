<?php

declare(strict_types=1);

namespace App\Http\Resources\Finance;

use App\Domain\Finance\Models\Payout;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payout line (PRD-08 FR-07). The number is masked.
 *
 * @mixin Payout
 */
final class PayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'batch_id' => $this->payout_batch_id,
            'reference' => $this->reference,
            'professor' => [
                'id' => $this->professor_profile_id,
                'display_name' => $this->professor?->display_name,
            ],
            'amount' => $this->amount()->toArray(),
            'fee' => $this->fee(),
            'rail' => $this->rail->value,
            'msisdn' => PhoneNumber::fromString($this->msisdn)->masked(),
            'name' => $this->name,
            'status' => $this->status->value,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'excluded_reason' => $this->excluded_reason,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{amount_minor: int, currency: string}|null
     */
    private function fee(): ?array
    {
        return $this->fee_minor === null ? null : Money::of($this->fee_minor, Currency::from($this->currency))->toArray();
    }
}
