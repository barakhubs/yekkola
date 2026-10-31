<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Http\Support\Impersonation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own account. Never use this for other users (it includes the full phone number).
 *
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone_e164,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'locale' => $this->locale,
            'province' => $this->whenLoaded('province', fn () => $this->province ? new ProvinceResource($this->province) : null),
            'city' => $this->city,
            'status' => $this->status->value,
            'roles' => $this->getRoleNames()->values()->all(),
            // What the back office may show (it hides what the API would refuse; the API still checks).
            'is_staff' => $this->isStaff(),
            /** Staff: whether a back-office password is set, and whether they must choose a new one now. */
            'has_password' => $this->hasPassword(),
            'must_change_password' => (bool) $this->must_change_password,
            /** A super admin is viewing the platform as this account (banner + stop button). */
            'impersonation' => $this->impersonation($request),
            'permissions' => $this->permissionNames(),
            'deletion_requested_at' => $this->deletion_requested_at?->toIso8601String(),
            /** @var string|null When the account is erased unless the request is cancelled (FR-11). */
            'deletion_scheduled_for' => $this->deletion_requested_at
                ?->copy()->addDays((int) config('yekkola.auth.deletion_grace_days'))->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array{by_name: string|null, expires_at: string}|null
     */
    private function impersonation(Request $request): ?array
    {
        $current = Impersonation::current($request);
        if ($current === null || $request->user()?->getAuthIdentifier() !== $this->id) {
            return null;
        }

        return [
            'by_name' => $this->nameOf($current['by']),
            'expires_at' => CarbonImmutable::createFromTimestamp($current['expires_at'])->toIso8601String(),
        ];
    }

    private function nameOf(string $userId): ?string
    {
        $name = User::query()->whereKey($userId)->value('name');

        return is_string($name) ? $name : null;
    }

    private function hasPassword(): bool
    {
        return is_string($this->password) && $this->password !== '';
    }

    /**
     * @return list<string>
     */
    private function permissionNames(): array
    {
        return $this->getAllPermissions()->map(fn ($permission): string => (string) $permission->name)->sort()->values()->all();
    }

    /** Same rule as the `staff` middleware: any staff role. */
    private function isStaff(): bool
    {
        return $this->hasAnyRole(array_values(array_map(
            fn (Role $role): string => $role->value,
            array_filter(Role::cases(), fn (Role $role): bool => $role->isStaff()),
        )));
    }
}
