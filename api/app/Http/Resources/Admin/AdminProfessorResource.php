<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Professors\Models\ProfessorProfile;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use App\Http\Resources\PublicProfessorResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A professor as staff see it in the back office.
 *
 * @mixin ProfessorProfile
 */
final class AdminProfessorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new PublicProfessorResource($this->resource))->toArray($request),
            'user_id' => $this->user_id,
            'phone' => $this->user->phone->masked(),
            'status' => $this->status->value,
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'suspension_reason' => $this->suspension_reason,
            'kyc_status' => $this->kyc_status->value,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'revenue_share_bps' => $this->revenue_share_bps,
            'payout_method' => $this->hasPayoutMethod() ? [
                'rail' => $this->payout_rail?->value,
                'phone' => PhoneNumber::fromString((string) $this->payout_msisdn)->masked(),
                'name' => $this->payout_name,
            ] : null,
            'payout_hold_until' => $this->payout_hold_until?->toIso8601String(),
            'is_payable' => $this->isPayable(),
        ];
    }
}
