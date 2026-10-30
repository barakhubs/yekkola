<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member (PRD-09 FR-17): their staff role, the extra permissions added on top, and everything that gives.
 *
 * @mixin User
 */
final class StaffMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** @var string|null */
            'phone' => $this->maskedPhone(),
            'status' => $this->status->value,
            /** @var string|null moderator, admin or super-admin; null once staff access has ended. */
            'role' => $this->staffRole(),
            /** @var list<string> */
            'extra_permissions' => $this->extraPermissions(),
            /** @var list<string> */
            'permissions' => $this->allPermissions(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];
    }

    private function staffRole(): ?string
    {
        foreach ([Role::SuperAdmin, Role::Admin, Role::Moderator] as $role) {
            if ($this->hasRole($role->value)) {
                return $role->value;
            }
        }

        return null;
    }

    /**
     * Added on top of the role.
     *
     * @return list<string>
     */
    private function extraPermissions(): array
    {
        return $this->names($this->getDirectPermissions()->pluck('name')->all());
    }

    /**
     * Everything the role and the extras give.
     *
     * @return list<string>
     */
    private function allPermissions(): array
    {
        return $this->names($this->getAllPermissions()->pluck('name')->all());
    }

    /**
     * @param  array<int, mixed>  $names
     * @return list<string>
     */
    private function names(array $names): array
    {
        $names = array_map(fn ($n) => (string) $n, $names);
        sort($names);

        return $names;
    }
}
