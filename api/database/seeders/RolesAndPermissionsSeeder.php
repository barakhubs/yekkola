<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every role and permission from the enums and syncs role → permission grants. Idempotent.
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public const GUARD = 'web';

    public function __construct(private readonly PermissionRegistrar $registrar) {}

    public function run(): void
    {
        $this->registrar->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, self::GUARD);
        }

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, self::GUARD)
                ->syncPermissions(array_map(fn (Permission $p) => $p->value, $role->permissions()));
        }

        $this->registrar->forgetCachedPermissions();
    }
}
