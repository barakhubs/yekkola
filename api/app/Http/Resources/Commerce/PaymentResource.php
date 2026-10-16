<?php

declare(strict_types=1);

namespace App\Http\Resources\Commerce;

use App\Domain\Commerce\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment attempt for the waiting screen (PRD-05 FR-06): status, operator, masked payer number.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'rail' => $this->rail->value,
            'payer' => $this->payer()->masked(),
            'amount' => $this->amount()->toArray(),
            'status' => $this->status->value,
            'failure_code' => $this->failure_code,
            'failure_reason' => $this->failure_code === null ? null : (trans()->has('payments.failure.'.$this->failure_code) ? __('payments.failure.'.$this->failure_code) : __('payments.failure.failed')),
            'operator_reference' => $this->operator_reference,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
