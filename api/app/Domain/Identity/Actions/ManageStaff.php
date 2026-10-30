<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Exceptions\IdentityException;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Actions\RecordAudit;
use App\Domain\Shared\ValueObjects\PhoneNumber;
use Illuminate\Database\ConnectionInterface;

/**
 * Staff management (PRD-09 FR-17): give an account a staff role (creating the account from its phone number if
 * needed — they sign in with an SMS code like anyone), set its role and extra permissions (combinable sets such as
 * finance), or end its staff access. Nobody changes their own access; anything touching a super admin needs
 * `staff.roles`. Every change is audited with before/after.
 */
final class ManageStaff
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly FindOrRegisterStudent $accounts,
        private readonly SignOutEverywhere $signOut,
        private readonly RecordAudit $recordAudit,
    ) {}

    /**
     * @param  list<Permission>  $permissions  Extra permissions on top of the role's.
     */
    public function invite(PhoneNumber $phone, Role $role, array $permissions, User $by, string $locale): User
    {
        $user = $this->accounts->handle($phone, $locale);

        return $this->update($user, $role, $permissions, $by);
    }

    /**
     * @param  list<Permission>  $permissions
     */
    public function update(User $user, Role $role, array $permissions, User $by): User
    {
        if (! $role->isStaff()) {
            throw IdentityException::notAStaffRole();
        }
        $this->assertMayChange($user, $by, $role);

        return $this->db->transaction(function () use ($user, $role, $permissions, $by): User {
            $before = $this->access($user);
            $user->syncRoles(array_values(array_unique([...$this->nonStaffRoles($user), $role->value])));
            $user->syncPermissions(array_map(fn (Permission $p) => $p->value, $permissions));
            $after = $this->access($user->refresh());

            if ($before !== $after) {
                $this->recordAudit->handle('staff.updated', $user, ['before' => $before, 'after' => $after], causer: $by);
            }

            return $user;
        });
    }

    /** Ends staff access: staff roles and extra permissions go, and every session is signed out. */
    public function remove(User $user, User $by): User
    {
        $this->assertMayChange($user, $by, null);

        return $this->db->transaction(function () use ($user, $by): User {
            $before = $this->access($user);
            $user->syncRoles($this->nonStaffRoles($user));
            $user->syncPermissions([]);
            $this->signOut->handle($user);
            $this->recordAudit->handle('staff.removed', $user, ['before' => $before], causer: $by);

            return $user->refresh();
        });
    }

    private function assertMayChange(User $user, User $by, ?Role $newRole): void
    {
        if ($user->id === $by->id) {
            throw IdentityException::ownAccountStatus();
        }
        $touchesSuperAdmin = $newRole === Role::SuperAdmin || $user->hasRole(Role::SuperAdmin->value);
        if ($touchesSuperAdmin && ! $by->can(Permission::StaffRoles->value)) {
            throw IdentityException::staffAccountProtected();
        }
    }

    /**
     * @return list<string>
     */
    private function nonStaffRoles(User $user): array
    {
        return $user->getRoleNames()
            ->filter(fn ($name) => ! (Role::tryFrom((string) $name)?->isStaff() ?? false))
            ->map(fn ($name) => (string) $name)->values()->all();
    }

    /**
     * @return array{roles: list<string>, permissions: list<string>}
     */
    private function access(User $user): array
    {
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return [
            'roles' => $user->getRoleNames()->map(fn ($n) => (string) $n)->sort()->values()->all(),
            'permissions' => $user->getDirectPermissions()->map(fn ($p) => (string) $p->name)->sort()->values()->all(),
        ];
    }
}
