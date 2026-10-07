<?php

declare(strict_types=1);

use App\Domain\Identity\Enums\Permission;
use App\Domain\Identity\Enums\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Platform\Models\Province;
use Database\Seeders\ProvinceSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RoleModel;

it('seeds the 26 DRC provinces once, listed alphabetically', function () {
    $this->seed(ProvinceSeeder::class);
    $this->seed(ProvinceSeeder::class); // idempotent

    $names = Province::query()->alphabetical()->pluck('name')->all();

    expect(Province::query()->count())->toBe(26)
        ->and(array_slice($names, 0, 3))->toBe(['Bas-Uele', 'Équateur', 'Haut-Katanga'])
        ->and(Province::query()->where('code', 'CD-NK')->value('name'))->toBe('Nord-Kivu');
});

it('lets a user optionally belong to a province', function () {
    $this->seed(ProvinceSeeder::class);
    $province = Province::query()->where('code', 'CD-HK')->sole();

    $user = User::factory()->create(['province_id' => $province->id]);

    expect($user->province->name)->toBe('Haut-Katanga')
        ->and(User::factory()->create()->province_id)->toBeNull();
});

it('creates every role and permission from the enums', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(RolesAndPermissionsSeeder::class); // idempotent

    expect(RoleModel::query()->pluck('name')->sort()->values()->all())
        ->toBe(collect(Role::cases())->map->value->sort()->values()->all());
});

it('grants staff permissions by role, keeping finance separate', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $moderator = User::factory()->create()->assignRole(Role::Moderator->value);
    $admin = User::factory()->create()->assignRole(Role::Admin->value);
    $super = User::factory()->create()->assignRole(Role::SuperAdmin->value);

    expect($moderator->can(Permission::ModerationReview->value))->toBeTrue()
        ->and($moderator->can(Permission::SettingsManage->value))->toBeFalse()
        ->and($admin->can(Permission::SettingsManage->value))->toBeTrue()
        ->and($admin->can(Permission::StaffManage->value))->toBeTrue()
        ->and($admin->can(Permission::KycView->value))->toBeTrue()
        ->and($admin->can(Permission::StaffRoles->value))->toBeFalse()
        ->and($admin->can(Permission::FinancePayouts->value))->toBeFalse()
        ->and($super->can(Permission::FinancePayouts->value))->toBeTrue();

    // Finance is granted individually on top of a role.
    $admin->givePermissionTo(Permission::FinanceRefunds->value);
    expect($admin->fresh()->can(Permission::FinanceRefunds->value))->toBeTrue();
});
