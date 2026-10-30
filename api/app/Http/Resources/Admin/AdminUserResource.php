<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account in the back office list (PRD-09 FR-04). The phone number is masked.
 *
 * @mixin User
 */
final class AdminUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** @var string|null Null once the account is anonymised. */
            'phone' => $this->maskedPhone(),
            'status' => $this->status->value,
            'roles' => $this->roleNames(),
            'is_staff' => ChangeUserStatus::isStaff($this->resource),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'deletion_requested_at' => $this->deletion_requested_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return list<string>
     */
    private function roleNames(): array
    {
        return $this->getRoleNames()->map(fn ($name): string => (string) $name)->values()->all();
    }
}
