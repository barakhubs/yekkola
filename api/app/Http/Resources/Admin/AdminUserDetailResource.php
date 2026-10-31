<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Domain\Commerce\Models\Order;
use App\Domain\Identity\Actions\ChangeUserStatus;
use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\UserStatus;
use App\Domain\Identity\Models\Device;
use App\Domain\Identity\Models\User;
use App\Domain\Learning\Models\Enrollment;
use App\Domain\Shared\Enums\Currency;
use App\Domain\Shared\ValueObjects\Money;
use App\Http\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * One account (PRD-09 FR-04): profile, devices, recent enrollments and orders, and which actions the signed-in
 * staff member may take on it (the API still checks each one).
 *
 * @mixin User
 */
final class AdminUserDetailResource extends JsonResource
{
    /**
     * @param  Collection<int, Device>  $devices
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  Collection<int, Order>  $orders
     */
    public function __construct(
        User $user,
        private readonly Collection $devices,
        private readonly Collection $enrollments,
        private readonly int $enrollmentCount,
        private readonly Collection $orders,
        private readonly ?string $professorProfileId,
    ) {
        parent::__construct($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $staff */
        $staff = $request->user();

        return [
            ...(new AdminUserResource($this->resource))->toArray($request),
            'email' => $this->email,
            'locale' => $this->locale,
            /** @var string|null */
            'province' => $this->province?->name,
            'city' => $this->city,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            /** @var string|null Their professor profile, if they teach. */
            'professor_profile_id' => $this->professorProfileId,
            'devices' => $this->deviceLines(),
            'enrollment_count' => $this->enrollmentCount,
            'enrollments' => $this->enrollmentLines(),
            'orders' => $this->orderLines(),
            'can' => $this->can($staff),
        ];
    }

    /**
     * @return array{suspend: bool, ban: bool, reactivate: bool, sign_out: bool, remove_device: bool, impersonate: bool}
     */
    private function can(User $staff): array
    {
        $manage = $staff->can(Permission::UsersManage->value)
            && $staff->id !== $this->id
            && (! ChangeUserStatus::isStaff($this->resource) || $staff->can(Permission::StaffManage->value));

        return [
            'suspend' => $manage && $this->status === UserStatus::Active,
            'ban' => $manage && $this->status !== UserStatus::Banned,
            'reactivate' => $manage && $this->status !== UserStatus::Active,
            'sign_out' => $manage,
            'remove_device' => $manage,
            'impersonate' => Impersonation::allowed($staff, $this->resource),
        ];
    }

    /**
     * @return list<array{id: string, platform: string, name: string|null, app_version: string|null, last_active_at: string|null, registered_at: string}>
     */
    private function deviceLines(): array
    {
        return $this->devices->map(fn (Device $d) => [
            'id' => $d->id,
            'platform' => $d->platform->value,
            'name' => $d->name,
            'app_version' => $d->app_version,
            'last_active_at' => $d->last_active_at?->toIso8601String(),
            'registered_at' => $d->registered_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return list<array{course_id: string, course: string|null, source: string, status: string, progress_pct: int, enrolled_at: string}>
     */
    private function enrollmentLines(): array
    {
        return $this->enrollments->map(fn (Enrollment $e) => [
            'course_id' => $e->course_id,
            'course' => $e->course?->liveVersion?->title,
            'source' => $e->source->value,
            'status' => $e->status->value,
            'progress_pct' => $e->progress_pct,
            'enrolled_at' => $e->enrolled_at->toIso8601String(),
        ])->values()->all();
    }

    /**
     * @return list<array{id: string, number: string, status: string, total: array{amount_minor: int, currency: string}, created_at: string}>
     */
    private function orderLines(): array
    {
        return $this->orders->map(fn (Order $o) => [
            'id' => $o->id,
            'number' => $o->number,
            'status' => $o->status->value,
            'total' => Money::of($o->total_minor, Currency::from($o->currency))->toArray(),
            'created_at' => $o->created_at->toIso8601String(),
        ])->values()->all();
    }
}
